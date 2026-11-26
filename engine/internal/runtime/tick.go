package runtime

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"bytes"
	"github.com/jackc/pgx/v5"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/grid"
	"github.com/johnson407/trading-bot/engine/internal/paperfill"
	"github.com/johnson407/trading-bot/engine/internal/risk"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

// TickBot evaluates one bot once. It serializes with control commands for the
// same bot, so a stop or kill switch cannot interleave with order placement.
func (r *Runtime) TickBot(ctx context.Context, botID int64) error {
	return withBotLock(ctx, botID, func(ctx context.Context) error {
		return r.tickBot(ctx, botID)
	})
}

type tickStatsKey struct{}

type tickStats struct {
	botID         int64
	correlationID string
	startedAt     time.Time
	outcome       string
	failureType   string

	ordersPlaced   int
	ordersCanceled int
	paperFills     int
	liveFills      int
	gridsCreated   int
	gridsMatched   int
	gridsReplaced  int
	levelsCreated  int
	levelsMatched  int
	levelsReplaced int
}

func newTickStats(botID int64, start time.Time) *tickStats {
	return &tickStats{
		botID:         botID,
		correlationID: fmt.Sprintf("tick-%d-%d", botID, start.UnixNano()),
		startedAt:     start,
	}
}

func withTickStats(ctx context.Context, stats *tickStats) context.Context {
	return context.WithValue(ctx, tickStatsKey{}, stats)
}

func tickStatsFrom(ctx context.Context) *tickStats {
	stats, _ := ctx.Value(tickStatsKey{}).(*tickStats)

	return stats
}

func tickCorrelation(ctx context.Context) string {
	if stats := tickStatsFrom(ctx); stats != nil {
		return stats.correlationID
	}

	return ""
}

func withTickCorrelation(ctx context.Context, fields map[string]any) map[string]any {
	correlationID := tickCorrelation(ctx)
	if correlationID == "" {
		return fields
	}
	if fields == nil {
		fields = make(map[string]any, 1)
	}
	if _, exists := fields["correlation_id"]; !exists {
		fields["correlation_id"] = correlationID
	}

	return fields
}

func (s *tickStats) markPaused() {
	if s == nil || s.outcome == "failed" || s.outcome == "panicked" {
		return
	}
	s.outcome = "paused"
}

func (s *tickStats) markFailed(cause error) {
	if s == nil || s.outcome == "panicked" {
		return
	}
	s.outcome = "failed"
	if cause != nil {
		s.failureType = fmt.Sprintf("%T", cause)
	}
}

func (s *tickStats) addGridCreated(levels int) {
	if s == nil {
		return
	}
	s.gridsCreated++
	s.levelsCreated += levels
}

func (s *tickStats) addGridMatched(levels int) {
	if s == nil {
		return
	}
	s.gridsMatched++
	s.levelsMatched += levels
}

func (s *tickStats) addGridReplaced(levels int) {
	if s == nil {
		return
	}
	s.gridsReplaced++
	s.levelsReplaced += levels
}

func (s *tickStats) log(r *Runtime) {
	if s == nil {
		return
	}
	if s.outcome == "" {
		s.outcome = "completed"
	}
	args := []any{
		"bot_id", s.botID,
		"correlation_id", s.correlationID,
		"outcome", s.outcome,
		"duration_ms", r.now().Sub(s.startedAt).Milliseconds(),
		"orders_placed", s.ordersPlaced,
		"orders_canceled", s.ordersCanceled,
		"paper_fills", s.paperFills,
		"live_fills", s.liveFills,
		"grids_created", s.gridsCreated,
		"grids_matched", s.gridsMatched,
		"grids_replaced", s.gridsReplaced,
		"levels_created", s.levelsCreated,
		"levels_matched", s.levelsMatched,
		"levels_replaced", s.levelsReplaced,
	}
	if s.failureType != "" {
		args = append(args, "failure_type", s.failureType)
	}
	r.logger.Info("bot tick completed", args...)
}

func (s *tickStats) finish(r *Runtime, recovered any, retErr error) {
	if recovered != nil {
		s.outcome = "panicked"
		s.log(r)
		panic(recovered)
	}
	if s.outcome == "" {
		if retErr != nil {
			s.markFailed(retErr)
		} else {
			s.outcome = "completed"
		}
	}
	s.log(r)
}

