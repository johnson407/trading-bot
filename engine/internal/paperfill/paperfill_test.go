package paperfill_test

import (
	"fmt"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/goldenfixture"
	"github.com/johnson407/trading-bot/engine/internal/paperfill"
)

const (
	goldenClock = "2026-11-23T00:00:00+00:00"
	goldenFee   = "0.001"
)

type fixtureOrder struct {
	Grid              int    `json:"grid"`
	GridLevelSequence int    `json:"grid_level_sequence"`
	ClientOrderID     string `json:"client_order_id"`
	Side              string `json:"side"`
	Price             string `json:"price"`
	Quantity          string `json:"quantity"`
	FilledQuantity    string `json:"filled_quantity"`
	Status            string `json:"status"`
}

type fixtureFill struct {
	ClientOrderID string `json:"client_order_id"`
	Side          string `json:"side"`
	Price         string `json:"price"`
	Quantity      string `json:"quantity"`
	Fee           string `json:"fee"`
	FeeCurrency   string `json:"fee_currency"`
}

type qualifyingCase struct {
	Label      string         `json:"label"`
	MarketLast string         `json:"market_last"`
	FeeRate    string         `json:"fee_rate"`
	Orders     []fixtureOrder `json:"orders"`
	Fills      []fixtureFill  `json:"fills"`
}

type tickStep struct {
	Label       string `json:"label"`
	MarketPrice string `json:"market_price"`
	Result      struct {
		Fills int `json:"fills"`
	} `json:"result"`
	Orders []fixtureOrder `json:"orders"`
}

type paperFillsFixture struct {
	Format       string `json:"format"`
	Clock        string `json:"clock"`
	DecimalScale int    `json:"decimal_scale"`
	FeeRate      string `json:"fee_rate"`
	Qualifying   struct {
		Cases []qualifyingCase `json:"cases"`
	} `json:"qualifying_fills"`
	TickPipeline struct {
		IntraTickPrints map[string][]string `json:"intra_tick_prints"`
		Steps           []tickStep          `json:"steps"`
	} `json:"tick_pipeline_cross_and_recross"`
}

func TestGoldenPaperQualifyingFills(t *testing.T) {
	var fixture paperFillsFixture
	if err := goldenfixture.Load("paper_fills", &fixture); err != nil {
		t.Fatal(err)
	}

	assertGoldenMetadata(t, fixture)

	if len(fixture.Qualifying.Cases) == 0 {
		t.Fatal("golden fixture has no qualifying_fills cases")
	}

	for _, testCase := range fixture.Qualifying.Cases {
		t.Run(testCase.Label, func(t *testing.T) {
			orders := make([]paperfill.Order, 0, len(testCase.Orders))
			for _, order := range testCase.Orders {
				orders = append(orders, paperfillOrder(t, order))
			}

			fills, err := paperfill.QualifyingFills(decimal.MustParse(testCase.MarketLast), orders, testCase.FeeRate)
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}

			if len(fills) != len(testCase.Fills) {
				t.Fatalf("fill count = %d, want %d", len(fills), len(testCase.Fills))
			}

			for index, want := range testCase.Fills {
				got := fills[index]

				if got.ClientOrderID != want.ClientOrderID ||
					string(got.Side) != want.Side ||
					got.Price.String() != want.Price ||
					got.Quantity.String() != want.Quantity ||
					got.Fee.String() != want.Fee ||
					got.FeeCurrency != want.FeeCurrency {
					t.Fatalf("fill[%d] = {%s %s %s %s %s %s}, want {%s %s %s %s %s %s}",
						index,
						got.ClientOrderID, got.Side, got.Price, got.Quantity, got.Fee, got.FeeCurrency,
						want.ClientOrderID, want.Side, want.Price, want.Quantity, want.Fee, want.FeeCurrency,
					)
				}
			}
		})
	}
}

func TestGoldenPaperFillsCrossAndRecrossBetweenTicks(t *testing.T) {
	var fixture paperFillsFixture
	if err := goldenfixture.Load("paper_fills", &fixture); err != nil {
		t.Fatal(err)
	}

	assertGoldenMetadata(t, fixture)

	scenario := fixture.TickPipeline

	wantSteps := []struct {
		label string
		fills int
	}{
		{label: "initial_tick_95", fills: 0},
		{label: "recross_returns_before_tick_91", fills: 0},
		{label: "tick_observes_89_and_fills_buy", fills: 1},
		{label: "recross_returns_before_tick_109", fills: 0},
		{label: "tick_observes_111_and_fills_sell", fills: 1},
	}

	if len(scenario.Steps) != len(wantSteps) {
		t.Fatalf("step count = %d, want %d", len(scenario.Steps), len(wantSteps))
	}

	// These prints happened between ticks. The pipeline only evaluates the
	// market price observed by the tick, so the later 91/109 tick does not see
	// the 89/111 cross and must not fill.
	if got := scenario.IntraTickPrints["before_91"]; len(got) != 1 || got[0] != "89" {
		t.Fatalf("before_91 intra-tick prints = %v, want [89]", got)
	}

	if got := scenario.IntraTickPrints["before_109"]; len(got) != 1 || got[0] != "111" {
		t.Fatalf("before_109 intra-tick prints = %v, want [111]", got)
	}

	orders := make([]paperfill.Order, 0, len(scenario.Steps[0].Orders))
	for index, order := range scenario.Steps[0].Orders {
		if order.ClientOrderID == "" {
			order.ClientOrderID = fmt.Sprintf("grid-%d-level-%d-%d", order.Grid, order.GridLevelSequence, index)
		}

		orders = append(orders, paperfillOrder(t, order))
	}

	for index, step := range scenario.Steps {
		expected := wantSteps[index]

		if step.Label != expected.label {
			t.Fatalf("step[%d] label = %q, want %q", index, step.Label, expected.label)
		}

		if step.Result.Fills != expected.fills {
			t.Fatalf("%s fixture result fills = %d, want %d", step.Label, step.Result.Fills, expected.fills)
		}

		fills, err := paperfill.QualifyingFills(decimal.MustParse(step.MarketPrice), orders, fixture.FeeRate)
		if err != nil {
			t.Fatalf("%s: unexpected error: %v", step.Label, err)
		}

		if len(fills) != expected.fills {
			t.Fatalf("%s: fill count = %d, want %d", step.Label, len(fills), expected.fills)
		}

		switch step.Label {
		case "recross_returns_before_tick_91", "recross_returns_before_tick_109":
			if len(fills) != 0 {
				t.Fatalf("%s: intra-tick print must not fill a returning market", step.Label)
			}
		case "tick_observes_89_and_fills_buy":
			assertFill(t, fills[0], "buy", "90", "1", "0.09")
		case "tick_observes_111_and_fills_sell":
			assertFill(t, fills[0], "sell", "110", "1", "0.11")
		}

		applyFills(t, step.Label, orders, fills)

		// The fixture records strategy placement after a fill; that remains
		// task 4.5. Compare the orders that existed before the tick.
		assertOrderPrefix(t, step.Label, orders, step.Orders)
	}
}

