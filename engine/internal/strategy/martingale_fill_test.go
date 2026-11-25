package strategy

import (
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func testMartingaleInput() Input {
	return Input{
		Market: MarketSnapshot{Price: decimal.MustParse("100")},
		Config: Config{
			Key: "martingale-grid",
			Values: map[string]any{
				"center_price":        "100",
				"levels_per_side":     2,
				"spacing_type":        "arithmetic",
				"spacing_value":       "10",
				"base_order_quantity": "1",
				"size_multiplier":     "2",
				"max_order_quantity":  "10",
				"max_increases":       10,
			},
		},
		State: NewState(),
	}
}

func TestMartingaleGridBuyPlacesSellForFilledQuantity(t *testing.T) {
	martingale := NewMartingaleGrid()
	input := testMartingaleInput()
	initialized, err := martingale.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	first, err := martingale.OnFill(input.WithState(initialized.State), FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("80"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(1),
	})
	if err != nil {
		t.Fatalf("first OnFill returned error: %v", err)
	}

	if len(first.Orders) != 1 {
		t.Fatalf("expected one order, got %+v", first.Orders)
	}
	if first.Orders[0].Side != SideSell {
		t.Fatalf("expected a sell at the adjacent higher level, got %s", first.Orders[0].Side)
	}
	if !first.Orders[0].Price.Equals(decimal.MustParse("90")) {
		t.Fatalf("expected price 90, got %s", first.Orders[0].Price)
	}
	if !first.Orders[0].Quantity.Equals(decimal.MustParse("1")) {
		t.Fatalf("take-profit quantity must be the filled quantity 1, got %s", first.Orders[0].Quantity)
	}
	if got := first.State.Get("current_quantity", nil); got != "2" {
		t.Fatalf("expected current_quantity 2, got %v", got)
	}

	second, err := martingale.OnFill(input.WithState(first.State), FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("90"),
		Quantity:          decimal.MustParse("2"),
		GridLevelSequence: intPointer(2),
	})
	if err != nil {
		t.Fatalf("second OnFill returned error: %v", err)
	}

	if len(second.Orders) != 1 {
		t.Fatalf("expected one order, got %+v", second.Orders)
	}
	if second.Orders[0].Side != SideSell {
		t.Fatalf("expected a sell at the adjacent higher level, got %s", second.Orders[0].Side)
	}
	if !second.Orders[0].Price.Equals(decimal.MustParse("110")) {
		t.Fatalf("expected price 110, got %s", second.Orders[0].Price)
	}
	if !second.Orders[0].Quantity.Equals(decimal.MustParse("2")) {
		t.Fatalf("take-profit quantity must be the filled quantity 2, got %s", second.Orders[0].Quantity)
	}
	if got := second.State.Get("current_quantity", nil); got != "4" {
		t.Fatalf("expected current_quantity 4, got %v", got)
	}
}

func TestMartingaleGridSellPlacesBuyUsingNextSize(t *testing.T) {
	martingale := NewMartingaleGrid()
	input := testMartingaleInput()
	initialized, err := martingale.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	state := initialized.State.Merge(map[string]any{
		"current_quantity": "2",
		"increase_count":   1,
	})

	decision, err := martingale.OnFill(input.WithState(state), FillEvent{
		Side:              SideSell,
		Price:             decimal.MustParse("120"),
		Quantity:          decimal.MustParse("2"),
		GridLevelSequence: intPointer(4),
	})
	if err != nil {
		t.Fatalf("OnFill returned error: %v", err)
	}

	if len(decision.Orders) != 1 {
		t.Fatalf("expected one order, got %+v", decision.Orders)
	}
	order := decision.Orders[0]
	if order.Side != SideBuy {
		t.Fatalf("expected a buy at the adjacent lower level, got %s", order.Side)
	}
	if !order.Price.Equals(decimal.MustParse("110")) {
		t.Fatalf("expected price 110, got %s", order.Price)
	}
	if !order.Quantity.Equals(decimal.MustParse("4")) {
		t.Fatalf("entry after a sell must use the increased next size 4, got %s", order.Quantity)
	}
	if got := decision.State.Get("current_quantity", nil); got != "4" {
		t.Fatalf("expected current_quantity 4, got %v", got)
	}
}