func (r *Runtime) tickBot(ctx context.Context, botID int64) (retErr error) {
	bot, err := r.loadBot(ctx, botID)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		return err
	}
	if bot.Status != "running" {
		return nil
	}

	tickStart := r.now()
	tag, err := r.pool.Exec(ctx,
		`UPDATE bots SET last_tick_at = $2, updated_at = now() WHERE id = $1 AND deleted_at IS NULL`,
		bot.ID, tickStart,
	)
	if err != nil {
		return err
	}
	if tag.RowsAffected() == 0 {
		return nil
	}

	stats := newTickStats(bot.ID, tickStart)
	ctx = withTickStats(ctx, stats)
	defer func() { stats.finish(r, recover(), retErr) }()

	if bot.live() && !r.liveTrading {
		r.pause(ctx, bot, liveDisabledMessage, "bot.live_trading_disabled", "risk.live_trading_disabled", "critical")
		return nil
	}

	// Apply exchange fills before the exit guard and daily-loss checks so a fill
	// that happened since the last tick informs those gates immediately.
	// Reconcile only reads/cancels; it never places an order.
	if bot.live() {
		if err := r.syncLiveOrdersLocked(ctx, bot, false); err != nil {
			return r.tickFailure(ctx, bot, err)
		}
	}

	if r.market == nil {
		return r.tickFailure(ctx, bot, fmt.Errorf("no public market price source is configured"))
	}

	price, err := r.market.Last(ctx, bot.Exchange, bot.Symbol)
	if err != nil {
		return r.tickFailure(ctx, bot, err)
	}
	if !price.IsPositive() {
		return r.tickFailure(ctx, bot, fmt.Errorf("public market price was not positive"))
	}

	bot = r.rememberPrice(ctx, bot, price)
	if bot == nil {
		return nil
	}

	rules := risk.NewExitRules(bot.StopLossPercent, bot.TakeProfitPercent, bot.RangeLow, bot.RangeHigh)
	if signal := rules.Evaluate(price.String(), bot.AverageEntry.String(), bot.Base.String()); signal != nil {
		message := fmt.Sprintf("%s triggered at %s (threshold %s). Bot paused and open orders canceled.", signal.Label(), signal.MarkPrice, signal.Threshold)
		r.pause(ctx, bot, message, "bot.exit_guard_triggered", signal.RiskEventType(), "warning")
		return nil
	}

	limit, loss, account, err := r.dailyLoss(ctx, bot, price)
	if err != nil {
		return r.tickFailure(ctx, bot, err)
	}
	if loss.GreaterThanOrEqual(limit) {
		message := fmt.Sprintf("Daily loss limit reached (%s). Bot paused and open orders canceled.", loss.String())
		if account {
			message = fmt.Sprintf("Account daily loss limit reached (%s). Bot paused and open orders canceled.", loss.String())
		}
		r.pause(ctx, bot, message, "bot.daily_loss_paused", "risk.daily_loss", "warning")
		if account {
			r.pauseOtherBots(ctx, bot.UserID, bot.ID, message)
		}
		return nil
	}

	state, config, err := r.loadStrategy(ctx, bot)
	if err != nil {
		return r.tickFailure(ctx, bot, err)
	}

	impl, err := strategy.NewStrategy(bot.StrategyKey)
	if err != nil {
		return r.tickFailure(ctx, bot, err)
	}

	market := strategy.MarketSnapshot{
		Symbol: bot.Symbol, Price: price, Bid: &price, Ask: &price,
		RecentPrices: recentPrices(bot.Settings), Timestamp: &tickStart,
	}

	// Reconciliation applies live inventory; OnFill applies the same fill to
	// strategy state (disarmed levels, martingale sizing). Without this the
	// strategy would re-emit a level that just filled.
	var liveApplied []int64
	var liveStop bool
	if bot.live() {
		state, liveApplied, liveStop, err = r.applyLiveFills(ctx, bot, impl, config, market, state)
		if err != nil {
			return r.tickFailure(ctx, bot, err)
		}
	}

	open, err := r.openOrders(ctx, bot.ID)
	if err != nil {
		return err
	}
	input := strategy.Input{Market: market, Bot: bot.snapshot(open), Config: config, State: state}

	decision, err := r.decide(ctx, impl, input)
	if err != nil {
		return r.tickFailure(ctx, bot, err)
	}
	decision.Stop = decision.Stop || liveStop

	active, err := r.activeGrid(ctx, bot.ID)
	if err != nil {
		return err
	}
	gridAfter, replaced, err := r.syncGrid(ctx, bot, decision.Levels, active, stats)
	if err != nil {
		return err
	}

	if replaced {
		refreshed, err := r.openOrders(ctx, bot.ID)
		if err != nil {
			return err
		}
		input = input.WithBot(bot.snapshot(refreshed)).WithState(decision.State)
		second, err := impl.OnTick(input)
		if err != nil {
			return r.tickFailure(ctx, bot, err)
		}
		decision.State = second.State
		if len(second.Orders) > 0 {
			decision.Orders = second.Orders
		}
		gridAfter, _, err = r.syncGrid(ctx, bot, second.Levels, gridAfter, stats)
		if err != nil {
			return err
		}
	}

	if err := r.applyCancels(ctx, bot, impl, &decision.State, decision.CancelClientOrderIDs, stats); err != nil {
		return err
	}

	if current, err := r.loadBot(ctx, bot.ID); err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		return err
	} else if current.Status != "running" || current.DesiredStatus != "running" {
		return nil
	}

	if gridAfter != nil {
		placed, err := r.placeOrders(ctx, bot, gridAfter, decision.Orders)
		if err != nil {
			return r.tickFailure(ctx, bot, err)
		}
		stats.ordersPlaced += placed
	}

	var fills []appliedFill
	if bot.live() {
		// Live fills are applied by syncLiveOrders before the strategy decision.
		fills = nil
	} else {
		fills, err = r.processPaperFills(ctx, bot, gridAfter, tickStart)
		if err != nil {
			return r.tickFailure(ctx, bot, err)
		}
		stats.paperFills += len(fills)
	}

	for _, fill := range fills {
		refreshed, err := r.loadBot(ctx, bot.ID)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return nil
			}
			return err
		}
		orders, err := r.openOrders(ctx, bot.ID)
		if err != nil {
			return err
		}
		fillDecision, err := impl.OnFill(input.WithBot(refreshed.snapshot(orders)).WithState(decision.State), strategy.FillEvent{
			ExchangeFillID: fill.ExchangeFillID, Side: strategy.Side(fill.Side), Price: fill.Price,
			Quantity: fill.Quantity, Fee: fill.Fee, ClientOrderID: &fill.ClientOrderID, GridLevelSequence: fill.Sequence,
		})
		if err != nil {
			return r.tickFailure(ctx, refreshed, err)
		}
		decision.State = fillDecision.State
		if gridState, _, err := r.syncGrid(ctx, refreshed, fillDecision.Levels, gridAfter, stats); err != nil {
			return err
		} else if gridState != nil {
			gridAfter = gridState
			placed, err := r.placeOrders(ctx, refreshed, gridState, fillDecision.Orders)
			if err != nil {
				return err
			}
			stats.ordersPlaced += placed
		}
	}

	if decision.Stop || impl.ShouldStop(input.WithState(decision.State)) {
		r.pause(ctx, bot, "The strategy requested that the bot stop.", "bot.strategy_stop", "", "warning")
		return nil
	}

	if err := r.persistStrategy(ctx, bot.ID, decision.State, liveApplied); err != nil {
		return r.tickFailure(ctx, bot, err)
	}

	tag, err = r.pool.Exec(ctx,
		`UPDATE bots SET last_successful_tick_at = now(), last_error = NULL, consecutive_failures = 0, updated_at = now()
		 WHERE id = $1 AND deleted_at IS NULL`,
		bot.ID,
	)
	if err != nil {
		return err
	}
	if tag.RowsAffected() == 0 {
		return nil
	}

	return nil
}

func (r *Runtime) decide(ctx context.Context, impl strategy.Strategy, input strategy.Input) (strategy.Decision, error) {
	return impl.OnTick(input)
}

