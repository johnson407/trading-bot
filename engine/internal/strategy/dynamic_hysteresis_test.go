package strategy

import (
	"strings"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func hysteresisTestInput(state State, candidate, hysteresis string) Input {
	values := map[string]any{
		"center_price":                   "100",
		"levels_per_side":                2,
		"min_spacing":                    candidate,
		"max_spacing":                    candidate,
		"initial_spacing":                candidate,
		"recalculation_interval_seconds": 0,
		"order_quantity":                 "1",
	}

	if hysteresis != "" {
		values["spacing_hysteresis_percent"] = hysteresis
	}

	marketAt := time.Date(2026, time.January, 1, 0, 1, 5, 0, time.UTC)

	return Input{
		Market: MarketSnapshot{
			Price:     decimal.MustParse("100"),
			Timestamp: &marketAt,
		},
		Config: Config{Key: "dynamic-grid", Values: values},
		State:  state,
		Bot: BotSnapshot{
			OpenOrders: []OpenOrderSnapshot{{
				ClientOrderID: "order-at-old-level",
				Side:          SideBuy,
				Price:         decimal.MustParse("110"),
				Quantity:      decimal.MustParse("1"),
			}},
		},
	}
}

func hysteresisTestState(spacing string) State {
	return NewState().Merge(map[string]any{
		"initialized":          true,
		"center_price":         "100",
		"spacing_value":        spacing,
		"levels_per_side":      2,
		"last_recalculated_at": "2026-01-01T00:00:00+00:00",
		"recalculation_count":  0,
		"fill_count":           0,
	})
}

func TestDynamicGridHysteresisRetainsOrAdoptsSpacing(t *testing.T) {
	tests := []struct {
		name        string
		candidate   string
		hysteresis  string
		wantSpacing string
		wantSkip    bool
	}{
		{name: "below default threshold retains current", candidate: "11.9", hysteresis: "", wantSpacing: "10", wantSkip: true},
		{name: "at default threshold adopts", candidate: "12", hysteresis: "", wantSpacing: "12"},
		{name: "at threshold adopts", candidate: "12", hysteresis: "20", wantSpacing: "12"},
		{name: "above threshold adopts", candidate: "12.1", hysteresis: "20", wantSpacing: "12.1"},
		{name: "fractional below threshold retains current", candidate: "11.5", hysteresis: "15.1", wantSpacing: "10", wantSkip: true},
		{name: "fractional at threshold adopts", candidate: "11.5", hysteresis: "15", wantSpacing: "11.5"},
		{name: "zero disables hysteresis", candidate: "11.9", hysteresis: "0", wantSpacing: "11.9"},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			dynamic := NewDynamicGrid()
			decision, err := dynamic.OnTick(hysteresisTestInput(hysteresisTestState("10"), test.candidate, test.hysteresis))
			if err != nil {
				t.Fatalf("OnTick returned error: %v", err)
			}

			if got := decision.State.Get("spacing_value", nil); got != test.wantSpacing {
				t.Fatalf("spacing_value = %v, want %s", got, test.wantSpacing)
			}

			if got := decision.State.Get("recalculation_count", nil); got != 1 {
				t.Fatalf("recalculation_count = %v, want 1", got)
			}

			if got := decision.State.Get("last_recalculated_at", nil); got != "2026-01-01T00:01:05+00:00" {
				t.Fatalf("last_recalculated_at = %v", got)
			}

			expected, err := dynamic.levelsFor(hysteresisTestInput(NewState(), test.wantSpacing, test.hysteresis), decimal.MustParse("100"), decimal.MustParse(test.wantSpacing))
			if err != nil {
				t.Fatalf("expected levels: %v", err)
			}

			if len(decision.Levels) != len(expected) {
				t.Fatalf("levels = %+v, want %+v", decision.Levels, expected)
			}

			for index := range expected {
				if decision.Levels[index].Sequence != expected[index].Sequence ||
					decision.Levels[index].Side != expected[index].Side ||
					!decision.Levels[index].Price.Equals(expected[index].Price) {
					t.Fatalf("levels = %+v, want %+v", decision.Levels, expected)
				}
			}

			if test.wantSkip {
				if len(decision.Orders) != 3 {
					t.Fatalf("skip emitted %d orders, want 3 missing-level orders: %+v", len(decision.Orders), decision.Orders)
				}

				for _, order := range decision.Orders {
					if order.Price.Equals(decimal.MustParse("110")) {
						t.Fatalf("skip duplicated the order already open at 110: %+v", decision.Orders)
					}
				}

				if len(decision.CancelClientOrderIDs) != 0 {
					t.Fatalf("skip emitted cancels: %+v", decision.CancelClientOrderIDs)
				}
			}
		})
	}
}

