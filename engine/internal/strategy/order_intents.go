package strategy

import (
	"sort"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// SortOrderIntents returns a copy of intents ordered nearest to the market
// first. Equal distances are broken by lower price, then by the caller's
// original order. Placement uses this order so a short capital or open-order
// limit funds the levels closest to the market on both sides.
func SortOrderIntents(intents []OrderIntent, market decimal.Decimal) []OrderIntent {
	if len(intents) < 2 || !market.IsPositive() {
		return append([]OrderIntent(nil), intents...)
	}

	sorted := append([]OrderIntent(nil), intents...)

	sort.SliceStable(sorted, func(left, right int) bool {
		leftDistance := sorted[left].Price.Sub(market).Abs()
		rightDistance := sorted[right].Price.Sub(market).Abs()

		if comparison := leftDistance.Compare(rightDistance); comparison != 0 {
			return comparison < 0
		}

		return sorted[left].Price.LessThan(sorted[right].Price)
	})

	return sorted
}