func (r *Runtime) tickFailure(ctx context.Context, bot *Bot, cause error) error {
	tickStatsFrom(ctx).markFailed(cause)

	message := cause.Error()
	runes := []rune(message)
	if len(runes) > 500 {
		message = string(runes[:497]) + "..."
	}
	var failures int
	err := r.pool.QueryRow(ctx,
		`UPDATE bots SET last_error = $2, consecutive_failures = consecutive_failures + 1, updated_at = now()
		 WHERE id = $1 AND deleted_at IS NULL RETURNING consecutive_failures`,
		bot.ID, message,
	).Scan(&failures)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil
	}
	if err != nil {
		return err
	}
	if failures >= 3 {
		r.pause(ctx, bot, "Bot paused after 3 consecutive tick failures: "+message, "exchange.failure_threshold", "exchange.failure_threshold", "critical")
		return nil
	}
	r.logger.Warn("bot tick failed", "bot_id", bot.ID, "error_type", fmt.Sprintf("%T", cause))
	return nil
}

func (r *Runtime) rememberPrice(ctx context.Context, bot *Bot, price decimal.Decimal) *Bot {
	prices := recentPrices(bot.Settings)
	prices = append(prices, price)
	if len(prices) > 30 {
		prices = prices[len(prices)-30:]
	}

	raw := make([]string, 0, len(prices))
	for _, value := range prices {
		raw = append(raw, value.String())
	}
	bot.Settings["mark_price"] = price.String()
	bot.Settings["recent_prices"] = raw

	encoded, _ := json.Marshal(bot.Settings)
	tag, err := r.pool.Exec(ctx,
		`UPDATE bots SET settings = $2::jsonb, updated_at = now() WHERE id = $1 AND deleted_at IS NULL`,
		bot.ID, string(encoded),
	)
	if err != nil {
		r.logger.Error("persist market settings failed", "bot_id", bot.ID)
		return nil
	}
	if tag.RowsAffected() == 0 {
		return nil
	}
	return bot
}

func recentPrices(settings map[string]any) []decimal.Decimal {
	raw, ok := settings["recent_prices"]
	if !ok {
		return nil
	}

	var values []any
	switch typed := raw.(type) {
	case []any:
		values = typed
	case []string:
		values = make([]any, 0, len(typed))
		for _, value := range typed {
			values = append(values, value)
		}
	default:
		return nil
	}

	prices := make([]decimal.Decimal, 0, len(values))
	for _, value := range values {
		if text, ok := value.(string); ok {
			if parsed, err := decimal.Parse(text); err == nil {
				prices = append(prices, parsed)
			}
		}
	}
	return prices
}

func divOr(a, b decimal.Decimal) decimal.Decimal {
	value, err := a.Div(b)
	if err != nil {
		return decimal.Zero()
	}
	return value
}

func (r *Runtime) dailyLoss(ctx context.Context, bot *Bot, mark decimal.Decimal) (decimal.Decimal, decimal.Decimal, bool, error) {
	var limitRaw string
	err := r.pool.QueryRow(ctx,
		`SELECT COALESCE(
			(SELECT daily_loss_limit::text FROM risk_limits
			 WHERE bot_id = $1 AND daily_loss_limit IS NOT NULL
			 ORDER BY id DESC LIMIT 1),
			(SELECT daily_loss_limit::text FROM risk_limits
			 WHERE user_id = $2 AND scope = 'user' AND bot_id IS NULL AND daily_loss_limit IS NOT NULL
			 ORDER BY id DESC LIMIT 1),
			$3::text
		)`,
		bot.ID, bot.UserID, defaultDailyLoss,
	).Scan(&limitRaw)
	if err != nil {
		return decimal.Zero(), decimal.Zero(), false, fmt.Errorf("load daily loss limit: %w", err)
	}

	today := r.now().Format("2006-01-02")
	baseline := decimal.Zero()
	stored, _ := bot.Settings["daily_loss_baseline"].(map[string]any)
	if stored != nil && fmt.Sprint(stored["day"]) == today {
		baseline = mustDecimal(fmt.Sprint(stored["realized"]))
	} else {
		baseline = bot.Realized
		bot.Settings["daily_loss_baseline"] = map[string]any{"day": today, "realized": bot.Realized.String()}
		r.persistSettings(ctx, bot)
	}

	loss := dailyLossValue(bot, baseline, mark)

	// Account-wide loss uses the same UTC-day baseline and evaluates every
	// user bot, not only the bot being ticked. Archived bots are intentionally
	// included: their realized P&L and residual inventory remain part of the
	// account's exposure even though their own risk rules no longer run.
	var userLimitRaw, userBaselineRaw = defaultDailyLoss, "0"
	var dayStart *time.Time
	err = r.pool.QueryRow(ctx,
		`SELECT COALESCE(daily_loss_limit::text, $2), day_start, COALESCE(day_start_realized_pnl::text, '0')
		 FROM risk_limits WHERE user_id = $1 AND scope = 'user' AND bot_id IS NULL
		 ORDER BY id DESC LIMIT 1`,
		bot.UserID, defaultDailyLoss,
	).Scan(&userLimitRaw, &dayStart, &userBaselineRaw)
	if err != nil && !errors.Is(err, pgx.ErrNoRows) {
		return decimal.Zero(), decimal.Zero(), false, fmt.Errorf("load account daily loss limit and baseline: %w", err)
	}

	if dayStart == nil || dayStart.UTC().Format("2006-01-02") != today {
		var realized = decimal.Zero()
		rows, err := r.pool.Query(ctx, `SELECT realized_pnl::text FROM bots WHERE user_id = $1`, bot.UserID)
		if err == nil {
			for rows.Next() {
				var raw string
				if rows.Scan(&raw) == nil {
					realized = realized.Add(mustDecimal(raw))
				}
			}
			rows.Close()
		}
		userBaselineRaw = realized.String()
		if _, err := r.pool.Exec(ctx,
			`UPDATE risk_limits SET day_start = now(), day_start_realized_pnl = $2, updated_at = now()
			 WHERE user_id = $1 AND scope = 'user' AND bot_id IS NULL`,
			bot.UserID, userBaselineRaw,
		); err != nil {
			r.logger.Error("persist account daily loss baseline failed", "user_id", bot.UserID, "error_type", fmt.Sprintf("%T", err))
		}
	}

	userLoss := decimal.Zero()
	rows, err := r.pool.Query(ctx,
		`SELECT realized_pnl::text, base_inventory::text, average_entry_price::text, settings
		 FROM bots WHERE user_id = $1`, bot.UserID,
	)
	if err == nil {
		userBaseline := mustDecimal(userBaselineRaw)
		total := decimal.Zero()
		for rows.Next() {
			var realizedRaw, baseRaw, averageRaw string
			var settings []byte
			if rows.Scan(&realizedRaw, &baseRaw, &averageRaw, &settings) != nil {
				continue
			}
			total = total.Add(mustDecimal(realizedRaw))
			var values map[string]any
			_ = json.Unmarshal(settings, &values)
			if parsed := recentMark(values); parsed != nil {
				total = total.Add(unrealized(mustDecimal(baseRaw), mustDecimal(averageRaw), *parsed))
			}
		}
		rows.Close()
		total = total.Sub(userBaseline)
		if total.IsNegative() {
			userLoss = total.Abs()
		}
	}

	userLimit := mustDecimal(userLimitRaw)
	if userLoss.GreaterThanOrEqual(userLimit) {
		return userLimit, userLoss, true, nil
	}

	return mustDecimal(limitRaw), loss, false, nil
}

