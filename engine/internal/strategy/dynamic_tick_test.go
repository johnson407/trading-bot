package strategy

import (
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func dynamicTickTestInput(state State) Input {
	marketAt := time.Date(2026, time.January, 1, 0, 30, 0, 0, time.UTC)

	return Input{
		Market: MarketSnapshot{
			Price:     decimal.MustParse("100"),
			Timestamp: &marketAt,
		},
		Config: Config{
			Key: "dynamic-grid",
			Values: map[string]any{
				"center_price":                   "100",
				"levels_per_side":                2,
				"min_spacing":                    "10",
				"max_spacing":                    "10",
				"initial_spacing":                "10",
				"recalculation_interval_seconds": 3600,
				"spacing_hysteresis_percent":     0,
				"order_quantity":                 "1",
			},
		},
		State: state,
	}
}

func dynamicTickTestState() State {
	return NewState().Merge(map[string]any{
		"initialized":          true,
		"center_price":         "100",
		"spacing_value":        "10",
		"levels_per_side":      2,
		"last_recalculated_at": "2026-01-01T00:00:00+00:00",
		"recalculation_count":  0,
		"fill_count":           0,
	})
}

func TestDynamicGridIgnoresDynamicSpacingType(t *testing.T) {
	input := dynamicTickTestInput(NewState())
	input.Config.Values["spacing_type"] = "dynamic"

	decision, err := NewDynamicGrid().Initialize(input)
	if err != nil {
		t.Fatalf("initialize: %v", err)
	}

	if len(decision.Levels) != 4 {
		t.Fatalf("levels = %d, want 4 from min/max spacing", len(decision.Levels))
	}
	if got := decision.State.Get("spacing_value", nil); got != "10" {
		t.Fatalf("spacing_value = %v, want 10", got)
	}
}

func TestDynamicRecalculationPrunesStaleDisarms(t *testing.T) {
	input := dynamicTickTestInput(NewState())
	input.Config.Values["recalculation_interval_seconds"] = 0
	input.Config.Values["min_spacing"] = "10"
	input.Config.Values["max_spacing"] = "20"
	input.Market.RecentPrices = []decimal.Decimal{decimal.MustParse("80"), decimal.MustParse("120")}
	state := dynamicTickTestState().Merge(map[string]any{
		"disarmed_levels": map[string]any{
			"70": map[string]any{"side": "buy", "pair": "75"},
		},
	})
	input.State = state

	decision, err := NewDynamicGrid().OnTick(input)
	if err != nil {
		t.Fatalf("OnTick returned error: %v", err)
	}

	if _, present := disarmedLevels(decision.State)["70"]; present {
		t.Fatal("expected the stale disarm to be pruned on recalculation")
	}
}

func TestDynamicGridNonDueTickReplenishesOrders(t *testing.T) {
	tests := []struct {
		name     string
		open     []OpenOrderSnapshot
		disarmed map[string]any
		want     []string
	}{
		{
			name: "missing orders are replenished",
			want: []string{"80", "90", "110", "120"},
		},
		{
			name: "levels with open orders are skipped",
			open: []OpenOrderSnapshot{{
				ClientOrderID: "order-at-110",
				Side:          SideSell,
				Price:         decimal.MustParse("110"),
				Quantity:      decimal.MustParse("1"),
			}},
			want: []string{"80", "90", "120"},
		},
		{
			name: "disarmed levels stay skipped",
			disarmed: map[string]any{
				"90": map[string]any{"side": "buy", "pair": "110"},
			},
			want: []string{"80", "110", "120"},
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			state := dynamicTickTestState()
			if test.disarmed != nil {
				state = state.Merge(map[string]any{"disarmed_levels": test.disarmed})
			}

			input := dynamicTickTestInput(state)
			input.Bot.OpenOrders = test.open

			decision, err := NewDynamicGrid().OnTick(input)
			if err != nil {
				t.Fatalf("OnTick returned error: %v", err)
			}

			if got := decision.State.Get("spacing_value", nil); got != "10" {
				t.Fatalf("spacing_value = %v, want 10", got)
			}

			if got := decision.State.Get("recalculation_count", nil); got != 0 {
				t.Fatalf("recalculation_count = %v, want 0", got)
			}

			if got := decision.State.Get("last_recalculated_at", nil); got != "2026-01-01T00:00:00+00:00" {
				t.Fatalf("last_recalculated_at = %v, want unchanged", got)
			}

			if len(decision.Levels) != 4 {
				t.Fatalf("levels = %+v, want four levels", decision.Levels)
			}

			if len(decision.Orders) != len(test.want) {
				t.Fatalf("orders = %+v, want %d orders", decision.Orders, len(test.want))
			}

			for index, price := range test.want {
				if !decision.Orders[index].Price.Equals(decimal.MustParse(price)) {
					t.Fatalf("orders = %+v, want prices %+v", decision.Orders, test.want)
				}
			}

			if len(decision.CancelClientOrderIDs) != 0 {
				t.Fatalf("cancels = %+v, want none", decision.CancelClientOrderIDs)
			}
		})
	}
}
