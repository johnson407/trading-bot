package runtime

import (
	"context"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

func newDynamicGridInput() strategy.Input {
	return strategy.Input{
		Market: strategy.MarketSnapshot{Price: decimal.MustParse("100")},
		Config: strategy.Config{
			Key: "dynamic-grid",
			Values: map[string]any{
				"center_price":                   "100",
				"levels_per_side":                2,
				"min_spacing":                    "5",
				"max_spacing":                    "20",
				"initial_spacing":                "10",
				"recalculation_interval_seconds": 60,
				"order_quantity":                 "1",
			},
		},
		State: strategy.NewState(),
	}
}

func TestDecideNewDynamicGridEmitsInitialOrders(t *testing.T) {
	decision, err := (&Runtime{}).decide(context.Background(), strategy.NewDynamicGrid(), newDynamicGridInput())
	if err != nil {
		t.Fatalf("decide returned error: %v", err)
	}

	if decision.State.IsNew() {
		t.Fatal("expected the strategy state to be initialized")
	}

	if len(decision.Levels) != 4 {
		t.Fatalf("levels = %d, want 4", len(decision.Levels))
	}

	if len(decision.Orders) != 4 {
		t.Fatalf("orders = %d, want 4 initial orders: %+v", len(decision.Orders), decision.Orders)
	}

	ordersByPrice := make(map[string]strategy.OrderIntent, len(decision.Orders))
	for _, order := range decision.Orders {
		ordersByPrice[order.Price.String()] = order
	}

	want := []struct {
		side  strategy.Side
		price string
	}{
		{strategy.SideBuy, "80"},
		{strategy.SideBuy, "90"},
		{strategy.SideSell, "110"},
		{strategy.SideSell, "120"},
	}

	for _, expected := range want {
		order, ok := ordersByPrice[expected.price]
		if !ok {
			t.Fatalf("missing initial order at %s", expected.price)
		}
		if order.Side != expected.side {
			t.Fatalf("order at %s side = %s, want %s", expected.price, order.Side, expected.side)
		}
		if !order.Quantity.Equals(decimal.MustParse("1")) {
			t.Fatalf("order at %s quantity = %s, want 1", expected.price, order.Quantity)
		}
	}
}

func TestDecideGridReplacementReplenishesSharedPrices(t *testing.T) {
	now := time.Now().UTC()
	input := newDynamicGridInput()
	input.Market = strategy.MarketSnapshot{
		Price:        decimal.MustParse("100"),
		RecentPrices: []decimal.Decimal{decimal.MustParse("90"), decimal.MustParse("110")},
		Timestamp:    &now,
	}
	input.State = strategy.NewState().Merge(map[string]any{
		"initialized":          true,
		"center_price":         "100",
		"spacing_value":        "10",
		"levels_per_side":      2,
		"last_recalculated_at": now.Add(-2 * time.Hour).Format(time.RFC3339),
		"recalculation_count":  3,
		"fill_count":           0,
	})
	input.Bot = strategy.BotSnapshot{
		OpenOrders: []strategy.OpenOrderSnapshot{
			openOrderAt(strategy.SideBuy, "80"),
			openOrderAt(strategy.SideBuy, "90"),
			openOrderAt(strategy.SideSell, "110"),
			openOrderAt(strategy.SideSell, "120"),
		},
	}

	first, err := (&Runtime{}).decide(context.Background(), strategy.NewDynamicGrid(), input)
	if err != nil {
		t.Fatalf("first decide returned error: %v", err)
	}

	if len(first.CancelClientOrderIDs) != 2 {
		t.Fatalf("cancels = %v, want the two prices dropped by the new ladder", first.CancelClientOrderIDs)
	}
	if hasOrderAt(first.Orders, "80") || hasOrderAt(first.Orders, "120") {
		t.Fatalf(
			"shared levels should be skipped while their old orders are still open: %+v",
			first.Orders,
		)
	}
	for _, price := range []string{"60", "140"} {
		if !hasOrderAt(first.Orders, price) {
			t.Fatalf("missing new-ladder order at %s: %+v", price, first.Orders)
		}
	}

	// The runtime cancels every order during a grid replacement, then evaluates
	// once more with the refreshed, empty order book before placing orders.
	secondInput := input
	secondInput.State = first.State
	secondInput.Bot = strategy.BotSnapshot{}

	second, err := (&Runtime{}).decide(context.Background(), strategy.NewDynamicGrid(), secondInput)
	if err != nil {
		t.Fatalf("second decide returned error: %v", err)
	}

	for _, price := range []string{"60", "80", "120", "140"} {
		if !hasOrderAt(second.Orders, price) {
			t.Fatalf("missing replacement order at shared or new price %s: %+v", price, second.Orders)
		}
	}
}

func openOrderAt(side strategy.Side, price string) strategy.OpenOrderSnapshot {
	return strategy.OpenOrderSnapshot{
		ClientOrderID: "order-" + price,
		Side:          side,
		Price:         decimal.MustParse(price),
		Quantity:      decimal.MustParse("1"),
	}
}

func hasOrderAt(orders []strategy.OrderIntent, price string) bool {
	for _, order := range orders {
		if order.Price.String() == price {
			return true
		}
	}

	return false
}

func TestDecideInitializedStateIsNotReinitialized(t *testing.T) {
	input := newDynamicGridInput()
	input.State = strategy.NewState().Merge(map[string]any{
		"initialized":          true,
		"center_price":         "100",
		"spacing_value":        "10",
		"levels_per_side":      2,
		"last_recalculated_at": time.Now().UTC().Format(time.RFC3339),
		"recalculation_count":  0,
		"fill_count":           0,
		"marker":               "keep",
	})

	decision, err := (&Runtime{}).decide(context.Background(), strategy.NewDynamicGrid(), input)
	if err != nil {
		t.Fatalf("decide returned error: %v", err)
	}

	if got := decision.State.Get("marker", nil); got != "keep" {
		t.Fatalf("marker = %v, want keep; state was reinitialized", got)
	}
	if got := decision.State.Get("recalculation_count", nil); got != 0 {
		t.Fatalf("recalculation_count = %v, want 0", got)
	}
	if len(decision.Orders) != 4 {
		t.Fatalf("orders = %d, want the four missing orders restored before the recalculation interval elapses: %+v", len(decision.Orders), decision.Orders)
	}

	wantPrices := map[string]bool{"80": true, "90": true, "110": true, "120": true}
	for _, order := range decision.Orders {
		delete(wantPrices, order.Price.String())
	}
	if len(wantPrices) != 0 {
		t.Fatalf("orders = %+v, missing prices %+v", decision.Orders, wantPrices)
	}
}