func dailyLossValue(bot *Bot, baseline, mark decimal.Decimal) decimal.Decimal {
	pnl := bot.Realized.Sub(baseline).Add(unrealized(bot.Base, bot.AverageEntry, mark))
	if pnl.IsNegative() {
		return pnl.Abs()
	}
	return decimal.Zero()
}

func unrealized(base, average, mark decimal.Decimal) decimal.Decimal {
	if base.IsPositive() && average.IsPositive() {
		return mark.Sub(average).Mul(base)
	}
	return decimal.Zero()
}

func recentMark(settings map[string]any) *decimal.Decimal {
	raw, ok := settings["mark_price"].(string)
	if !ok {
		return nil
	}
	value, err := decimal.Parse(raw)
	if err != nil || !value.IsPositive() {
		return nil
	}
	return &value
}

func (r *Runtime) persistSettings(ctx context.Context, bot *Bot) {
	encoded, err := json.Marshal(bot.Settings)
	if err != nil {
		return
	}
	_, _ = r.pool.Exec(ctx, `UPDATE bots SET settings = $2::jsonb, updated_at = now() WHERE id = $1 AND deleted_at IS NULL`, bot.ID, string(encoded))
}

func (r *Runtime) pauseOtherBots(ctx context.Context, userID, currentBotID int64, message string) {
	rows, err := r.pool.Query(ctx,
		`SELECT id FROM bots WHERE user_id = $1 AND id <> $2 AND status = 'running' AND deleted_at IS NULL ORDER BY id`,
		userID, currentBotID,
	)
	if err != nil {
		return
	}
	defer rows.Close()

	var ids []int64
	for rows.Next() {
		var id int64
		if rows.Scan(&id) == nil {
			ids = append(ids, id)
		}
	}

	for _, id := range ids {
		other, err := r.loadBot(ctx, id)
		if err != nil || other.Status != "running" {
			continue
		}
		r.pause(ctx, other, message, "bot.daily_loss_paused", "risk.daily_loss", "warning")
	}
}

func (r *Runtime) pause(ctx context.Context, bot *Bot, message, activityType, riskType, severity string) {
	tickStatsFrom(ctx).markPaused()

	_, _ = r.pool.Exec(ctx,
		`UPDATE bots SET status = 'paused', last_error = $2, updated_at = now() WHERE id = $1 AND deleted_at IS NULL`,
		bot.ID, message,
	)
	if _, err := r.cancelOpenOrders(ctx, bot.UserID, bot.ID, activityType); err != nil {
		_, _ = r.pool.Exec(ctx, `UPDATE bots SET cancel_retry_requested = true, updated_at = now() WHERE id = $1 AND deleted_at IS NULL`, bot.ID)
		r.logger.Error("pause could not cancel orders", "bot_id", bot.ID, "error_type", fmt.Sprintf("%T", err))
	}
	if activityType != "" {
		r.activity(ctx, bot.UserID, bot.ID, activityType, severity, message, nil)
	}
	if riskType != "" {
		r.riskEvent(ctx, bot.UserID, bot.ID, riskType, severity, message, nil)
	}
}

func (r *Runtime) cancelOpenOrders(ctx context.Context, userID, botID int64, reason string) ([]openOrder, error) {
	orders, err := r.openOrders(ctx, botID)
	if err != nil {
		return nil, err
	}

	var bot *Bot
	if len(orders) > 0 {
		bot, err = r.loadBot(ctx, botID)
		if err != nil {
			return nil, err
		}
	}

	var failures []error
	for _, order := range orders {
		if bot != nil && bot.live() {
			if err := r.cancelLiveOrder(ctx, bot, order); err != nil {
				failures = append(failures, fmt.Errorf("cancel live order %d: %w", order.ID, err))
				r.riskEvent(ctx, userID, botID, "risk.cancel_failed", "critical", "A live order could not be canceled.", map[string]any{"order_id": order.ID, "client_order_id": order.ClientOrderID})
				continue
			}
		}

		_, err := r.pool.Exec(ctx,
			`UPDATE orders SET status = 'canceled', closed_at = now(), updated_at = now()
			 WHERE id = $1 AND status IN ('pending','open','partially_filled')`,
			order.ID,
		)
		if err != nil {
			failures = append(failures, fmt.Errorf("cancel order %d: %w", order.ID, err))
			r.riskEvent(ctx, userID, botID, "risk.cancel_failed", "warning", "An open order could not be canceled.", map[string]any{"order_id": order.ID})
			continue
		}
		if order.GridLevelID != nil {
			_, _ = r.pool.Exec(ctx, `UPDATE grid_levels SET state = 'canceled', updated_at = now() WHERE id = $1 AND state IN ('idle','open')`, *order.GridLevelID)
		}
	}

	_ = reason

	return orders, errors.Join(failures...)
}

func (r *Runtime) loadStrategy(ctx context.Context, bot *Bot) (strategy.State, strategy.Config, error) {
	var configuration []byte
	var rawState []byte
	var version int

	err := r.pool.QueryRow(ctx,
		`SELECT configuration, state, state_version FROM strategy_configs WHERE bot_id = $1`,
		bot.ID,
	).Scan(&configuration, &rawState, &version)
	if err != nil && !errors.Is(err, pgx.ErrNoRows) {
		return strategy.State{}, strategy.Config{}, err
	}

	values := map[string]any{}
	if len(configuration) > 0 {
		decoder := json.NewDecoder(bytes.NewReader(configuration))
		decoder.UseNumber()
		if err := decoder.Decode(&values); err != nil {
			return strategy.State{}, strategy.Config{}, fmt.Errorf("decode strategy configuration: %w", err)
		}
	}

	state := strategy.NewState()
	if len(rawState) > 0 {
		var stored struct {
			Version int            `json:"version"`
			Data    map[string]any `json:"data"`
		}
		if err := json.Unmarshal(rawState, &stored); err != nil {
			return strategy.State{}, strategy.Config{}, fmt.Errorf("decode strategy state: %w", err)
		}
		if stored.Version > 0 {
			state.Version = stored.Version
		}
		if stored.Data != nil {
			state.Data = stored.Data
		}
	}
	if version > 0 {
		state.Version = version
	}

	return state, strategy.Config{Key: bot.StrategyKey, Values: values}, nil
}

