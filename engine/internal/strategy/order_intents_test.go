package strategy_test

import (
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

func TestSortOrderIntentsNearestFirstOnBothSides(t *testing.T) {
	intents := []strategy.OrderIntent{
		{Price: decimal.MustParse("70"), Side: strategy.SideBuy},
		{Price: decimal.MustParse("130"), Side: strategy.SideSell},
		{Price: decimal.MustParse("90"), Side: strategy.SideBuy},
		{Price: decimal.MustParse("110"), Side: strategy.SideSell},
	}

	sorted := strategy.SortOrderIntents(intents, decimal.MustParse("100"))

	want := []string{"90", "110", "70", "130"}
	for index, price := range want {
		if !sorted[index].Price.Equals(decimal.MustParse(price)) {
			t.Fatalf("intent %d price = %s, want %s", index, sorted[index].Price.String(), price)
		}
	}

	// The helper must not mutate the caller's slice; runtime may reuse it.
	if !intents[0].Price.Equals(decimal.MustParse("70")) {
		t.Fatalf("input was mutated: %s", intents[0].Price.String())
	}
}

func TestSortOrderIntentsBreaksTiesByLowerPrice(t *testing.T) {
	intents := []strategy.OrderIntent{
		{Price: decimal.MustParse("110"), Side: strategy.SideSell},
		{Price: decimal.MustParse("90"), Side: strategy.SideBuy},
	}

	sorted := strategy.SortOrderIntents(intents, decimal.MustParse("100"))

	if !sorted[0].Price.Equals(decimal.MustParse("90")) || !sorted[1].Price.Equals(decimal.MustParse("110")) {
		t.Fatalf("tie order = %s, %s, want 90, 110", sorted[0].Price.String(), sorted[1].Price.String())
	}
}
