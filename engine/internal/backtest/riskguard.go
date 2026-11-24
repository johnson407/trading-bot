package backtest

import (
	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

// BacktestRiskGuard mirrors App\Domain\Backtest\Engine\BacktestRiskGuard.
type BacktestRiskGuard struct {
	limits  RiskLimits
	feeRate decimal.Decimal
}

// NewBacktestRiskGuard creates the replay risk guard.
func NewBacktestRiskGuard(limits RiskLimits, feeRate decimal.Decimal) *BacktestRiskGuard {
	return &BacktestRiskGuard{limits: limits, feeRate: feeRate}
}

// CheckOrder mirrors BacktestRiskGuard::checkOrder. The reason string is
// returned for tests and logging; the engine only needs the decision.
func (g *BacktestRiskGuard) CheckOrder(
	side strategy.Side,
	price decimal.Decimal,
	quantity decimal.Decimal,
	portfolio *InMemoryPortfolio,
	book *SimulatedOrderBook,
) (bool, string) {
	if !price.IsPositive() || !quantity.IsPositive() {
		return false, "risk.invalid_order"
	}

	notional := price.Mul(quantity)
	fee := notional.Mul(g.feeRate)

	if side == strategy.SideBuy {
		committed := book.OpenBuyNotional()
		committedWithFees := committed.Add(committed.Mul(g.feeRate))
		available := portfolio.Quote.Sub(committedWithFees)
		if notional.Add(fee).GreaterThan(available) {
			return false, "risk.insufficient_capital"
		}
	}

	if side == strategy.SideSell {
		reserved := book.ReservedSellQuantity()
		available := portfolio.Base.Sub(reserved)
		if quantity.GreaterThan(available) {
			return false, "risk.insufficient_inventory"
		}
	}

	positionNotional := portfolio.Base.Abs().Mul(price).Add(book.OpenBuyNotional())
	projected := positionNotional.Add(notional)
	if side != strategy.SideBuy {
		projected = positionNotional.Sub(notional)
		if projected.IsNegative() {
			projected = decimal.Zero()
		}
	}
	if projected.GreaterThan(g.limits.MaxPositionNotional) {
		return false, "risk.position_limit"
	}

	if book.OpenCount()+1 > g.limits.MaxOpenOrders {
		return false, "risk.open_order_limit"
	}

	return true, ""
}

// DailyLoss mirrors BacktestRiskGuard::dailyLoss.
func (g *BacktestRiskGuard) DailyLoss(realized, unrealized, dayStartRealized decimal.Decimal) decimal.Decimal {
	pnl := realized.Sub(dayStartRealized).Add(unrealized)
	if pnl.IsNegative() {
		return pnl.Negate()
	}

	return decimal.Zero()
}

// DailyLossLimit returns the configured limit.
func (g *BacktestRiskGuard) DailyLossLimit() decimal.Decimal {
	return g.limits.DailyLossLimit
}