type liveFill struct {
	ID             int64
	ExchangeFillID string
	ClientOrderID  string
	Side           string
	Price          decimal.Decimal
	Quantity       decimal.Decimal
	Fee            decimal.Decimal
	Sequence       *int
}

// unappliedLiveFills returns exchange fills that were reconciled into
// inventory but have not yet been seen by the strategy. Paper fills are
// processed inline and are never part of this queue.
func (r *Runtime) unappliedLiveFills(ctx context.Context, botID int64) ([]liveFill, error) {
	rows, err := r.pool.Query(ctx,
		`SELECT f.id, f.exchange_fill_id, COALESCE(o.client_order_id, ''), f.side,
		        f.price::text, f.quantity::text, COALESCE(f.fee::text, '0'), gl.sequence
		 FROM fills f
		 JOIN orders o ON o.id = f.order_id
		 LEFT JOIN grid_levels gl ON gl.id = o.grid_level_id
		 WHERE f.bot_id = $1 AND f.strategy_applied_at IS NULL AND f.exchange <> 'paper'
		 ORDER BY f.filled_at, f.id`,
		botID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var fills []liveFill
	for rows.Next() {
		var fill liveFill
		var price, quantity, fee string
		if err := rows.Scan(&fill.ID, &fill.ExchangeFillID, &fill.ClientOrderID, &fill.Side, &price, &quantity, &fee, &fill.Sequence); err != nil {
			return nil, err
		}
		fill.Price = mustDecimal(price)
		fill.Quantity = mustDecimal(quantity)
		fill.Fee = mustDecimal(fee)
		fills = append(fills, fill)
	}

	return fills, rows.Err()
}

// applyLiveFills feeds reconciled exchange fills through OnFill before the
// next strategy decision. The returned fill IDs are marked applied in the same
// transaction as the strategy state, so a crash cannot skip OnFill.
func (r *Runtime) applyLiveFills(ctx context.Context, bot *Bot, impl strategy.Strategy, config strategy.Config, market strategy.MarketSnapshot, state strategy.State) (strategy.State, []int64, bool, error) {
	fills, err := r.unappliedLiveFills(ctx, bot.ID)
	if err != nil {
		return state, nil, false, err
	}

	ids := make([]int64, 0, len(fills))
	stop := false

	for _, fill := range fills {
		refreshed, err := r.loadBot(ctx, bot.ID)
		if err != nil {
			return state, nil, false, err
		}
		orders, err := r.openOrders(ctx, bot.ID)
		if err != nil {
			return state, nil, false, err
		}

		clientID := fill.ClientOrderID
		decision, err := impl.OnFill(
			strategy.Input{Market: market, Bot: refreshed.snapshot(orders), Config: config, State: state},
			strategy.FillEvent{
				ExchangeFillID:    fill.ExchangeFillID,
				Side:              strategy.Side(fill.Side),
				Price:             fill.Price,
				Quantity:          fill.Quantity,
				Fee:               fill.Fee,
				ClientOrderID:     &clientID,
				GridLevelSequence: fill.Sequence,
			},
		)
		if err != nil {
			return state, nil, false, err
		}
		state = decision.State
		stop = stop || decision.Stop
		ids = append(ids, fill.ID)
	}

	return state, ids, stop, nil
}

func (r *Runtime) persistStrategy(ctx context.Context, botID int64, state strategy.State, appliedFillIDs []int64) error {
	encoded, err := json.Marshal(state)
	if err != nil {
		return fmt.Errorf("encode strategy state: %w", err)
	}

	tx, err := r.pool.Begin(ctx)
	if err != nil {
		return fmt.Errorf("persist strategy state: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	var activeBotID int64
	if err := tx.QueryRow(ctx,
		`SELECT id FROM bots WHERE id = $1 AND deleted_at IS NULL FOR UPDATE`,
		botID,
	).Scan(&activeBotID); err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		return fmt.Errorf("persist strategy state: %w", err)
	}

	if _, err := tx.Exec(ctx,
		`UPDATE strategy_configs SET state = $2::jsonb, state_version = $3, updated_at = now() WHERE bot_id = $1`,
		botID, string(encoded), state.Version,
	); err != nil {
		return fmt.Errorf("persist strategy state: %w", err)
	}

	if len(appliedFillIDs) > 0 {
		if _, err := tx.Exec(ctx,
			`UPDATE fills SET strategy_applied_at = now() WHERE bot_id = $1 AND id = ANY($2::bigint[])`,
			botID, appliedFillIDs,
		); err != nil {
			return fmt.Errorf("mark live fills applied: %w", err)
		}
	}

	if err := tx.Commit(ctx); err != nil {
		return fmt.Errorf("persist strategy state: %w", err)
	}

	return nil
}

func (r *Runtime) syncGrid(ctx context.Context, bot *Bot, levels []strategy.GridLevelPlan, active *gridState, stats *tickStats) (*gridState, bool, error) {
	if len(levels) == 0 {
		return active, false, nil
	}

	if active != nil && len(active.Levels) == len(levels) {
		match := true
		for index, level := range active.Levels {
			if level.Sequence != levels[index].Sequence || !level.Price.Equals(levels[index].Price) || string(level.Side) != string(levels[index].Side) {
				match = false
				break
			}
		}
		if match {
			stats.addGridMatched(len(levels))

			return active, false, nil
		}
	}

	tx, err := r.pool.Begin(ctx)
	if err != nil {
		return nil, false, err
	}
	defer func() { _ = tx.Rollback(ctx) }()

	var activeBotID int64
	if err := tx.QueryRow(ctx,
		`SELECT id FROM bots WHERE id = $1 AND deleted_at IS NULL FOR UPDATE`,
		bot.ID,
	).Scan(&activeBotID); err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return active, false, nil
		}
		return nil, false, err
	}

	if active != nil {
		if bot.live() {
			open, err := r.openOrders(ctx, bot.ID)
			if err != nil {
				return nil, false, err
			}
			for _, order := range open {
				if err := r.cancelLiveOrder(ctx, bot, order); err != nil {
					return nil, false, err
				}
			}
		}

		canceled, err := tx.Exec(ctx,
			`UPDATE orders SET status = 'canceled', closed_at = now(), updated_at = now()
			 WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`,
			bot.ID,
		)
		if err != nil {
			return nil, false, err
		}
		stats.ordersCanceled += int(canceled.RowsAffected())
		if _, err := tx.Exec(ctx,
			`UPDATE grid_levels SET state = 'canceled', updated_at = now()
			 WHERE grid_id = $1 AND state IN ('idle','open')`,
			active.ID,
		); err != nil {
			return nil, false, err
		}
		if _, err := tx.Exec(ctx, `UPDATE grids SET status = 'stopped', updated_at = now() WHERE id = $1`, active.ID); err != nil {
			return nil, false, err
		}
	}

	center := divOr(levels[0].Price.Add(levels[len(levels)-1].Price), decimal.FromInt(2))
	spacing := decimal.Zero()
	if len(levels) > 1 {
		spacing = levels[1].Price.Sub(levels[0].Price).Abs()
	}
	spacingType := string(bot.SpacingType())

	var gridID int64
	if err := tx.QueryRow(ctx,
		`INSERT INTO grids (bot_id, center_price, spacing_type, level_count, spacing_value, status, created_at, updated_at)
		 VALUES ($1, $2, $3, $4, $5, 'active', now(), now()) RETURNING id`,
		bot.ID, center.String(), spacingType, len(levels), spacing.String(),
	).Scan(&gridID); err != nil {
		return nil, false, err
	}

	created := &gridState{ID: gridID, Center: center, Spacing: spacingType, SpacingVal: spacing, LevelCount: len(levels)}
	for _, level := range levels {
		var levelID int64
		if err := tx.QueryRow(ctx,
			`INSERT INTO grid_levels (grid_id, sequence, price, side, state, created_at, updated_at)
			 VALUES ($1, $2, $3, $4, 'idle', now(), now()) RETURNING id`,
			gridID, level.Sequence, level.Price.String(), string(level.Side),
		).Scan(&levelID); err != nil {
			return nil, false, err
		}
		created.Levels = append(created.Levels, grid.GridLevel{Sequence: level.Sequence, Price: level.Price, Side: grid.Side(level.Side), State: grid.LevelIdle})
	}

	if err := tx.Commit(ctx); err != nil {
		return nil, false, err
	}

	stats.addGridCreated(len(levels))
	if active != nil {
		stats.addGridReplaced(len(active.Levels))
	}

	return created, active != nil, nil
}

