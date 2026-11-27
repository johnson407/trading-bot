package strategy

import (
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func testDynamicInput() Input {
	return Input{
		Market: MarketSnapshot{Price: decimal.MustParse("100")},
		Config: Config{
			Key: "dynamic-grid",
			Values: map[string]any{
				"center_price":                   "100",
				"levels_per_side":                2,
				"min_spacing":                    "5",
				"max_spacing":                    "20",
				"initial_spacing":                "10",
				"recalculation_interval_seconds": 60,
				"spacing_hysteresis_percent":     "0",
				"order_quantity":                 "1",
			},
		},
		State: NewState(),
	}
}

func TestDynamicGridOuterLevelBuyPlacesSell(t *testing.T) {
	dynamic := NewDynamicGrid()
	input := testDynamicInput()
	initialized, err := dynamic.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	decision, err := dynamic.OnFill(input.WithState(initialized.State), FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("80"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(1),
	})
	if err != nil {
		t.Fatalf("OnFill returned error: %v", err)
	}

	if len(decision.Orders) != 1 {
		t.Fatalf("expected one order, got %+v", decision.Orders)
	}

	order := decision.Orders[0]
	if order.Side != SideSell {
		t.Fatalf("expected a sell at the adjacent higher level, got %s", order.Side)
	}
	if !order.Price.Equals(decimal.MustParse("90")) {
		t.Fatalf("expected price 90, got %s", order.Price)
	}
}

func TestDynamicGridOuterLevelSellPlacesBuy(t *testing.T) {
	dynamic := NewDynamicGrid()
	input := testDynamicInput()
	initialized, err := dynamic.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	decision, err := dynamic.OnFill(input.WithState(initialized.State), FillEvent{
		Side:              SideSell,
		Price:             decimal.MustParse("120"),
		Quantity:          decimal.MustParse("1"),
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
}

func TestDynamicGridCancelDoesNotRearmFilledLevel(t *testing.T) {
	dynamic := NewDynamicGrid()
	input := testDynamicInput()
	initialized, err := dynamic.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	filled, err := dynamic.OnFill(input.WithState(initialized.State), FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("80"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(1),
	})
	if err != nil {
		t.Fatalf("OnFill returned error: %v", err)
	}

	canceled, err := dynamic.OnCancel(input.WithState(filled.State), CancelEvent{
		ClientOrderID:     "client-2",
		GridLevelSequence: intPointer(2),
	})
	if err != nil {
		t.Fatalf("OnCancel returned error: %v", err)
	}

	decision, err := dynamic.OnTick(input.WithState(canceled.State))
	if err != nil {
		t.Fatalf("OnTick returned error: %v", err)
	}

	if len(disarmedLevels(decision.State)) == 0 {
		t.Fatal("expected the filled level to stay disarmed")
	}
	for _, order := range decision.Orders {
		if order.Price.Equals(decimal.MustParse("80")) {
			t.Fatalf("canceled pair must not be replaced at 80: %+v", decision.Orders)
		}
	}
}
