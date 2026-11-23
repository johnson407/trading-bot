// Package paperfill ports PaperExchangeClient::qualifyingFills. Given the
// market price observed by one tick, it selects the open or partially filled
// limit orders crossed by that price and returns the fills the PHP paper
// exchange would produce. It is a pure library: it never touches a database,
// an exchange, or the PHP runtime.
//
// Fills are evaluated only at tick time. An intra-tick print that crosses a
// limit and then returns before the next tick is not visible here and does not
// fill the order, matching the current PHP pipeline.
package paperfill

import (
	"sort"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// Side mirrors App\Domain\Order\Enums\OrderSide.
type Side string

const (
	SideBuy  Side = "buy"
	SideSell Side = "sell"
)

// OrderStatus mirrors App\Domain\Order\Enums\OrderStatus. Only Open and
// PartiallyFilled orders can produce paper fills.
type OrderStatus string

const (
	StatusPending         OrderStatus = "pending"
	StatusOpen            OrderStatus = "open"
	StatusPartiallyFilled OrderStatus = "partially_filled"
	StatusFilled          OrderStatus = "filled"
	StatusCanceled        OrderStatus = "canceled"
)

// Order is the subset of a persisted limit order needed for paper fills.
type Order struct {
	ClientOrderID  string
	Side           Side
	Price          decimal.Decimal
	Quantity       decimal.Decimal
	FilledQuantity decimal.Decimal
	Status         OrderStatus
}

// Fill mirrors App\Domain\Exchange\ValueObjects\PaperFill.
type Fill struct {
	ClientOrderID string
	Side          Side
	Price         decimal.Decimal
	Quantity      decimal.Decimal
	Fee           decimal.Decimal
	FeeCurrency   string
}

// QualifyingFills returns paper fills for the open or partially filled orders
// crossed by marketLast. A buy fills when marketLast <= limit and a sell fills
// when marketLast >= limit. Orders closest to the market fill first; an equal
// distance is broken by the lower price, and an exact tie keeps the caller's
// order (PHP 8 usort is stable).
//
// feeRate accepts the same values as PHP's Decimal::of: a decimal string or an
// integer/decimal value. Floats are rejected. The fee is price * remaining
// quantity * feeRate, and the currency is always USDT.
func QualifyingFills(marketLast decimal.Decimal, orders []Order, feeRate any) ([]Fill, error) {
	rate, err := decimal.Of(feeRate)
	if err != nil {
		return nil, err
	}

	qualifying := make([]Order, 0, len(orders))

	for _, order := range orders {
		if order.Status != StatusOpen && order.Status != StatusPartiallyFilled {
			continue
		}

		// PHP treats any non-buy side as a sell. Only buy and sell exist today,
		// but keep the same branch shape so an unknown side is not silently
		// skipped.
		crossed := marketLast.GreaterThanOrEqual(order.Price)
		if order.Side == SideBuy {
			crossed = marketLast.LessThanOrEqual(order.Price)
		}

		if !crossed {
			continue
		}

		qualifying = append(qualifying, order)
	}

	sort.SliceStable(qualifying, func(left, right int) bool {
		distanceLeft := qualifying[left].Price.Sub(marketLast).Abs()
		distanceRight := qualifying[right].Price.Sub(marketLast).Abs()

		if comparison := distanceLeft.Compare(distanceRight); comparison != 0 {
			return comparison < 0
		}

		return qualifying[left].Price.LessThan(qualifying[right].Price)
	})

	fills := make([]Fill, 0, len(qualifying))

	for _, order := range qualifying {
		quantity := order.Quantity.Sub(order.FilledQuantity)
		fee := order.Price.Mul(quantity).Mul(rate)

		fills = append(fills, Fill{
			ClientOrderID: order.ClientOrderID,
			Side:          order.Side,
			Price:         order.Price,
			Quantity:      quantity,
			Fee:           fee,
			FeeCurrency:   "USDT",
		})
	}

	return fills, nil
}