func assertGoldenMetadata(t *testing.T, fixture paperFillsFixture) {
	t.Helper()

	if fixture.Format != "php-golden-engine/1" {
		t.Fatalf("fixture format = %q, want php-golden-engine/1", fixture.Format)
	}

	if fixture.Clock != goldenClock {
		t.Fatalf("fixture clock = %q, want %q", fixture.Clock, goldenClock)
	}

	if fixture.DecimalScale != decimal.Scale {
		t.Fatalf("fixture decimal scale = %d, want %d", fixture.DecimalScale, decimal.Scale)
	}

	if fixture.FeeRate != goldenFee {
		t.Fatalf("fixture fee rate = %q, want %q", fixture.FeeRate, goldenFee)
	}
}

func paperfillOrder(t *testing.T, order fixtureOrder) paperfill.Order {
	t.Helper()

	return paperfill.Order{
		ClientOrderID:  order.ClientOrderID,
		Side:           paperfill.Side(order.Side),
		Price:          mustDecimal(t, order.Price),
		Quantity:       mustDecimal(t, order.Quantity),
		FilledQuantity: mustDecimal(t, order.FilledQuantity),
		Status:         paperfill.OrderStatus(order.Status),
	}
}

func applyFills(t *testing.T, label string, orders []paperfill.Order, fills []paperfill.Fill) {
	t.Helper()

	for _, fill := range fills {
		found := false

		for index := range orders {
			if orders[index].ClientOrderID != fill.ClientOrderID {
				continue
			}

			orders[index].FilledQuantity = orders[index].FilledQuantity.Add(fill.Quantity)
			if orders[index].FilledQuantity.GreaterThanOrEqual(orders[index].Quantity) {
				orders[index].Status = paperfill.StatusFilled
			} else {
				orders[index].Status = paperfill.StatusPartiallyFilled
			}

			found = true

			break
		}

		if !found {
			t.Fatalf("%s: fill references unknown client order id %q", label, fill.ClientOrderID)
		}
	}
}

func assertFill(t *testing.T, fill paperfill.Fill, side, price, quantity, fee string) {
	t.Helper()

	if string(fill.Side) != side || fill.Price.String() != price ||
		fill.Quantity.String() != quantity || fill.Fee.String() != fee {
		t.Fatalf("fill = {%s %s %s %s}, want {%s %s %s %s}",
			fill.Side, fill.Price, fill.Quantity, fill.Fee, side, price, quantity, fee,
		)
	}
}

func assertOrderPrefix(t *testing.T, label string, actual []paperfill.Order, expected []fixtureOrder) {
	t.Helper()

	if len(expected) < len(actual) {
		t.Fatalf("%s: fixture has %d orders, need at least %d", label, len(expected), len(actual))
	}

	for index := range actual {
		got := actual[index]
		want := expected[index]

		if string(got.Side) != want.Side ||
			fixed(t, got.Price) != want.Price ||
			fixed(t, got.Quantity) != want.Quantity ||
			fixed(t, got.FilledQuantity) != want.FilledQuantity ||
			string(got.Status) != want.Status {
			t.Fatalf("%s: order[%d] = {%s %s %s %s %s}, want {%s %s %s %s %s}",
				label, index,
				got.Side, fixed(t, got.Price), fixed(t, got.Quantity), fixed(t, got.FilledQuantity), got.Status,
				want.Side, want.Price, want.Quantity, want.FilledQuantity, want.Status,
			)
		}
	}
}

func fixed(t *testing.T, value decimal.Decimal) string {
	t.Helper()

	formatted, err := value.ToFixed(decimal.Scale)
	if err != nil {
		t.Fatal(err)
	}

	return formatted
}

func mustDecimal(t *testing.T, value string) decimal.Decimal {
	t.Helper()

	parsed, err := decimal.Parse(value)
	if err != nil {
		t.Fatal(err)
	}

	return parsed
}
