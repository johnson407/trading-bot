package risk

import (
	"fmt"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// ExitRules ports App\Domain\Exit\ExitRules. Values are trimmed; a blank value
// is treated as not configured.
type ExitRules struct {
	StopLossPercent   *string
	TakeProfitPercent *string
	RangeLow          *string
	RangeHigh         *string
}

// NewExitRules normalizes the four optional rule values.
func NewExitRules(stopLossPercent, takeProfitPercent, rangeLow, rangeHigh *string) ExitRules {
	return ExitRules{
		StopLossPercent:   trimmedOptional(stopLossPercent),
		TakeProfitPercent: trimmedOptional(takeProfitPercent),
		RangeLow:          trimmedOptional(rangeLow),
		RangeHigh:         trimmedOptional(rangeHigh),
	}
}

// Empty reports whether no rule is configured.
func (r ExitRules) Empty() bool {
	return r.StopLossPercent == nil && r.TakeProfitPercent == nil && r.RangeLow == nil && r.RangeHigh == nil
}

// ToArray returns the normalized values.
func (r ExitRules) ToArray() map[string]any {
	return map[string]any{
		"stop_loss_percent":   optionalString(r.StopLossPercent),
		"take_profit_percent": optionalString(r.TakeProfitPercent),
		"range_low":           optionalString(r.RangeLow),
		"range_high":          optionalString(r.RangeHigh),
	}
}

// Evaluate returns the first triggered rule in PHP precedence order:
// stop-loss, take-profit, then the range bound.
func (r ExitRules) Evaluate(markPrice, averageEntryPrice, baseInventory string) *ExitSignal {
	mark := decimal.MustParse(markPrice)
	average := decimal.MustParse(averageEntryPrice)
	base := decimal.MustParse(baseInventory)

	if base.IsPositive() && average.IsPositive() {
		if r.StopLossPercent != nil {
			if percent := decimal.MustParse(*r.StopLossPercent); percent.IsPositive() {
				threshold := average.Mul(decimal.FromInt(1).Sub(mustDivide(percent, decimal.FromInt(100))))
				if mark.LessThanOrEqual(threshold) {
					return newExitSignal(ExitStopLoss, mark, threshold, average, base, nil)
				}
			}
		}

		if r.TakeProfitPercent != nil {
			if percent := decimal.MustParse(*r.TakeProfitPercent); percent.IsPositive() {
				threshold := average.Mul(decimal.FromInt(1).Add(mustDivide(percent, decimal.FromInt(100))))
				if mark.GreaterThanOrEqual(threshold) {
					return newExitSignal(ExitTakeProfit, mark, threshold, average, base, nil)
				}
			}
		}
	}

	if r.RangeLow != nil {
		if low := decimal.MustParse(*r.RangeLow); low.IsPositive() && mark.LessThanOrEqual(low) {
			bound := "low"
			return newExitSignal(ExitRange, mark, low, average, base, &bound)
		}
	}

	if r.RangeHigh != nil {
		if high := decimal.MustParse(*r.RangeHigh); high.IsPositive() && mark.GreaterThanOrEqual(high) {
			bound := "high"
			return newExitSignal(ExitRange, mark, high, average, base, &bound)
		}
	}

	return nil
}

func newExitSignal(rule ExitRule, mark, threshold, average, base decimal.Decimal, bound *string) *ExitSignal {
	return &ExitSignal{
		Rule:              rule,
		MarkPrice:         mark.String(),
		Threshold:         threshold.String(),
		AverageEntryPrice: average.String(),
		BaseInventory:     base.String(),
		Bound:             bound,
	}
}

// ExitGuardMonitor ports App\Domain\Exit\ExitGuardMonitor. It pauses and
// cancels; it never places a market flatten order and never resumes a bot.
type ExitGuardMonitor struct {
	Store    *Store
	Canceler OrderCanceler
}

// NewExitGuardMonitor builds a monitor over the in-memory store.
func NewExitGuardMonitor(store *Store, canceler OrderCanceler) *ExitGuardMonitor {
	return &ExitGuardMonitor{Store: store, Canceler: canceler}
}

// Evaluate triggers the protective exit for a running bot.
func (g *ExitGuardMonitor) Evaluate(bot *Bot, markPrice string) *ExitSignal {
	if bot.Status != StatusRunning {
		return nil
	}

	rules := NewExitRules(bot.StopLossPercent, bot.TakeProfitPercent, bot.RangeLow, bot.RangeHigh)
	if rules.Empty() {
		return nil
	}

	signal := rules.Evaluate(markPrice, bot.AverageEntryPrice.String(), bot.BaseInventory.String())
	if signal == nil {
		return nil
	}

	message := fmt.Sprintf("%s triggered at %s (threshold %s). Bot paused and open orders canceled.", signal.Label(), signal.MarkPrice, signal.Threshold)

	if bot.Status != StatusRunning {
		return nil
	}

	bot.Status = StatusPaused
	bot.LastError = &message

	for _, failure := range g.Store.CancelOpenOrders(bot.ID, g.Canceler) {
		g.Store.RecordRisk(bot, "risk.exit_cancel_failed", SeverityCritical, "Protective exit could not cancel an order.", map[string]any{
			"order_id":        failure.Order.ID,
			"client_order_id": failure.Order.ClientOrderID,
			"error":           failure.Err.Error(),
		})
	}

	severity := SeverityWarning
	activitySeverity := "warning"
	if signal.Rule == ExitStopLoss {
		severity = SeverityCritical
		activitySeverity = "error"
	}

	g.Store.RecordRisk(bot, signal.RiskEventType(), severity, message, signal.Context())
	g.Store.RecordActivity(bot, "bot.exit_guard_triggered", activitySeverity, message, signal.Context())

	return signal
}

func optionalString(value *string) any {
	if value == nil {
		return nil
	}

	return *value
}

func mustDivide(a, b decimal.Decimal) decimal.Decimal {
	quotient, err := a.Div(b)
	if err != nil {
		panic(err)
	}

	return quotient
}
