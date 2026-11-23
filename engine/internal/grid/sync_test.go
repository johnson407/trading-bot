package grid_test

import (
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/goldenfixture"
	"github.com/johnson407/trading-bot/engine/internal/grid"
)

type syncFixtureLevel struct {
	Sequence int    `json:"sequence"`
	Price    string `json:"price"`
	Side     string `json:"side"`
	State    string `json:"state"`
}

type syncFixtureGrid struct {
	CenterPrice  string             `json:"center_price"`
	SpacingType  string             `json:"spacing_type"`
	SpacingValue string             `json:"spacing_value"`
	LevelCount   int                `json:"level_count"`
	Status       string             `json:"status"`
	Levels       []syncFixtureLevel `json:"levels"`
}

type syncFixtureOrder struct {
	Grid              int    `json:"grid"`
	GridLevelSequence int    `json:"grid_level_sequence"`
	Side              string `json:"side"`
	Type              string `json:"type"`
	Price             string `json:"price"`
	Quantity          string `json:"quantity"`
	FilledQuantity    string `json:"filled_quantity"`
	Status            string `json:"status"`
	Mode              string `json:"mode"`
}

type syncFixtureStep struct {
	Label       string             `json:"label"`
	MarketPrice string             `json:"market_price"`
	Grids       []syncFixtureGrid  `json:"grids"`
	Orders      []syncFixtureOrder `json:"orders"`
}

type gridSyncFixture struct {
	Scenarios map[string]struct {
		Steps []syncFixtureStep `json:"steps"`
	} `json:"scenarios"`
}

type syncScenarioConfig struct {
	name        string
	strategyKey string
	quantity    string
	// fillSteps are steps where the PHP pipeline also applies paper fills and
	// strategy placement. This test still owns grid sync only: paperfill proves
	// fill selection (task 4.4), while the strategy re-arm order that appears
	// after a fill is task 4.5. These steps therefore assert the grid structure
	// stays matched.
	fillSteps map[string]bool
}

func TestGoldenGridSyncCreateMatchFillAndTrailingRecenter(t *testing.T) {
	var fixture gridSyncFixture
	if err := goldenfixture.Load("grid_sync", &fixture); err != nil {
		t.Fatal(err)
	}

	scenarios := []syncScenarioConfig{
		{
			name:        "simple_grid_create_match_and_fill",
			strategyKey: "simple-grid",
			quantity:    "1",
			fillSteps: map[string]bool{
				"buy_fill_at_89":   true,
				"sell_fill_at_111": true,
			},
		},
		{
			name:        "trailing_grid_recenter",
			strategyKey: "trailing-grid",
			quantity:    "1",
			fillSteps:   map[string]bool{},
		},
	}

	for _, scenarioConfig := range scenarios {
		scenario, ok := fixture.Scenarios[scenarioConfig.name]
		if !ok {
			t.Fatalf("fixture scenario %q not found", scenarioConfig.name)
		}

		t.Run(scenarioConfig.name, func(t *testing.T) {
			var (
				active      *grid.Grid
				grids       []grid.Grid
				orders      []grid.Order
				nextGridID  int64 = 1
				nextOrderID int64 = 1
			)

			for _, step := range scenario.Steps {
				t.Run(step.Label, func(t *testing.T) {
					plans := plansForStep(t, step.Grids)

					result, err := grid.Sync(active, orders, plans, scenarioConfig.strategyKey, nextGridID)
					if err != nil {
						t.Fatal(err)
					}

					if result.Previous != nil {
						for _, canceled := range result.CanceledOrders {
							for index := range orders {
								if orders[index].ID == canceled.ID {
									orders[index].Status = grid.OrderCanceled
								}
							}
						}

						replaced := false
						for index := range grids {
							if grids[index].ID == result.Previous.ID {
								grids[index] = *result.Previous
								replaced = true
							}
						}

						if !replaced {
							grids = append(grids, *result.Previous)
						}
					}

					if result.Created {
						created := result.Grid
						grids = append(grids, *created)

						for index, level := range created.Levels {
							created.Levels[index].State = grid.LevelOpen
							orders = append(orders, grid.Order{
								ID:                nextOrderID,
								GridID:            created.ID,
								GridLevelSequence: level.Sequence,
								Side:              level.Side,
								Type:              grid.OrderTypeLimit,
								Price:             level.Price,
								Quantity:          decimal.MustParse(scenarioConfig.quantity),
								FilledQuantity:    decimal.Zero(),
								Status:            grid.OrderOpen,
								Mode:              grid.ModePaper,
							})
							nextOrderID++
						}

						nextGridID++
					}

					active = result.Grid

					if scenarioConfig.fillSteps[step.Label] {
						if !result.Matched {
							t.Fatal("fill-driven tick with unchanged levels must keep the matching grid")
						}

						assertGridStructure(t, grids, step.Grids)

						return
					}

					if len(grids) != len(step.Grids) {
						t.Fatalf("grid count = %d, want %d", len(grids), len(step.Grids))
					}

					assertGridsExact(t, grids, step.Grids)
					assertOrdersExact(t, orders, step.Orders)
				})
			}
		})
	}
}

