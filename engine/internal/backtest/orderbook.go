package backtest

import (
	"sort"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

// SimulatedOrder mirrors App\Domain\Backtest\ValueObjects\SimulatedOrder.
type SimulatedOrder struct {
	ClientOrderID     string
	Side              strategy.Side
	Price             decimal.Decimal
	Quantity          decimal.Decimal
	GridLevelSequence *int
	PlacedCandleIndex int
	FilledQuantity    decimal.Decimal
	Status            string
}

// Order status values used by the simulated book.
const (
	OrderOpen     = "open"
	OrderFilled   = "filled"
	OrderCanceled = "canceled"
)

// Remaining mirrors SimulatedOrder::remaining.
func (o *SimulatedOrder) Remaining() decimal.Decimal {
	return o.Quantity.Sub(o.FilledQuantity)
}

// IsOpen mirrors SimulatedOrder::isOpen.
func (o *SimulatedOrder) IsOpen() bool {
	return o.Status == OrderOpen
}

// IsEligible mirrors SimulatedOrder::isEligible.
func (o *SimulatedOrder) IsEligible(candleIndex int) bool {
	return o.IsOpen() && o.PlacedCandleIndex < candleIndex
}

// SimulatedOrderBook mirrors App\Domain\Backtest\Engine\SimulatedOrderBook.
type SimulatedOrderBook struct {
	orders           []*SimulatedOrder
	byID             map[string]*SimulatedOrder
	nextOrderID      int
	nextFillSequence int
	quoteCurrency    string
}

// NewSimulatedOrderBook creates an empty book for the quote asset.
func NewSimulatedOrderBook(quoteCurrency string) *SimulatedOrderBook {
	return &SimulatedOrderBook{
		byID:             map[string]*SimulatedOrder{},
		nextOrderID:      1,
		nextFillSequence: 1,
		quoteCurrency:    quoteCurrency,
	}
}

// Place adds an open simulated order.
func (b *SimulatedOrderBook) Place(side strategy.Side, price, quantity decimal.Decimal, gridLevelSequence *int, candleIndex int) *SimulatedOrder {
	order := &SimulatedOrder{
		ClientOrderID:     "backtest-" + itoa(b.nextOrderID),
		Side:              side,
		Price:             price,
		Quantity:          quantity,
		GridLevelSequence: gridLevelSequence,
		PlacedCandleIndex: candleIndex,
		FilledQuantity:    decimal.Zero(),
		Status:            OrderOpen,
	}
	b.nextOrderID++
	b.orders = append(b.orders, order)
	b.byID[order.ClientOrderID] = order

	return order
}

// OpenOrders returns open orders in insertion order.
func (b *SimulatedOrderBook) OpenOrders() []*SimulatedOrder {
	open := make([]*SimulatedOrder, 0, len(b.orders))
	for _, order := range b.orders {
		if order.IsOpen() {
			open = append(open, order)
		}
	}

	return open
}

// OpenOrdersBefore returns open orders placed on an earlier candle.
func (b *SimulatedOrderBook) OpenOrdersBefore(candleIndex int) []*SimulatedOrder {
	eligible := make([]*SimulatedOrder, 0, len(b.orders))
	for _, order := range b.orders {
		if order.IsEligible(candleIndex) {
			eligible = append(eligible, order)
		}
	}

	return eligible
}

// QualifyingOrders returns crossed orders nearest to the close first.
func (b *SimulatedOrderBook) QualifyingOrders(close decimal.Decimal, candleIndex int) []*SimulatedOrder {
	qualifying := make([]*SimulatedOrder, 0, len(b.orders))
	for _, order := range b.OpenOrdersBefore(candleIndex) {
		if order.Side == strategy.SideBuy && close.LessThanOrEqual(order.Price) {
			qualifying = append(qualifying, order)
		}
		if order.Side == strategy.SideSell && close.GreaterThanOrEqual(order.Price) {
			qualifying = append(qualifying, order)
		}
	}

	sort.SliceStable(qualifying, func(left, right int) bool {
		leftDistance := qualifying[left].Price.Sub(close).Abs()
		rightDistance := qualifying[right].Price.Sub(close).Abs()
		if comparison := leftDistance.Compare(rightDistance); comparison != 0 {
			return comparison < 0
		}

		return qualifying[left].Price.Compare(qualifying[right].Price) < 0
	})

	return qualifying
}

// Fill fills the order's remaining quantity and returns the fill. The fill
// price is the better of the order's limit and the candle close, so a market
// that gaps through a limit does not fill at a stale price.
func (b *SimulatedOrderBook) Fill(order *SimulatedOrder, filledAt time.Time, market, feeRate decimal.Decimal) Fill {
	quantity := order.Remaining()
	fillPrice := order.Price
	if order.Side == strategy.SideBuy && market.LessThan(fillPrice) {
		fillPrice = market
	}
	if order.Side == strategy.SideSell && market.GreaterThan(fillPrice) {
		fillPrice = market
	}

	fee := fillPrice.Mul(quantity).Mul(feeRate)
	order.FilledQuantity = order.Quantity
	order.Status = OrderFilled

	fill := Fill{
		Sequence:          b.nextFillSequence,
		Side:              order.Side,
		Price:             fillPrice,
		Quantity:          quantity,
		Fee:               fee,
		FeeCurrency:       b.quoteCurrency,
		FilledAt:          filledAt,
		ClientOrderID:     order.ClientOrderID,
		GridLevelSequence: order.GridLevelSequence,
	}
	b.nextFillSequence++

	return fill
}

// Cancel cancels an open order by client order id.
func (b *SimulatedOrderBook) Cancel(clientOrderID string) *SimulatedOrder {
	order, ok := b.byID[clientOrderID]
	if !ok || !order.IsOpen() {
		return nil
	}
	order.Status = OrderCanceled

	return order
}

// CancelAll cancels every open order in insertion order.
func (b *SimulatedOrderBook) CancelAll() []*SimulatedOrder {
	canceled := b.OpenOrders()
	for _, order := range canceled {
		order.Status = OrderCanceled
	}

	return canceled
}

// HasOpenAtPrice reports an open order at the price.
func (b *SimulatedOrderBook) HasOpenAtPrice(price decimal.Decimal) bool {
	for _, order := range b.OpenOrders() {
		if order.Price.Equals(price) {
			return true
		}
	}

	return false
}

// OpenForSequence returns the open order for a grid sequence.
func (b *SimulatedOrderBook) OpenForSequence(sequence int) *SimulatedOrder {
	for _, order := range b.OpenOrders() {
		if order.GridLevelSequence != nil && *order.GridLevelSequence == sequence {
			return order
		}
	}

	return nil
}

// OpenCount returns the number of open orders.
func (b *SimulatedOrderBook) OpenCount() int {
	return len(b.OpenOrders())
}

// OpenBuyNotional returns the remaining buy notional.
func (b *SimulatedOrderBook) OpenBuyNotional() decimal.Decimal {
	total := decimal.Zero()
	for _, order := range b.OpenOrders() {
		if order.Side == strategy.SideBuy {
			total = total.Add(order.Remaining().Mul(order.Price))
		}
	}

	return total
}

// ReservedSellQuantity returns the remaining sell quantity.
func (b *SimulatedOrderBook) ReservedSellQuantity() decimal.Decimal {
	total := decimal.Zero()
	for _, order := range b.OpenOrders() {
		if order.Side == strategy.SideSell {
			total = total.Add(order.Remaining())
		}
	}

	return total
}

// AllOrders returns every order in insertion order.
func (b *SimulatedOrderBook) AllOrders() []*SimulatedOrder {
	return append([]*SimulatedOrder(nil), b.orders...)
}
