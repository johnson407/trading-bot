package backtest

import (
	"strconv"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

const atomLayout = "2006-01-02T15:04:05-07:00"

// Fill mirrors App\Domain\Backtest\ValueObjects\SimulatedFill.
type Fill struct {
	Sequence          int
	Side              strategy.Side
	Price             decimal.Decimal
	Quantity          decimal.Decimal
	Fee               decimal.Decimal
	FeeCurrency       string
	FilledAt          time.Time
	ClientOrderID     string
	GridLevelSequence *int
}

// ToArray mirrors SimulatedFill::toArray.
func (f Fill) ToArray() map[string]any {
	var clientOrderID any
	if f.ClientOrderID != "" {
		clientOrderID = f.ClientOrderID
	}

	var gridLevelSequence any
	if f.GridLevelSequence != nil {
		gridLevelSequence = *f.GridLevelSequence
	}

	return map[string]any{
		"sequence":            f.Sequence,
		"side":                string(f.Side),
		"price":               f.Price.String(),
		"quantity":            f.Quantity.String(),
		"fee":                 f.Fee.String(),
		"fee_currency":        f.FeeCurrency,
		"filled_at":           formatAtom(f.FilledAt),
		"client_order_id":     clientOrderID,
		"grid_level_sequence": gridLevelSequence,
	}
}

// EquityPoint mirrors App\Domain\Backtest\ValueObjects\EquityPoint.
type EquityPoint struct {
	At     time.Time
	Equity decimal.Decimal
}

// ToArray mirrors EquityPoint::toArray.
func (p EquityPoint) ToArray() map[string]any {
	return map[string]any{
		"at":     formatAtom(p.At),
		"equity": p.Equity.String(),
	}
}

// Metrics mirrors App\Domain\Backtest\ValueObjects\BacktestMetrics.
type Metrics struct {
	EndReason           string
	FinalEquity         decimal.Decimal
	NetPnL              decimal.Decimal
	RealizedPnL         decimal.Decimal
	UnrealizedPnL       decimal.Decimal
	Fees                decimal.Decimal
	ReturnPercent       decimal.Decimal
	MaxDrawdown         decimal.Decimal
	MaxDrawdownPercent  decimal.Decimal
	FinalBaseInventory  decimal.Decimal
	FinalQuoteInventory decimal.Decimal
	FinalPrice          decimal.Decimal
	FillCount           int
	BuyCount            int
	SellCount           int
	CandlesProcessed    int
	DurationMs          int
}

// ToArray mirrors BacktestMetrics::toArray.
func (m Metrics) ToArray() map[string]any {
	return map[string]any{
		"end_reason":            m.EndReason,
		"final_equity":          m.FinalEquity.String(),
		"net_pnl":               m.NetPnL.String(),
		"realized_pnl":          m.RealizedPnL.String(),
		"unrealized_pnl":        m.UnrealizedPnL.String(),
		"fees":                  m.Fees.String(),
		"return_percent":        m.ReturnPercent.String(),
		"max_drawdown":          m.MaxDrawdown.String(),
		"max_drawdown_percent":  m.MaxDrawdownPercent.String(),
		"final_base_inventory":  m.FinalBaseInventory.String(),
		"final_quote_inventory": m.FinalQuoteInventory.String(),
		"final_price":           m.FinalPrice.String(),
		"fill_count":            m.FillCount,
		"buy_count":             m.BuyCount,
		"sell_count":            m.SellCount,
		"candles_processed":     m.CandlesProcessed,
		"duration_ms":           m.DurationMs,
	}
}

// Result mirrors App\Domain\Backtest\ValueObjects\BacktestResult.
type Result struct {
	Metrics     Metrics
	Fills       []Fill
	EquityCurve []EquityPoint
	FinalState  strategy.State
}

// ToArray returns the fixture/storage shape of the result.
func (r Result) ToArray() map[string]any {
	fills := make([]any, 0, len(r.Fills))
	for _, fill := range r.Fills {
		fills = append(fills, fill.ToArray())
	}

	equity := make([]any, 0, len(r.EquityCurve))
	for _, point := range r.EquityCurve {
		equity = append(equity, point.ToArray())
	}

	return map[string]any{
		"metrics":      r.Metrics.ToArray(),
		"fills":        fills,
		"equity_curve": equity,
		"final_state": map[string]any{
			"version": r.FinalState.Version,
			"data":    r.FinalState.Data,
		},
	}
}

// CalculateMetrics mirrors MetricsCalculator::calculate.
func CalculateMetrics(
	portfolio *InMemoryPortfolio,
	equity []EquityPoint,
	fills []Fill,
	capital decimal.Decimal,
	lastPrice decimal.Decimal,
	candlesProcessed int,
	endReason string,
	durationMs int,
) Metrics {
	finalEquity := portfolio.Equity(lastPrice)
	if len(equity) > 0 {
		finalEquity = equity[len(equity)-1].Equity
	}

	net := finalEquity.Sub(capital)
	returnPercent := decimal.Zero()
	if !capital.IsZero() {
		if ratio, err := net.Div(capital); err == nil {
			returnPercent = ratio.Mul(decimal.FromInt(100))
		}
	}

	drawdown, drawdownPercent := drawdown(equity)

	buyCount := 0
	for _, fill := range fills {
		if fill.Side == strategy.SideBuy {
			buyCount++
		}
	}

	return Metrics{
		EndReason:           endReason,
		FinalEquity:         finalEquity,
		NetPnL:              net,
		RealizedPnL:         portfolio.Realized,
		UnrealizedPnL:       portfolio.Unrealized(lastPrice),
		Fees:                portfolio.Fees,
		ReturnPercent:       returnPercent,
		MaxDrawdown:         drawdown,
		MaxDrawdownPercent:  drawdownPercent,
		FinalBaseInventory:  portfolio.Base,
		FinalQuoteInventory: portfolio.Quote,
		FinalPrice:          lastPrice,
		FillCount:           len(fills),
		BuyCount:            buyCount,
		SellCount:           len(fills) - buyCount,
		CandlesProcessed:    candlesProcessed,
		DurationMs:          durationMs,
	}
}

func drawdown(equity []EquityPoint) (decimal.Decimal, decimal.Decimal) {
	if len(equity) == 0 {
		return decimal.Zero(), decimal.Zero()
	}

	peak := equity[0].Equity
	worst := decimal.Zero()
	worstPercent := decimal.Zero()

	for _, point := range equity {
		if point.Equity.GreaterThan(peak) {
			peak = point.Equity
		}
		current := peak.Sub(point.Equity)
		if current.GreaterThan(worst) {
			worst = current
			if peak.IsPositive() {
				if ratio, err := current.Div(peak); err == nil {
					worstPercent = ratio.Mul(decimal.FromInt(100))
				} else {
					worstPercent = decimal.Zero()
				}
			} else {
				worstPercent = decimal.Zero()
			}
		}
	}

	return worst, worstPercent
}

// Downsample mirrors MetricsCalculator::downsample.
func Downsample(equity []EquityPoint, maxPoints int) []EquityPoint {
	if maxPoints < 2 || len(equity) <= maxPoints {
		return equity
	}

	selected := make(map[int]EquityPoint, maxPoints)
	for index := 0; index < maxPoints; index++ {
		source := int(roundHalfUp(float64(index) * float64(len(equity)-1) / float64(maxPoints-1)))
		selected[source] = equity[source]
	}

	result := make([]EquityPoint, 0, len(selected))
	for index := 0; index < len(equity); index++ {
		if point, ok := selected[index]; ok {
			result = append(result, point)
		}
	}

	return result
}

func roundHalfUp(value float64) float64 {
	if value < 0 {
		return float64(int64(value - 0.5))
	}

	return float64(int64(value + 0.5))
}

func formatAtom(value time.Time) string {
	return value.UTC().Format(atomLayout)
}

func itoa(value int) string {
	return strconv.Itoa(value)
}