func plansForStep(t *testing.T, expectedGrids []syncFixtureGrid) []grid.Level {
	t.Helper()

	for index := len(expectedGrids) - 1; index >= 0; index-- {
		expected := expectedGrids[index]
		if expected.Status != string(grid.GridActive) {
			continue
		}

		plans := make([]grid.Level, 0, len(expected.Levels))

		for _, level := range expected.Levels {
			plans = append(plans, grid.Level{
				Sequence: level.Sequence,
				Price:    decimal.MustParse(level.Price),
				Side:     grid.Side(level.Side),
			})
		}

		return plans
	}

	t.Fatal("fixture step has no active grid")

	return nil
}

func assertGridsExact(t *testing.T, actual []grid.Grid, expected []syncFixtureGrid) {
	t.Helper()

	for index := range expected {
		got := actual[index]
		want := expected[index]

		if fixed(t, got.CenterPrice) != want.CenterPrice ||
			string(got.SpacingType) != want.SpacingType ||
			fixed(t, got.SpacingValue) != want.SpacingValue ||
			got.LevelCount != want.LevelCount ||
			string(got.Status) != want.Status {
			t.Fatalf("grid[%d] = {%s %s %s %d %s}, want {%s %s %s %d %s}",
				index,
				fixed(t, got.CenterPrice), got.SpacingType, fixed(t, got.SpacingValue), got.LevelCount, got.Status,
				want.CenterPrice, want.SpacingType, want.SpacingValue, want.LevelCount, want.Status,
			)
		}

		if len(got.Levels) != len(want.Levels) {
			t.Fatalf("grid[%d] level count = %d, want %d", index, len(got.Levels), len(want.Levels))
		}

		for levelIndex, level := range want.Levels {
			gotLevel := got.Levels[levelIndex]

			if gotLevel.Sequence != level.Sequence ||
				fixed(t, gotLevel.Price) != level.Price ||
				string(gotLevel.Side) != level.Side ||
				string(gotLevel.State) != level.State {
				t.Fatalf("grid[%d] level[%d] = {%d %s %s %s}, want {%d %s %s %s}",
					index, levelIndex,
					gotLevel.Sequence, fixed(t, gotLevel.Price), gotLevel.Side, gotLevel.State,
					level.Sequence, level.Price, level.Side, level.State,
				)
			}
		}
	}
}

func assertGridStructure(t *testing.T, actual []grid.Grid, expected []syncFixtureGrid) {
	t.Helper()

	if len(actual) != len(expected) {
		t.Fatalf("grid count = %d, want %d", len(actual), len(expected))
	}

	for index := range expected {
		got := actual[index]
		want := expected[index]

		if fixed(t, got.CenterPrice) != want.CenterPrice ||
			string(got.SpacingType) != want.SpacingType ||
			fixed(t, got.SpacingValue) != want.SpacingValue ||
			got.LevelCount != want.LevelCount ||
			string(got.Status) != want.Status {
			t.Fatalf("grid[%d] structure does not match fixture", index)
		}

		for levelIndex, level := range want.Levels {
			gotLevel := got.Levels[levelIndex]
			if gotLevel.Sequence != level.Sequence ||
				fixed(t, gotLevel.Price) != level.Price ||
				string(gotLevel.Side) != level.Side {
				t.Fatalf("grid[%d] level[%d] structure does not match fixture", index, levelIndex)
			}
		}
	}
}

func assertOrdersExact(t *testing.T, actual []grid.Order, expected []syncFixtureOrder) {
	t.Helper()

	if len(actual) != len(expected) {
		t.Fatalf("order count = %d, want %d", len(actual), len(expected))
	}

	for index := range expected {
		got := actual[index]
		want := expected[index]

		if got.GridID != int64(want.Grid) ||
			got.GridLevelSequence != want.GridLevelSequence ||
			string(got.Side) != want.Side ||
			got.Type != want.Type ||
			fixed(t, got.Price) != want.Price ||
			fixed(t, got.Quantity) != want.Quantity ||
			fixed(t, got.FilledQuantity) != want.FilledQuantity ||
			string(got.Status) != want.Status ||
			got.Mode != want.Mode {
			t.Fatalf("order[%d] = {%d %d %s %s %s %s %s %s %s}, want {%d %d %s %s %s %s %s %s %s}",
				index,
				got.GridID, got.GridLevelSequence, got.Side, got.Type,
				fixed(t, got.Price), fixed(t, got.Quantity), fixed(t, got.FilledQuantity), got.Status, got.Mode,
				want.Grid, want.GridLevelSequence, want.Side, want.Type,
				want.Price, want.Quantity, want.FilledQuantity, want.Status, want.Mode,
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