func (b *Bot) SpacingType() strategy.SpacingType {
	if b.StrategyKey == "dynamic-grid" {
		return strategy.SpacingDynamic
	}
	return strategy.SpacingArithmetic
}

func (r *Runtime) applyCancels(ctx context.Context, bot *Bot, impl strategy.Strategy, state *strategy.State, clientIDs []string, stats *tickStats) error {
	if len(clientIDs) == 0 {
		return nil
	}

	for _, clientID := range clientIDs {
		var orderID, levelID int64
		var levelSequence *int
		var exchangeOrderID, filled string
		err := r.pool.QueryRow(ctx,
			`SELECT o.id, COALESCE(o.grid_level_id, 0), gl.sequence, COALESCE(o.exchange_order_id, ''), o.filled_quantity::text
			 FROM orders o LEFT JOIN grid_levels gl ON gl.id = o.grid_level_id
			 WHERE o.bot_id = $1 AND o.client_order_id = $2 AND o.status IN ('pending','open','partially_filled')`,
			bot.ID, clientID,
		).Scan(&orderID, &levelID, &levelSequence, &exchangeOrderID, &filled)
		if err != nil {
			continue
		}
		if bot.live() {
			liveOrder := openOrder{ID: orderID, ClientOrderID: clientID, ExchangeOrderID: exchangeOrderID, FilledQuantity: mustDecimal(filled)}
			if err := r.cancelLiveOrder(ctx, bot, liveOrder); err != nil {
				return err
			}
		}
		tag, _ := r.pool.Exec(ctx, `UPDATE orders SET status = 'canceled', closed_at = now(), updated_at = now() WHERE id = $1 AND status IN ('pending','open','partially_filled')`, orderID)
		if tag.RowsAffected() > 0 {
			stats.ordersCanceled += int(tag.RowsAffected())
			r.activity(ctx, bot.UserID, bot.ID, "order.canceled", "info", "Order canceled by strategy.", map[string]any{
				"client_order_id": clientID,
				"reason":          "strategy",
			})
		}
		if levelID > 0 {
			_, _ = r.pool.Exec(ctx, `UPDATE grid_levels SET state = 'canceled', updated_at = now() WHERE id = $1 AND state IN ('idle','open')`, levelID)
		}
		decision, err := impl.OnCancel(r.strategyInput(ctx, bot, *state), strategy.CancelEvent{ClientOrderID: clientID, GridLevelSequence: levelSequence, Reason: "strategy"})
		if err != nil {
			return err
		}
		*state = decision.State
	}

	return nil
}

func (r *Runtime) strategyInput(ctx context.Context, bot *Bot, state strategy.State) strategy.Input {
	price := decimal.Zero()
	if raw, ok := bot.Settings["mark_price"].(string); ok {
		price = mustDecimal(raw)
	}
	orders, _ := r.openOrders(ctx, bot.ID)
	return strategy.Input{
		Market: strategy.MarketSnapshot{Symbol: bot.Symbol, Price: price, RecentPrices: recentPrices(bot.Settings)},
		Bot:    bot.snapshot(orders), State: state,
	}
}