func TestDynamicGridHysteresisSkipRestoresEmptyLadder(t *testing.T) {
	input := hysteresisTestInput(hysteresisTestState("10"), "11.9", "")
	input.Bot.OpenOrders = nil

	decision, err := NewDynamicGrid().OnTick(input)
	if err != nil {
		t.Fatalf("OnTick returned error: %v", err)
	}

	if got := decision.State.Get("spacing_value", nil); got != "10" {
		t.Fatalf("spacing_value = %v, want 10", got)
	}

	if len(decision.Orders) != 4 {
		t.Fatalf("skip emitted %d orders, want the full four-level ladder: %+v", len(decision.Orders), decision.Orders)
	}

	if len(decision.CancelClientOrderIDs) != 0 {
		t.Fatalf("skip emitted cancels: %+v", decision.CancelClientOrderIDs)
	}
}

func TestDynamicGridHysteresisClampsCandidateBeforeComparing(t *testing.T) {
	input := hysteresisTestInput(hysteresisTestState("10"), "10", "20")
	input.Config.Values["min_spacing"] = "5"
	input.Config.Values["max_spacing"] = "20"
	input.Config.Values["initial_spacing"] = "5"
	raw := time.Date(2026, time.January, 1, 0, 1, 5, 0, time.UTC)
	input.Market.Timestamp = &raw
	input.Market.RecentPrices = []decimal.Decimal{decimal.MustParse("100"), decimal.MustParse("150")}

	decision, err := NewDynamicGrid().OnTick(input)
	if err != nil {
		t.Fatalf("OnTick returned error: %v", err)
	}

	if got := decision.State.Get("spacing_value", nil); got != "20" {
		t.Fatalf("spacing_value = %v, want clamped candidate 20", got)
	}
}

func TestDynamicGridClampsInitialSpacing(t *testing.T) {
	tests := []struct {
		name  string
		value string
		want  string
	}{
		{name: "above the maximum", value: "30", want: "20"},
		{name: "below the minimum", value: "2", want: "5"},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			input := testDynamicInput()
			input.Config.Values["initial_spacing"] = test.value

			decision, err := NewDynamicGrid().Initialize(input)
			if err != nil {
				t.Fatalf("Initialize returned error: %v", err)
			}

			if got := decision.State.Get("spacing_value", nil); got != test.want {
				t.Fatalf("spacing_value = %v, want %s", got, test.want)
			}
		})
	}
}

func TestDynamicGridHysteresisRejectsInvalidConfiguration(t *testing.T) {
	for _, value := range []string{"-1", "91", "not-a-number"} {
		t.Run(value, func(t *testing.T) {
			dynamic := NewDynamicGrid()

			_, err := dynamic.Initialize(hysteresisTestInput(NewState(), "10", value))
			if err == nil {
				t.Fatal("Initialize accepted invalid spacing_hysteresis_percent")
			}

			if !strings.Contains(err.Error(), "spacing_hysteresis_percent") {
				t.Fatalf("error = %v, want field name", err)
			}

			_, err = dynamic.OnTick(hysteresisTestInput(hysteresisTestState("10"), "10", value))
			if err == nil {
				t.Fatal("OnTick accepted invalid spacing_hysteresis_percent")
			}

			if !strings.Contains(err.Error(), "spacing_hysteresis_percent") {
				t.Fatalf("error = %v, want field name", err)
			}
		})
	}
}

func TestDynamicGridHysteresisFailsClosedOnInvalidCurrentSpacing(t *testing.T) {
	for _, spacing := range []string{"", "0", "-1", "not-a-number"} {
		t.Run(spacing, func(t *testing.T) {
			dynamic := NewDynamicGrid()
			state := hysteresisTestState(spacing)
			if spacing == "" {
				state = NewState().Merge(map[string]any{
					"initialized":          true,
					"center_price":         "100",
					"levels_per_side":      2,
					"last_recalculated_at": "2026-01-01T00:00:00+00:00",
					"recalculation_count":  0,
				})
			}

			if _, err := dynamic.OnTick(hysteresisTestInput(state, "12", "20")); err == nil {
				t.Fatal("OnTick accepted invalid persisted spacing")
			}
		})
	}
}
