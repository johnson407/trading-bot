package strategy

import (
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func testSimpleInputWithLevels(perSide int) Input {
	input := testSimpleInput()
	input.Config.Values["levels_per_side"] = perSide

	return input
}

func TestSimpleGridOuterLevelBuyPlacesSell(t *testing.T) {
	simple := NewSimpleGrid()
	input := testSimpleInput()
	initialized, err := simple.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	decision, err := simple.OnFill(input.WithState(initialized.State), FillEvent{
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
	if order.GridLevelSequence == nil || *order.GridLevelSequence != 2 {
		t.Fatalf("expected grid sequence 2, got %+v", order.GridLevelSequence)
	}
}

func TestSimpleGridOuterLevelSellPlacesBuy(t *testing.T) {
	simple := NewSimpleGrid()
	input := testSimpleInput()
	initialized, err := simple.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	decision, err := simple.OnFill(input.WithState(initialized.State), FillEvent{
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
	if order.GridLevelSequence == nil || *order.GridLevelSequence != 3 {
		t.Fatalf("expected grid sequence 3, got %+v", order.GridLevelSequence)
	}
}

func TestSimpleGridSixBuyGapPlacesSixSells(t *testing.T) {
	simple := NewSimpleGrid()
	input := testSimpleInputWithLevels(6)

	initialized, err := simple.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	state := initialized.State
	var sells []OrderIntent
	for _, price := range []string{"40", "50", "60", "70", "80", "90"} {
		fillPrice := decimal.MustParse(price)
		sequence := 0
		for _, level := range initialized.Levels {
			if level.Price.Equals(fillPrice) {
				sequence = level.Sequence
				break
			}
		}
		if sequence == 0 {
			t.Fatalf("level %s not found", price)
		}

		decision, err := simple.OnFill(input.WithState(state), FillEvent{
			Side:              SideBuy,
			Price:             fillPrice,
			Quantity:          decimal.MustParse("1"),
			GridLevelSequence: intPointer(sequence),
		})
		if err != nil {
			t.Fatalf("OnFill at %s returned error: %v", price, err)
		}
		sells = append(sells, decision.Orders...)
		state = decision.State
	}

	expectedPrices := []string{"50", "60", "70", "80", "90", "110"}
	if len(sells) != len(expectedPrices) {
		t.Fatalf("expected six sell orders, got %+v", sells)
	}
	for index, order := range sells {
		if order.Side != SideSell {
			t.Fatalf("order %d expected sell, got %s", index, order.Side)
		}
		if !order.Price.Equals(decimal.MustParse(expectedPrices[index])) {
			t.Fatalf("order %d expected price %s, got %s", index, expectedPrices[index], order.Price)
		}
	}

	for _, price := range []string{"40", "50", "60", "70", "80", "90"} {
		level := testGridLevel(0, price, SideBuy)
		if LevelIsArmed(state, level) {
			t.Fatalf("buy level %s should stay disarmed", price)
		}
	}

	tick, err := simple.OnTick(input.WithState(state))
	if err != nil {
		t.Fatalf("OnTick returned error: %v", err)
	}
	for _, order := range tick.Orders {
		if order.Side == SideBuy && order.Price.GreaterThanOrEqual(decimal.MustParse("40")) && order.Price.LessThanOrEqual(decimal.MustParse("90")) {
			t.Fatalf("next tick must not place a buy above the market: %+v", order)
		}
	}
}
