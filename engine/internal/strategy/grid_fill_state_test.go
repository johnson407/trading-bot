package strategy

import (
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func testGridLevel(sequence int, price string, side Side) GridLevelPlan {
	return GridLevelPlan{
		Sequence: sequence,
		Price:    decimal.MustParse(price),
		Side:     side,
	}
}

func testSimpleInput() Input {
	return Input{
		Config: Config{
			Key: "simple-grid",
			Values: map[string]any{
				"center_price":    "100",
				"levels_per_side": 2,
				"spacing_type":    "arithmetic",
				"spacing_value":   "10",
				"order_quantity":  "1",
			},
		},
		State: NewState(),
	}
}

func TestApplyFillToLevelStateSameSideDoesNotRearm(t *testing.T) {
	state := NewState()
	level80 := testGridLevel(1, "80", SideBuy)

	state = ApplyFillToLevelState(state, FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("80"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(1),
	}, levelPointer(testGridLevel(2, "90", SideBuy)))

	if LevelIsArmed(state, level80) {
		t.Fatal("filled buy at 80 should be disarmed")
	}

	state = ApplyFillToLevelState(state, FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("90"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(2),
	}, levelPointer(testGridLevel(3, "100", SideSell)))

	if LevelIsArmed(state, level80) {
		t.Fatal("a same-side buy at the paired price must not re-arm level 80")
	}

	level90 := testGridLevel(2, "90", SideBuy)
	if LevelIsArmed(state, level90) {
		t.Fatal("filled buy at 90 should be disarmed")
	}
}

func TestApplyFillToLevelStateUsesOrderPriceWhenExecutionGaps(t *testing.T) {
	state := NewState()
	level90 := testGridLevel(6, "90", SideBuy)

	state = ApplyFillToLevelState(state, FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("30"),
		OrderPrice:        decimal.MustParse("90"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(6),
	}, levelPointer(testGridLevel(7, "110", SideSell)))

	if !LevelIsArmed(state, testGridLevel(5, "30", SideBuy)) {
		t.Fatal("the execution price must not be recorded as a filled level")
	}
	if LevelIsArmed(state, level90) {
		t.Fatal("the order price should identify and disarm the filled level")
	}
}

func TestApplyFillToLevelStateOppositeSideRearms(t *testing.T) {
	state := NewState()
	level80 := testGridLevel(1, "80", SideBuy)

	state = ApplyFillToLevelState(state, FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("80"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(1),
	}, levelPointer(testGridLevel(2, "90", SideBuy)))

	if LevelIsArmed(state, level80) {
		t.Fatal("filled buy at 80 should be disarmed")
	}

	state = ApplyFillToLevelState(state, FillEvent{
		Side:              SideSell,
		Price:             decimal.MustParse("90"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(2),
	}, levelPointer(level80))

	if !LevelIsArmed(state, level80) {
		t.Fatal("a sell at the paired price should re-arm level 80")
	}
}

func TestApplyFillToLevelStateEntryWithoutSideStaysDisarmed(t *testing.T) {
	state := NewState().Merge(map[string]any{
		"disarmed_levels": map[string]any{
			"80": map[string]any{"pair": "90"},
		},
	})
	level80 := testGridLevel(1, "80", SideBuy)

	state = ApplyFillToLevelState(state, FillEvent{
		Side:              SideSell,
		Price:             decimal.MustParse("90"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(2),
	}, levelPointer(level80))

	if LevelIsArmed(state, level80) {
		t.Fatal("disarm entries without a side cannot be matched safely and should stay disarmed")
	}
}

func TestOnCancelLeavesPairedLevelDisarmed(t *testing.T) {
	simple := NewSimpleGrid()
	input := testSimpleInput()

	filled, err := simple.OnFill(input, FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("90"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(2),
	})
	if err != nil {
		t.Fatalf("OnFill returned error: %v", err)
	}

	level90 := testGridLevel(2, "90", SideBuy)
	if LevelIsArmed(filled.State, level90) {
		t.Fatal("filled buy at 90 should be disarmed")
	}

	canceled, err := simple.OnCancel(input.WithState(filled.State), CancelEvent{
		ClientOrderID:     "paired-sell",
		GridLevelSequence: intPointer(3),
		Reason:            "canceled",
	})
	if err != nil {
		t.Fatalf("OnCancel returned error: %v", err)
	}

	if LevelIsArmed(canceled.State, level90) {
		t.Fatal("canceling the paired order must not silently re-arm the filled level")
	}
	if orders, err := simple.OnTick(input.WithState(canceled.State)); err != nil {
		t.Fatalf("OnTick returned error: %v", err)
	} else {
		for _, order := range orders.Orders {
			if order.Price.Equals(level90.Price) {
				t.Fatalf("a disarmed level should not receive a replacement order, got %+v", order)
			}
		}
	}
}

func levelPointer(level GridLevelPlan) *GridLevelPlan {
	return &level
}
