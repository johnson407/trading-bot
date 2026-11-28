package strategy

import (
	"strings"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func testTrailingInput() Input {
	return Input{
		Market: MarketSnapshot{Price: decimal.MustParse("100")},
		Config: Config{
			Key: "trailing-grid",
			Values: map[string]any{
				"center_price":      "100",
				"levels_per_side":   2,
				"spacing_type":      "arithmetic",
				"spacing_value":     "10",
				"order_quantity":    "1",
				"trailing_distance": "10",
			},
		},
		State: NewState(),
	}
}

func TestTrailingInitializeRejectsDynamicSpacing(t *testing.T) {
	input := testTrailingInput()
	input.Config.Values["spacing_type"] = "dynamic"

	_, err := NewTrailingGrid().Initialize(input)
	if err == nil || !strings.Contains(err.Error(), "spacing_type") {
		t.Fatalf("expected spacing_type configuration error, got %v", err)
	}
}

func TestTrailingGridOuterLevelBuyPlacesSell(t *testing.T) {
	trailing := NewTrailingGrid()
	input := testTrailingInput()
	initialized, err := trailing.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	decision, err := trailing.OnFill(input.WithState(initialized.State), FillEvent{
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

func TestTrailingGridOuterLevelSellPlacesBuy(t *testing.T) {
	trailing := NewTrailingGrid()
	input := testTrailingInput()
	initialized, err := trailing.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	decision, err := trailing.OnFill(input.WithState(initialized.State), FillEvent{
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

func TestTrailingGridCancelDoesNotRearmFilledLevel(t *testing.T) {
	trailing := NewTrailingGrid()
	input := testTrailingInput()
	initialized, err := trailing.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	filled, err := trailing.OnFill(input.WithState(initialized.State), FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("80"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(1),
	})
	if err != nil {
		t.Fatalf("OnFill returned error: %v", err)
	}

	canceled, err := trailing.OnCancel(input.WithState(filled.State), CancelEvent{
		ClientOrderID:     "client-2",
		GridLevelSequence: intPointer(2),
	})
	if err != nil {
		t.Fatalf("OnCancel returned error: %v", err)
	}

	decision, err := trailing.OnTick(input.WithState(canceled.State))
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
