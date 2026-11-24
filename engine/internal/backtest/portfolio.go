package backtest

import (
	"fmt"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

// InMemoryPortfolio mirrors App\Domain\Backtest\Engine\InMemoryPortfolio.
type InMemoryPortfolio struct {
	Base         decimal.Decimal
	Quote        decimal.Decimal
	AverageEntry decimal.Decimal
	Realized     decimal.Decimal
	Fees         decimal.Decimal
}

// NewInMemoryPortfolio starts with the configured quote capital.
func NewInMemoryPortfolio(capital decimal.Decimal) *InMemoryPortfolio {
	return &InMemoryPortfolio{
		Base:         decimal.Zero(),
		Quote:        capital,
		AverageEntry: decimal.Zero(),
		Realized:     decimal.Zero(),
		Fees:         decimal.Zero(),
	}
}

// Apply mirrors InMemoryPortfolio::apply.
func (p *InMemoryPortfolio) Apply(fill Fill) error {
	price := fill.Price
	quantity := fill.Quantity
	fee := fill.Fee

	if fill.Side == strategy.SideBuy {
		cost := price.Mul(quantity)
		newBase := p.Base.Add(quantity)
		if newBase.IsZero() {
			p.AverageEntry = decimal.Zero()
		} else {
			average, err := p.Base.Mul(p.AverageEntry).Add(cost).Div(newBase)
			if err != nil {
				return err
			}
			p.AverageEntry = average
		}
		p.Base = newBase
		p.Quote = p.Quote.Sub(cost).Sub(fee)
		p.Realized = p.Realized.Sub(fee)
	} else {
		if quantity.GreaterThan(p.Base) {
			return fmt.Errorf(
				"Backtest cannot sell %s base units; only %s available.",
				quantity.String(),
				p.Base.String(),
			)
		}
		proceeds := price.Mul(quantity)
		realized := price.Sub(p.AverageEntry).Mul(quantity)
		p.Base = p.Base.Sub(quantity)
		if p.Base.IsZero() {
			p.AverageEntry = decimal.Zero()
		}
		p.Quote = p.Quote.Add(proceeds).Sub(fee)
		p.Realized = p.Realized.Add(realized).Sub(fee)
	}
	p.Fees = p.Fees.Add(fee)

	return nil
}

// Equity mirrors InMemoryPortfolio::equity.
func (p *InMemoryPortfolio) Equity(markPrice decimal.Decimal) decimal.Decimal {
	return p.Quote.Add(p.Base.Mul(markPrice))
}

// Unrealized mirrors InMemoryPortfolio::unrealized.
func (p *InMemoryPortfolio) Unrealized(markPrice decimal.Decimal) decimal.Decimal {
	if p.Base.IsZero() {
		return decimal.Zero()
	}

	return markPrice.Sub(p.AverageEntry).Mul(p.Base)
}
