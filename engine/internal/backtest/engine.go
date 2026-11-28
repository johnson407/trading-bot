package backtest

import (
	"context"
	"errors"
	"fmt"
	"math"
	"strings"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

// Defaults mirror config/backtest.php.
const (
	DefaultProgressInterval = 500
	DefaultMaxFills         = 25000
	DefaultEquityPoints     = 1000
)

// ErrNoHistoricalData mirrors the PHP no-candle error.
var ErrNoHistoricalData = errors.New("No historical data available.")

// Engine mirrors App\Domain\Backtest\Engine\BacktestEngine.
type Engine struct {
	progressInterval int
	maxFills         int
	equityPoints     int
}

// Option customises an Engine.
type Option func(*Engine)

// WithProgressInterval overrides config('backtest.progress_interval').
func WithProgressInterval(interval int) Option {
	return func(engine *Engine) {
		engine.progressInterval = interval
	}
}

// WithMaxFills overrides config('backtest.max_fills').
func WithMaxFills(maxFills int) Option {
	return func(engine *Engine) {
		engine.maxFills = maxFills
	}
}

// WithEquityPoints overrides config('backtest.equity_points').
func WithEquityPoints(points int) Option {
	return func(engine *Engine) {
		engine.equityPoints = points
	}
}

// NewEngine creates a deterministic replay engine.
func NewEngine(options ...Option) *Engine {
	engine := &Engine{
		progressInterval: DefaultProgressInterval,
		maxFills:         DefaultMaxFills,
		equityPoints:     DefaultEquityPoints,
	}
	for _, option := range options {
		option(engine)
	}
	if engine.progressInterval < 1 {
		engine.progressInterval = 1
	}
	if engine.maxFills < 1 {
		engine.maxFills = 1
	}

	return engine
}

// NewStrategy returns the built-in strategy for a persisted key.
func NewStrategy(key string) (strategy.Strategy, error) {
	switch key {
	case "simple-grid":
		return strategy.NewSimpleGrid(), nil
	case "dynamic-grid":
		return strategy.NewDynamicGrid(), nil
	case "martingale-grid":
		return strategy.NewMartingaleGrid(), nil
	case "trailing-grid":
		return strategy.NewTrailingGrid(), nil
	default:
		return nil, fmt.Errorf(
			"Unknown strategy [%s]. Known strategies: simple-grid, trailing-grid, martingale-grid, dynamic-grid.",
			key,
		)
	}
}

// Run executes a configuration against a contiguous candle sequence.
func (e *Engine) Run(configuration Configuration, candles []Candle, onProgress func(int)) (Result, error) {
	return e.RunContext(context.Background(), configuration, candles, onProgress)
}

// RunContext executes a replay and stops with the context error when the
// deadline expires. The result is discarded because a partial replay cannot be
// persisted as a completed run.
func (e *Engine) RunContext(ctx context.Context, configuration Configuration, candles []Candle, onProgress func(int)) (Result, error) {
	started := time.Now()
	if err := ctx.Err(); err != nil {
		return Result{}, err
	}
	replayStrategy, err := NewStrategy(configuration.StrategyKey)
	if err != nil {
		return Result{}, err
	}

	quoteAsset := quoteAsset(configuration.Symbol)
	portfolio := NewInMemoryPortfolio(configuration.CapitalAllocation)
	book := NewSimulatedOrderBook(quoteAsset)
	riskGuard := NewBacktestRiskGuard(configuration.RiskLimits, configuration.FeeRate)
	strategyConfiguration := strategy.Config{
		Key:    configuration.StrategyKey,
		Values: configuration.StrategyConfiguration,
	}

	state := strategy.NewState()
	recentPrices := make([]decimal.Decimal, 0, 30)
	var activeLevels []strategy.GridLevelPlan
	activeSet := false
	equity := make([]EquityPoint, 0, len(candles))
	fills := make([]Fill, 0)
	processed := 0
	lastPrice := decimal.Zero()
	endReason := "range_end"
	dayKey := ""
	dayStartRealized := decimal.Zero()

	for _, candle := range candles {
		if err := ctx.Err(); err != nil {
			return Result{}, err
		}

		index := processed
		closePrice := candle.Close
		lastPrice = closePrice
		at, closeErr := candle.CloseTime(configuration.Timeframe)
		if closeErr != nil {
			return Result{}, closeErr
		}

		recentPrices = append(recentPrices, closePrice)
		if len(recentPrices) > 30 {
			recentPrices = recentPrices[len(recentPrices)-30:]
		}

		day := at.Format("2006-01-02")
		if dayKey == "" || day != dayKey {
			dayKey = day
			dayStartRealized = portfolio.Realized
		}

		market := strategy.MarketSnapshot{
			Symbol:       configuration.Symbol,
			Price:        closePrice,
			Bid:          decimalPointer(closePrice),
			Ask:          decimalPointer(closePrice),
			RecentPrices: append([]decimal.Decimal(nil), recentPrices...),
			Timestamp:    timePointer(at),
		}

		if signal := configuration.ExitRules.Evaluate(
			closePrice.String(),
			portfolio.AverageEntry.String(),
			portfolio.Base.String(),
		); signal != nil {
			state, err = e.cancelAll(replayStrategy, configuration, strategyConfiguration, state, portfolio, book, market)
			if err != nil {
				return Result{}, err
			}
			equity = append(equity, EquityPoint{At: at, Equity: portfolio.Equity(closePrice)})
			processed++
			endReason = string(signal.Rule)
			break
		}

		if dailyLossBreached(riskGuard, portfolio, closePrice, dayStartRealized) {
			state, err = e.cancelAll(replayStrategy, configuration, strategyConfiguration, state, portfolio, book, market)
			if err != nil {
				return Result{}, err
			}
			equity = append(equity, EquityPoint{At: at, Equity: portfolio.Equity(closePrice)})
			processed++
			endReason = "risk_pause"
			break
		}

		input := e.inputFor(configuration, strategyConfiguration, portfolio, book, state, market)

		decision, err := replayStrategy.OnTick(input)
		if err != nil {
			return Result{}, err
		}

		state = decision.State
		previousLevels := activeLevels
		previousSet := activeSet
		var canceled []*SimulatedOrder
		activeLevels, activeSet, canceled = e.syncGrid(activeLevels, activeSet, decision.Levels, book)
		pendingCancels := cancelEventsFor(canceled)

		if previousSet && activeSet && !levelsMatch(previousLevels, activeLevels) {
			// A replacement cancels every previous order. Re-evaluate once with
			// the new order book so retained levels get orders again.
			firstPassOrders := decision.Orders
			replacement, err := replayStrategy.OnTick(
				e.inputFor(configuration, strategyConfiguration, portfolio, book, state, market),
			)
			if err != nil {
				return Result{}, err
			}
			replacement.Orders = mergeReplacementOrders(firstPassOrders, replacement.Orders)
			decision = replacement
			state = decision.State
			var canceledAgain []*SimulatedOrder
			activeLevels, activeSet, canceledAgain = e.syncGrid(activeLevels, activeSet, decision.Levels, book)
			pendingCancels = append(pendingCancels, cancelEventsFor(canceledAgain)...)
		}

		for _, clientOrderID := range decision.CancelClientOrderIDs {
			canceledOrder := book.Cancel(clientOrderID)
			if canceledOrder != nil {
				pendingCancels = append(pendingCancels, strategy.CancelEvent{
					ClientOrderID:     canceledOrder.ClientOrderID,
					GridLevelSequence: canceledOrder.GridLevelSequence,
					Reason:            "strategy",
				})
			}
		}

		for _, event := range pendingCancels {
			cancelDecision, cancelErr := replayStrategy.OnCancel(
				e.inputFor(configuration, strategyConfiguration, portfolio, book, state, market),
				event,
			)
			if cancelErr != nil {
				return Result{}, cancelErr
			}
			state = cancelDecision.State
		}

		if activeSet {
			e.placeIntents(decision.Orders, book, riskGuard, portfolio, index, closePrice)
		}

		for _, order := range book.QualifyingOrders(closePrice, index) {
			if !order.IsOpen() {
				continue
			}

			if len(fills) >= e.maxFills {
				return Result{}, fmt.Errorf(
					"Backtest produced more than %d fills; choose a shorter range or a larger timeframe.",
					e.maxFills,
				)
			}

			fill := book.Fill(order, at, closePrice, configuration.FeeRate)
			if applyErr := portfolio.Apply(fill); applyErr != nil {
				return Result{}, applyErr
			}
			fills = append(fills, fill)

			fillDecision, fillErr := replayStrategy.OnFill(
				e.inputFor(configuration, strategyConfiguration, portfolio, book, state, market),
				strategy.FillEvent{
					ExchangeFillID:    "backtest-" + itoa(fill.Sequence),
					Side:              fill.Side,
					Price:             fill.Price,
					OrderPrice:        order.Price,
					Quantity:          fill.Quantity,
					Fee:               fill.Fee,
					ClientOrderID:     stringPointer(fill.ClientOrderID),
					GridLevelSequence: fill.GridLevelSequence,
				},
			)
			if fillErr != nil {
				return Result{}, fillErr
			}

			state = fillDecision.State
			// Live computes the opposite intent from the grid that was active
			// when the order filled, before any grid replacement.
			opposite := oppositeIntent(activeLevels, fill)
			var canceledByFill []*SimulatedOrder
			activeLevels, activeSet, canceledByFill = e.syncGrid(activeLevels, activeSet, fillDecision.Levels, book)

			for _, event := range cancelEventsFor(canceledByFill) {
				cancelDecision, cancelErr := replayStrategy.OnCancel(
					e.inputFor(configuration, strategyConfiguration, portfolio, book, state, market),
					event,
				)
				if cancelErr != nil {
					return Result{}, cancelErr
				}
				state = cancelDecision.State
			}

			if activeSet {
				intents := make([]strategy.OrderIntent, 0, len(fillDecision.Orders)+1)
				intents = append(intents, fillDecision.Orders...)
				if opposite != nil {
					intents = append(intents, *opposite)
				}
				e.placeIntents(intents, book, riskGuard, portfolio, index, closePrice)
			}
		}

		if dailyLossBreached(riskGuard, portfolio, closePrice, dayStartRealized) {
			state, err = e.cancelAll(replayStrategy, configuration, strategyConfiguration, state, portfolio, book, market)
			if err != nil {
				return Result{}, err
			}
			equity = append(equity, EquityPoint{At: at, Equity: portfolio.Equity(closePrice)})
			processed++
			endReason = "risk_pause"
			break
		}

		shouldStopInput := e.inputFor(configuration, strategyConfiguration, portfolio, book, state, market)
		if decision.Stop || replayStrategy.ShouldStop(shouldStopInput) {
			state, err = e.cancelAll(replayStrategy, configuration, strategyConfiguration, state, portfolio, book, market)
			if err != nil {
				return Result{}, err
			}
			equity = append(equity, EquityPoint{At: at, Equity: portfolio.Equity(closePrice)})
			processed++
			endReason = "strategy_stop"
			break
		}

		equity = append(equity, EquityPoint{At: at, Equity: portfolio.Equity(closePrice)})
		processed++

		if onProgress != nil && processed%e.progressInterval == 0 {
			onProgress(processed)
		}
	}

	if processed == 0 {
		return Result{}, ErrNoHistoricalData
	}

	durationMs := int(math.Round(float64(time.Since(started).Nanoseconds()) / 1e6))
	metrics := CalculateMetrics(
		portfolio,
		equity,
		fills,
		configuration.CapitalAllocation,
		lastPrice,
		processed,
		endReason,
		durationMs,
	)

	return Result{
		Metrics:     metrics,
		Fills:       fills,
		EquityCurve: Downsample(equity, e.equityPoints),
		FinalState:  state,
	}, nil
}

func (e *Engine) syncGrid(
	active []strategy.GridLevelPlan,
	activeSet bool,
	levels []strategy.GridLevelPlan,
	book *SimulatedOrderBook,
) ([]strategy.GridLevelPlan, bool, []*SimulatedOrder) {
	if len(levels) == 0 {
		return active, activeSet, nil
	}

	if activeSet && levelsMatch(active, levels) {
		return active, true, nil
	}

	var canceled []*SimulatedOrder
	if activeSet {
		canceled = book.CancelAll()
	}

	return append([]strategy.GridLevelPlan(nil), levels...), true, canceled
}

// mergeReplacementOrders keeps the first pass's orders when the replacement
// evaluation returns none, so a cancelled grid cannot leave the ladder empty.
func mergeReplacementOrders(first, second []strategy.OrderIntent) []strategy.OrderIntent {
	if len(second) == 0 {
		return first
	}

	return second
}

func levelsMatch(active, levels []strategy.GridLevelPlan) bool {
	if len(active) != len(levels) {
		return false
	}

	for index, level := range levels {
		current := active[index]
		if current.Sequence != level.Sequence || !current.Price.Equals(level.Price) || current.Side != level.Side {
			return false
		}
	}

	return true
}

func cancelEventsFor(orders []*SimulatedOrder) []strategy.CancelEvent {
	events := make([]strategy.CancelEvent, 0, len(orders))
	for _, order := range orders {
		events = append(events, strategy.CancelEvent{
			ClientOrderID:     order.ClientOrderID,
			GridLevelSequence: order.GridLevelSequence,
			Reason:            "grid_replaced",
		})
	}

	return events
}

func (e *Engine) placeIntents(
	intents []strategy.OrderIntent,
	book *SimulatedOrderBook,
	riskGuard *BacktestRiskGuard,
	portfolio *InMemoryPortfolio,
	candleIndex int,
	market decimal.Decimal,
) {
	for _, intent := range strategy.SortOrderIntents(intents, market) {
		if book.HasOpenAtPrice(intent.Price) {
			continue
		}

		if intent.GridLevelSequence != nil && book.OpenForSequence(*intent.GridLevelSequence) != nil {
			continue
		}

		allowed, _ := riskGuard.CheckOrder(intent.Side, intent.Price, intent.Quantity, portfolio, book)
		if !allowed {
			continue
		}

		book.Place(intent.Side, intent.Price, intent.Quantity, intent.GridLevelSequence, candleIndex)
	}
}

func (e *Engine) cancelAll(
	replayStrategy strategy.Strategy,
	configuration Configuration,
	strategyConfiguration strategy.Config,
	state strategy.State,
	portfolio *InMemoryPortfolio,
	book *SimulatedOrderBook,
	market strategy.MarketSnapshot,
) (strategy.State, error) {
	for _, event := range cancelEventsFor(book.CancelAll()) {
		decision, err := replayStrategy.OnCancel(
			e.inputFor(configuration, strategyConfiguration, portfolio, book, state, market),
			event,
		)
		if err != nil {
			return state, err
		}
		state = decision.State
	}

	return state, nil
}

func (e *Engine) inputFor(
	configuration Configuration,
	strategyConfiguration strategy.Config,
	portfolio *InMemoryPortfolio,
	book *SimulatedOrderBook,
	state strategy.State,
	market strategy.MarketSnapshot,
) strategy.Input {
	openOrders := make([]strategy.OpenOrderSnapshot, 0, len(book.orders))
	for _, order := range book.OpenOrders() {
		openOrders = append(openOrders, strategy.OpenOrderSnapshot{
			ClientOrderID:     order.ClientOrderID,
			Side:              order.Side,
			Price:             order.Price,
			Quantity:          order.Quantity,
			GridLevelSequence: order.GridLevelSequence,
		})
	}

	return strategy.Input{
		Market: market,
		Bot: strategy.BotSnapshot{
			BotID:             configuration.BotID,
			BaseInventory:     portfolio.Base,
			QuoteInventory:    portfolio.Quote,
			AverageEntryPrice: portfolio.AverageEntry,
			RealizedPnL:       portfolio.Realized,
			CapitalAllocation: configuration.CapitalAllocation,
			OpenOrders:        openOrders,
		},
		Config: strategyConfiguration,
		State:  state,
	}
}

func oppositeIntent(levels []strategy.GridLevelPlan, fill Fill) *strategy.OrderIntent {
	if fill.GridLevelSequence == nil {
		return nil
	}

	target := *fill.GridLevelSequence + 1
	side := strategy.SideSell
	if fill.Side == strategy.SideSell {
		target = *fill.GridLevelSequence - 1
		side = strategy.SideBuy
	}

	for _, level := range levels {
		if level.Sequence == target {
			sequence := level.Sequence
			return &strategy.OrderIntent{
				Side:              side,
				Price:             level.Price,
				Quantity:          fill.Quantity,
				GridLevelSequence: &sequence,
				Reason:            "fill.opposite",
			}
		}
	}

	return nil
}

func dailyLossBreached(
	riskGuard *BacktestRiskGuard,
	portfolio *InMemoryPortfolio,
	mark decimal.Decimal,
	dayStartRealized decimal.Decimal,
) bool {
	return riskGuard.DailyLoss(portfolio.Realized, portfolio.Unrealized(mark), dayStartRealized).
		GreaterThanOrEqual(riskGuard.DailyLossLimit())
}

func quoteAsset(symbol string) string {
	parts := strings.Split(symbol, "/")
	if len(parts) < 2 {
		return "USDT"
	}

	return parts[1]
}

func decimalPointer(value decimal.Decimal) *decimal.Decimal {
	return &value
}

func timePointer(value time.Time) *time.Time {
	return &value
}

func stringPointer(value string) *string {
	return &value
}