func (r *Runtime) placeOrders(ctx context.Context, bot *Bot, active *gridState, intents []strategy.OrderIntent) (int, error) {
	if active == nil {
		return 0, nil
	}

	if bot.live() {
		return r.placeLiveOrders(ctx, bot, active, intents)
	}

	open, err := r.openOrders(ctx, bot.ID)
	if err != nil {
		return 0, err
	}

	tx, err := r.pool.Begin(ctx)
	if err != nil {
		return 0, err
	}
	defer func() { _ = tx.Rollback(ctx) }()

	var status, desired string
	if err := tx.QueryRow(ctx, `SELECT status, desired_status FROM bots WHERE id = $1 AND deleted_at IS NULL FOR UPDATE`, bot.ID).Scan(&status, &desired); err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return 0, nil
		}
		return 0, err
	}
	if status != "running" || desired != "running" {
		return 0, nil
	}
	atPrice := map[string]bool{}
	atSequence := map[int]bool{}
	openBuyNotional := decimal.Zero()
	reservedSell := decimal.Zero()
	for _, order := range open {
		atPrice[order.Price.String()] = true
		if order.Sequence != nil {
			atSequence[*order.Sequence] = true
		}
		remaining := order.Quantity.Sub(order.FilledQuantity)
		if order.Side == "buy" {
			openBuyNotional = openBuyNotional.Add(order.Price.Mul(remaining))
		} else {
			reservedSell = reservedSell.Add(remaining)
		}
	}

	maxOpen := defaultMaxOpen
	maxNotionalRaw := defaultMaxNotional
	_ = tx.QueryRow(ctx,
		`SELECT COALESCE(max_open_orders, $2), COALESCE(max_position_notional::text, $4) FROM risk_limits WHERE bot_id = $1
		 UNION ALL SELECT COALESCE(max_open_orders, $2), COALESCE(max_position_notional::text, $4) FROM risk_limits WHERE user_id = $3 AND scope = 'user' AND bot_id IS NULL LIMIT 1`,
		bot.ID, defaultMaxOpen, bot.UserID, defaultMaxNotional,
	).Scan(&maxOpen, &maxNotionalRaw)
	maxNotional := mustDecimal(maxNotionalRaw)

	placed := 0
	positionLimitSkips := 0
	projectedNotional := ""
	defer func() {
		r.recordPositionLimitSkips(ctx, bot, positionLimitSkips, projectedNotional)
	}()
	for index, intent := range intents {
		if !intent.Price.IsPositive() || !intent.Quantity.IsPositive() {
			r.riskEvent(ctx, bot.UserID, bot.ID, "risk.invalid_order", "critical", "Order skipped: price and quantity must be positive.", nil)
			continue
		}
		if atPrice[intent.Price.String()] {
			continue
		}
		if intent.GridLevelSequence != nil && atSequence[*intent.GridLevelSequence] {
			continue
		}
		if len(open)+placed >= maxOpen {
			break
		}
		notional := intent.Price.Mul(intent.Quantity)
		fee := notional.Mul(bot.FeeRate)
		if intent.Side == strategy.SideBuy {
			if notional.Add(fee).Add(openBuyNotional).GreaterThan(bot.Quote) {
				continue
			}
		} else if intent.Quantity.Add(reservedSell).GreaterThan(bot.Base) {
			continue
		}

		projected := bot.Base.Abs().Mul(intent.Price).Add(openBuyNotional)
		if intent.Side == strategy.SideBuy {
			projected = projected.Add(notional)
		} else {
			projected = projected.Sub(notional)
			if projected.IsNegative() {
				projected = decimal.Zero()
			}
		}
		if projected.GreaterThan(maxNotional) {
			positionLimitSkips++
			projectedNotional = projected.String()
			continue
		}

		var levelID *int64
		if intent.GridLevelSequence != nil {
			var id int64
			err := tx.QueryRow(ctx, `SELECT id FROM grid_levels WHERE grid_id = $1 AND sequence = $2`, active.ID, *intent.GridLevelSequence).Scan(&id)
			if errors.Is(err, pgx.ErrNoRows) {
				r.riskEvent(ctx, bot.UserID, bot.ID, "order.level_missing", "warning", "Grid level was missing during order placement.", map[string]any{"sequence": *intent.GridLevelSequence})
				continue
			}
			if err != nil {
				return placed, err
			}
			levelID = &id
		}

		clientID := fmt.Sprintf("go-%d-%d-%d", bot.ID, r.now().UnixNano(), index)
		var orderID int64
		err := tx.QueryRow(ctx,
			`INSERT INTO orders (bot_id, grid_id, grid_level_id, client_order_id, mode, side, type, price, quantity, filled_quantity, status, requested_at, opened_at, created_at, updated_at)
			 SELECT $1, $2, $3, $4, $5, $6, 'limit', $7, $8, 0, 'open', now(), now(), now(), now()
			 WHERE EXISTS (SELECT 1 FROM bots WHERE id = $1 AND status = 'running' AND desired_status = 'running' AND deleted_at IS NULL)
			 RETURNING id`,
			bot.ID, active.ID, levelID, clientID, bot.Mode, string(intent.Side), intent.Price.String(), intent.Quantity.String(),
		).Scan(&orderID)
		if errors.Is(err, pgx.ErrNoRows) {
			return placed, nil
		}
		if err != nil {
			return placed, err
		}
		if levelID != nil {
			_, _ = tx.Exec(ctx, `UPDATE grid_levels SET state = 'open', updated_at = now() WHERE id = $1`, *levelID)
		}
		atPrice[intent.Price.String()] = true
		if intent.GridLevelSequence != nil {
			atSequence[*intent.GridLevelSequence] = true
		}
		if intent.Side == strategy.SideBuy {
			openBuyNotional = openBuyNotional.Add(notional)
		} else {
			reservedSell = reservedSell.Add(intent.Quantity)
		}
		placed++
	}

	if err := tx.Commit(ctx); err != nil {
		return placed, err
	}

	return placed, nil
}

type appliedFill struct {
	ClientOrderID  string
	ExchangeFillID string
	Side           string
	Price          decimal.Decimal
	Quantity       decimal.Decimal
	Fee            decimal.Decimal
	Sequence       *int
}

