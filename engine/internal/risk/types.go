// Package risk ports the PHP engine's blocking risk gates into the Go engine.
//
// The package is deliberately in-memory and side-effect free: it never opens an
// exchange connection, never signs a request, and never places an order. Callers
// provide bot, order, and limit state; the guards pause and cancel through an
// injected canceler. Money is always decimal.Decimal (scale 16), never float64.
package risk

import (
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// Bot status values used by the PHP engine.
const (
	StatusIdle    = "idle"
	StatusRunning = "running"
	StatusPaused  = "paused"
	StatusStopped = "stopped"
)

// Bot modes.
const (
	ModePaper = "paper"
	ModeLive  = "live"
)

// Order status values used by the guards.
const (
	OrderPending         = "pending"
	OrderOpen            = "open"
	OrderPartiallyFilled = "partially_filled"
	OrderCanceled        = "canceled"
	OrderFilled          = "filled"
	OrderRejected        = "rejected"
)

// Risk severities.
const (
	SeverityWarning  = "warning"
	SeverityCritical = "critical"
)

// Bot is the in-memory subset of App\Models\Bot the risk gates read or mutate.
type Bot struct {
	ID                  int64
	UserID              int64
	Name                string
	Mode                string
	Status              string
	Exchange            string
	Symbol              string
	CapitalAllocation   decimal.Decimal
	BaseInventory       decimal.Decimal
	QuoteInventory      decimal.Decimal
	AverageEntryPrice   decimal.Decimal
	RealizedPnL         decimal.Decimal
	FeesPaid            decimal.Decimal
	ConsecutiveFailures int
	StopLossPercent     *string
	TakeProfitPercent   *string
	RangeLow            *string
	RangeHigh           *string
	LastError           *string
	Settings            map[string]any
}

// IsLive reports whether the bot trades a real exchange.
func (b *Bot) IsLive() bool {
	return b.Mode == ModeLive
}

// Order is the in-memory subset of App\Models\Order the risk gates read.
type Order struct {
	ID              int64
	BotID           int64
	ClientOrderID   string
	ExchangeOrderID *string
	Side            string
	Type            string
	Status          string
	Mode            string
	Price           decimal.Decimal
	Quantity        decimal.Decimal
	FilledQuantity  decimal.Decimal
}

// IsOpen reports whether the order still consumes risk capacity.
func (o *Order) IsOpen() bool {
	switch o.Status {
	case OrderPending, OrderOpen, OrderPartiallyFilled:
		return true
	default:
		return false
	}
}

// Remaining is quantity minus filled quantity.
func (o *Order) Remaining() decimal.Decimal {
	return o.Quantity.Sub(o.FilledQuantity)
}

// Limit is the in-memory subset of App\Models\RiskLimit.
type Limit struct {
	ID                  int64
	UserID              int64
	BotID               *int64
	Scope               string
	MaxPositionNotional decimal.Decimal
	MaxOpenOrders       int
	DailyLossLimit      decimal.Decimal
	DayStart            time.Time
	DayStartRealizedPnL decimal.Decimal
}

// Event is a recorded risk event or activity log entry.
type Event struct {
	Type     string
	Severity string
	Message  string
	Context  map[string]any
}

// Decision is the RiskGuard verdict for an order.
type Decision struct {
	Allowed  bool
	Type     *string
	Message  *string
	Context  map[string]any
	Severity string
}

// ExitRule identifies a protective exit rule.
type ExitRule string

// Protective exit rules.
const (
	ExitStopLoss   ExitRule = "stop_loss"
	ExitTakeProfit ExitRule = "take_profit"
	ExitRange      ExitRule = "range_exit"
)

// Label is the human label used in pause messages.
func (r ExitRule) Label() string {
	switch r {
	case ExitStopLoss:
		return "Stop-loss"
	case ExitTakeProfit:
		return "Take-profit"
	case ExitRange:
		return "Range exit"
	default:
		return string(r)
	}
}

// RiskEventType is the event type recorded when the rule triggers.
func (r ExitRule) RiskEventType() string {
	switch r {
	case ExitStopLoss:
		return "risk.stop_loss_triggered"
	case ExitTakeProfit:
		return "risk.take_profit_triggered"
	case ExitRange:
		return "risk.range_exit_triggered"
	default:
		return "risk.exit_triggered"
	}
}

// ExitSignal describes a triggered protective exit.
type ExitSignal struct {
	Rule              ExitRule
	MarkPrice         string
	Threshold         string
	AverageEntryPrice string
	BaseInventory     string
	Bound             *string
}

// Label is the rule's display label.
func (s *ExitSignal) Label() string {
	return s.Rule.Label()
}

// RiskEventType is the rule's risk event type.
func (s *ExitSignal) RiskEventType() string {
	return s.Rule.RiskEventType()
}

// Context returns the PHP ExitSignal context payload.
func (s *ExitSignal) Context() map[string]any {
	context := map[string]any{
		"rule":                string(s.Rule),
		"observed_price":      s.MarkPrice,
		"threshold":           s.Threshold,
		"average_entry_price": s.AverageEntryPrice,
		"base_inventory":      s.BaseInventory,
	}

	if s.Bound != nil {
		context["bound"] = *s.Bound
	}

	return context
}
