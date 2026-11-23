package risk

import (
	"strings"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// RiskGuard ports App\Domain\Risk\RiskGuard: exposure, open-order, and
// inventory reservation checks that block an order before it is placed.
type RiskGuard struct {
	Store     *Store
	Allowlist *ExchangeAllowlist
}

// NewRiskGuard builds a guard over the in-memory store.
func NewRiskGuard(store *Store) *RiskGuard {
	return &RiskGuard{Store: store}
}

// Limits returns the effective limits without creating a limit row.
func (g *RiskGuard) Limits(bot *Bot) (maxPositionNotional decimal.Decimal, maxOpenOrders int, dailyLossLimit decimal.Decimal) {
	return g.Store.EffectiveRiskLimits(bot)
}

// CheckOrder returns the blocking decision for a proposed order.
func (g *RiskGuard) CheckOrder(bot *Bot, side, price, quantity string) Decision {
	if g.Allowlist != nil {
		if err := g.Allowlist.AssertPlace(bot); err != nil {
			message := err.Error()
			return g.reject(bot, Decision{
				Type:     stringPointer("risk.exchange_not_allowed"),
				Message:  &message,
				Context:  map[string]any{"exchange": bot.Exchange},
				Severity: SeverityCritical,
			})
		}
	}

	priceValue, priceErr := decimal.Parse(price)
	quantityValue, quantityErr := decimal.Parse(quantity)

	if priceErr != nil || quantityErr != nil || !priceValue.IsPositive() || !quantityValue.IsPositive() {
		context := map[string]any{
			"price":    price,
			"quantity": quantity,
		}
		if priceErr == nil {
			context["price"] = priceValue.String()
		}
		if quantityErr == nil {
			context["quantity"] = quantityValue.String()
		}

		return g.reject(bot, Decision{
			Type:     stringPointer("risk.invalid_order"),
			Message:  stringPointer("Order price and quantity must be positive."),
			Context:  context,
			Severity: SeverityCritical,
		})
	}

	maxPositionNotional, maxOpenOrders, _ := g.Limits(bot)
	notional := priceValue.Mul(quantityValue)
	base := bot.BaseInventory

	openOrders := g.Store.OpenOrdersForBot(bot.ID)
	openBuyNotional := decimal.Zero()
	reservedSellQuantity := decimal.Zero()

	for _, openOrder := range openOrders {
		remaining := openOrder.Remaining()
		if !remaining.IsPositive() {
			continue
		}

		if openOrder.Side == "buy" {
			openBuyNotional = openBuyNotional.Add(remaining.Mul(openOrder.Price))
		} else {
			reservedSellQuantity = reservedSellQuantity.Add(remaining)
		}
	}

	if side == "sell" {
		available := base.Sub(reservedSellQuantity)

		if quantityValue.GreaterThan(available) {
			// Waiting for a buy to fund the sell side is an expected state,
			// not a breach, so it is reported without a per-tick risk event.
			return Decision{
				Type:    stringPointer("risk.insufficient_inventory"),
				Message: stringPointer("Sell quantity exceeds the uncommitted base inventory held by the bot."),
				Context: map[string]any{
					"base_inventory":         base.String(),
					"reserved_sell_quantity": reservedSellQuantity.String(),
					"available":              available.String(),
					"quantity":               quantityValue.String(),
				},
				Severity: SeverityWarning,
			}
		}
	}

	positionNotional := base.Abs().Mul(priceValue).Add(openBuyNotional)
	projected := positionNotional.Add(notional)
	if side != "buy" {
		projected = maxDecimal(positionNotional.Sub(notional), decimal.Zero())
	}

	if projected.GreaterThan(maxPositionNotional) {
		return g.reject(bot, Decision{
			Type:    stringPointer("risk.position_limit"),
			Message: stringPointer("Order would exceed the maximum position notional."),
			Context: map[string]any{
				"projected_notional":    projected.String(),
				"max_position_notional": maxPositionNotional.String(),
				"side":                  side,
				"order_notional":        notional.String(),
			},
			Severity: SeverityCritical,
		})
	}

	if len(openOrders)+1 > maxOpenOrders {
		return g.reject(bot, Decision{
			Type:    stringPointer("risk.open_order_limit"),
			Message: stringPointer("Order would exceed the maximum number of open orders."),
			Context: map[string]any{
				"open_orders":     len(openOrders),
				"max_open_orders": maxOpenOrders,
			},
			Severity: SeverityWarning,
		})
	}

	return Decision{Allowed: true, Context: map[string]any{}, Severity: SeverityWarning}
}

func (g *RiskGuard) reject(bot *Bot, decision Decision) Decision {
	message := ""
	if decision.Message != nil {
		message = *decision.Message
	}
	eventType := ""
	if decision.Type != nil {
		eventType = *decision.Type
	}

	g.Store.RecordRisk(bot, eventType, decision.Severity, message, decision.Context)

	return decision
}

func stringPointer(value string) *string {
	return &value
}

// NormalizeSide lowercases the side for comparisons; the engine only knows buy
// and sell.
func NormalizeSide(side string) string {
	return strings.ToLower(strings.TrimSpace(side))
}