func (r *Runtime) processPaperFills(ctx context.Context, bot *Bot, active *gridState, before time.Time) ([]appliedFill, error) {
	if active == nil {
		return nil, nil
	}

	orders, err := r.openOrders(ctx, bot.ID)
	if err != nil {
		return nil, err
	}

	var eligible []paperfill.Order
	byClient := map[string]openOrder{}
	for _, order := range orders {
		if order.OpenedAt != nil && !order.OpenedAt.Before(before) {
			continue
		}
		eligible = append(eligible, paperfill.Order{
			ClientOrderID: order.ClientOrderID, Side: paperfill.Side(order.Side), Price: order.Price,
			Quantity: order.Quantity, FilledQuantity: order.FilledQuantity, Status: paperfill.OrderStatus(order.Status),
		})
		byClient[order.ClientOrderID] = order
	}

	price := mustDecimal(fmt.Sprint(bot.Settings["mark_price"]))
	fills, err := paperfill.QualifyingFills(price, eligible, bot.FeeRate.String())
	if err != nil {
		return nil, err
	}

	var applied []appliedFill
	for _, fill := range fills {
		order := byClient[fill.ClientOrderID]
		remaining := order.Quantity.Sub(order.FilledQuantity)
		if !fill.Quantity.Equals(remaining) || !fill.Quantity.IsPositive() {
			continue
		}
		notional := fill.Price.Mul(fill.Quantity)
		if fill.Side == "sell" && fill.Quantity.GreaterThan(bot.Base) {
			continue
		}
		if fill.Side == "buy" && notional.Add(fill.Fee).GreaterThan(bot.Quote) {
			continue
		}

		tx, err := r.pool.Begin(ctx)
		if err != nil {
			return nil, err
		}
		var activeBotID int64
		if err := tx.QueryRow(ctx,
			`SELECT id FROM bots WHERE id = $1 AND deleted_at IS NULL FOR UPDATE`,
			bot.ID,
		).Scan(&activeBotID); err != nil {
			_ = tx.Rollback(ctx)
			if errors.Is(err, pgx.ErrNoRows) {
				return nil, nil
			}
			return nil, err
		}
		tag, err := tx.Exec(ctx,
			`UPDATE orders SET status = 'filled', filled_quantity = quantity, closed_at = now(), updated_at = now()
			 WHERE id = $1 AND status IN ('open','partially_filled')`,
			order.ID,
		)
		if err != nil {
			_ = tx.Rollback(ctx)
			return nil, err
		}
		if tag.RowsAffected() == 0 {
			_ = tx.Rollback(ctx)
			continue
		}

		var fillID int64
		err = tx.QueryRow(ctx,
			`INSERT INTO fills (bot_id, order_id, exchange, exchange_fill_id, side, price, quantity, fee, fee_currency, filled_at, created_at, updated_at)
			 VALUES ($1, $2, 'paper', $3, $4, $5, $6, $7, $8, now(), now(), now())
			 ON CONFLICT (exchange, exchange_fill_id) DO NOTHING
			 RETURNING id`,
			bot.ID, order.ID, fill.ClientOrderID, string(fill.Side), fill.Price.String(), fill.Quantity.String(), fill.Fee.String(), quoteAsset(bot.Symbol),
		).Scan(&fillID)
		if errors.Is(err, pgx.ErrNoRows) {
			_ = tx.Rollback(ctx)
			continue
		}
		if err != nil {
			_ = tx.Rollback(ctx)
			return nil, err
		}

		newBase, newQuote, newAverage, newRealized, newFees := applyInventory(bot, fill)
		baseDelta := newBase.Sub(bot.Base)
		quoteDelta := newQuote.Sub(bot.Quote)
		if _, err := tx.Exec(ctx,
			`UPDATE bots SET base_inventory = $2, quote_inventory = $3, average_entry_price = $4,
			        realized_pnl = $5, fees_paid = $6, updated_at = now() WHERE id = $1`,
			bot.ID, newBase.String(), newQuote.String(), newAverage.String(), newRealized.String(), newFees.String(),
		); err != nil {
			_ = tx.Rollback(ctx)
			return nil, err
		}

		if _, err := tx.Exec(ctx,
			`INSERT INTO inventory_ledger_entries
			 (bot_id, fill_id, side, quantity, price, base_delta, quote_delta, base_balance, quote_balance, average_entry_price, realized_pnl, created_at)
			 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, now())`,
			bot.ID, fillID, string(fill.Side), fill.Quantity.String(), fill.Price.String(),
			baseDelta.String(), quoteDelta.String(), newBase.String(), newQuote.String(), newAverage.String(), newRealized.String(),
		); err != nil {
			_ = tx.Rollback(ctx)
			return nil, err
		}

		if order.GridLevelID != nil {
			_, _ = tx.Exec(ctx, `UPDATE grid_levels SET state = 'filled', updated_at = now() WHERE id = $1`, *order.GridLevelID)
		}

		if err := tx.Commit(ctx); err != nil {
			return nil, err
		}

		bot.Base, bot.Quote, bot.AverageEntry, bot.Realized, bot.Fees = newBase, newQuote, newAverage, newRealized, newFees
		r.activity(ctx, bot.UserID, bot.ID, "order.filled", "info", "Paper order filled.", map[string]any{
			"client_order_id": fill.ClientOrderID, "price": fill.Price.String(), "quantity": fill.Quantity.String(),
		})
		applied = append(applied, appliedFill{
			ClientOrderID: fill.ClientOrderID, ExchangeFillID: fill.ClientOrderID, Side: string(fill.Side),
			Price: fill.Price, Quantity: fill.Quantity, Fee: fill.Fee, Sequence: order.Sequence,
		})
	}

	return applied, nil
}

func quoteAsset(symbol string) string {
	parts := strings.SplitN(symbol, "/", 2)
	if len(parts) == 2 && parts[1] != "" {
		return parts[1]
	}
	return "USDT"
}

func applyInventory(bot *Bot, fill paperfill.Fill) (decimal.Decimal, decimal.Decimal, decimal.Decimal, decimal.Decimal, decimal.Decimal) {
	base, quote, average, realized, fees := bot.Base, bot.Quote, bot.AverageEntry, bot.Realized, bot.Fees

	if fill.Side == "buy" {
		cost := fill.Price.Mul(fill.Quantity)
		newBase := base.Add(fill.Quantity)
		if newBase.IsPositive() {
			average = divOr(base.Mul(average).Add(cost), newBase)
		}
		base = newBase
		quote = quote.Sub(cost).Sub(fill.Fee)
		realized = realized.Sub(fill.Fee)
	} else {
		realized = realized.Add(fill.Price.Sub(average).Mul(fill.Quantity)).Sub(fill.Fee)
		base = base.Sub(fill.Quantity)
		quote = quote.Add(fill.Price.Mul(fill.Quantity)).Sub(fill.Fee)
		if base.IsZero() {
			average = decimal.Zero()
		}
	}
	fees = fees.Add(fill.Fee)

	return base, quote, average, realized, fees
}
