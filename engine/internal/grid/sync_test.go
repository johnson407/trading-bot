package grid_test

import (
	"strconv"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/goldenfixture"
	"github.com/johnson407/trading-bot/engine/internal/grid"
	"github.com/johnson407/trading-bot/engine/internal/paperfill"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
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
	Label       string `json:"label"`
	MarketPrice string `json:"market_price"`
	Result      struct {
		Fills int `json:"fills"`
	} `json:"result"`
	Grids  []syncFixtureGrid  `json:"grids"`
	Orders []syncFixtureOrder `json:"orders"`
}

type gridSyncFixture struct {
	Scenarios map[string]struct {
		Steps []syncFixtureStep `json:"steps"`
	} `json:"scenarios"`
}

type syncScenarioConfig struct {
	name         string
	strategyKey  string
	quantity     string
	strategyImpl strategy.Strategy
	config       map[string]any
	// fillSteps are steps where the PHP pipeline applies paper fills and then
	// the strategy re-arm order. With SimpleGrid ported, these steps now assert
	// the exact orders and grid level states recorded by the fixture.
	fillSteps map[string]bool
}

func TestGoldenGridSyncCreateMatchFillAndTrailingRecenter(t *testing.T) {
	var fixture gridSyncFixture
	if err := goldenfixture.Load("grid_sync", &fixture); err != nil {
		t.Fatal(err)
	}

	scenarios := []syncScenarioConfig{
		{
			name:         "simple_grid_create_match_and_fill",
			strategyKey:  "simple-grid",
			quantity:     "1",
			strategyImpl: strategy.NewSimpleGrid(),
			config: map[string]any{
				"center_price":    "100",
				"levels_per_side": 1,
				"spacing_type":    "arithmetic",
				"spacing_value":   "10",
				"order_quantity":  "1",
			},
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
				active        *grid.Grid
				grids         []grid.Grid
				orders        []grid.Order
				nextGridID    int64 = 1
				nextOrderID   int64 = 1
				strategyState       = strategy.NewState()
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

					if scenarioConfig.strategyImpl != nil {
						input := strategyInputForStep(scenarioConfig, step.MarketPrice, strategyState, orders)

						if strategyState.IsNew() {
							initialized, initializeErr := scenarioConfig.strategyImpl.Initialize(input)
							if initializeErr != nil {
								t.Fatal(initializeErr)
							}

							strategyState = initialized.State
							input = input.WithState(strategyState)
						}

						tickDecision, tickErr := scenarioConfig.strategyImpl.OnTick(input)
						if tickErr != nil {
							t.Fatal(tickErr)
						}

						strategyState = tickDecision.State

						if scenarioConfig.fillSteps[step.Label] {
							if !result.Matched {
								t.Fatal("fill-driven tick with unchanged levels must keep the matching grid")
							}

							applyPaperFillsAndRearm(t, step, &orders, &grids, active, scenarioConfig, &strategyState, &nextOrderID)
						}
					} else if scenarioConfig.fillSteps[step.Label] {
						t.Fatal("fill step requires a strategy implementation")
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

func strategyInputForStep(
	scenarioConfig syncScenarioConfig,
	price string,
	state strategy.State,
	orders []grid.Order,
) strategy.Input {
	openOrders := make([]strategy.OpenOrderSnapshot, 0, len(orders))

	for _, order := range orders {
		if !isOpenOrder(order.Status) {
			continue
		}

		sequence := order.GridLevelSequence
		openOrders = append(openOrders, strategy.OpenOrderSnapshot{
			ClientOrderID:     strconv.FormatInt(order.ID, 10),
			Side:              strategy.Side(order.Side),
			Price:             order.Price,
			Quantity:          order.Quantity,
			GridLevelSequence: &sequence,
		})
	}

	return strategy.Input{
		Market: strategy.MarketSnapshot{Price: decimal.MustParse(price)},
		Bot:    strategy.BotSnapshot{OpenOrders: openOrders},
		Config: strategy.Config{Key: scenarioConfig.strategyKey, Values: scenarioConfig.config},
		State:  state,
	}
}

func applyPaperFillsAndRearm(
	t *testing.T,
	step syncFixtureStep,
	orders *[]grid.Order,
	grids *[]grid.Grid,
	active *grid.Grid,
	scenarioConfig syncScenarioConfig,
	state *strategy.State,
	nextOrderID *int64,
) {
	t.Helper()

	paperOrders := make([]paperfill.Order, 0, len(*orders))

	for _, order := range *orders {
		if !isOpenOrder(order.Status) {
			continue
		}

		paperOrders = append(paperOrders, paperfill.Order{
			ClientOrderID:  strconv.FormatInt(order.ID, 10),
			Side:           paperfill.Side(order.Side),
			Price:          order.Price,
			Quantity:       order.Quantity,
			FilledQuantity: order.FilledQuantity,
			Status:         paperfill.OrderStatus(order.Status),
		})
	}

	fills, err := paperfill.QualifyingFills(decimal.MustParse(step.MarketPrice), paperOrders, "0.001")
	if err != nil {
		t.Fatal(err)
	}

	if len(fills) != step.Result.Fills {
		t.Fatalf("%s: paper fill count = %d, want %d", step.Label, len(fills), step.Result.Fills)
	}

	for _, fill := range fills {
		orderIndex := -1

		for index := range *orders {
			if strconv.FormatInt((*orders)[index].ID, 10) == fill.ClientOrderID {
				orderIndex = index

				break
			}
		}

		if orderIndex < 0 {
			t.Fatalf("%s: fill references unknown order %q", step.Label, fill.ClientOrderID)
		}

		order := &(*orders)[orderIndex]
		order.FilledQuantity = order.FilledQuantity.Add(fill.Quantity)

		if order.FilledQuantity.GreaterThanOrEqual(order.Quantity) {
			order.Status = grid.OrderFilled
		} else {
			order.Status = grid.OrderPartiallyFilled
		}

		setGridLevelState(active, grids, order.GridID, order.GridLevelSequence, grid.LevelFilled)

		input := strategyInputForStep(scenarioConfig, step.MarketPrice, *state, *orders)
		decision, fillErr := scenarioConfig.strategyImpl.OnFill(input, strategy.FillEvent{
			ExchangeFillID:    "paper-" + strconv.FormatInt(order.ID, 10),
			Side:              strategy.Side(fill.Side),
			Price:             fill.Price,
			Quantity:          fill.Quantity,
			Fee:               fill.Fee,
			ClientOrderID:     stringPointer(fill.ClientOrderID),
			GridLevelSequence: intPointer(order.GridLevelSequence),
		})
		if fillErr != nil {
			t.Fatal(fillErr)
		}

		*state = decision.State

		for _, intent := range decision.Orders {
			if intent.GridLevelSequence == nil {
				continue
			}

			if hasOpenOrderAtLevel(*orders, active.ID, *intent.GridLevelSequence) {
				continue
			}

			price := intent.Price
			if level := gridLevel(active, *intent.GridLevelSequence); level != nil {
				price = level.Price
			}

			*orders = append(*orders, grid.Order{
				ID:                *nextOrderID,
				GridID:            active.ID,
				GridLevelSequence: *intent.GridLevelSequence,
				Side:              grid.Side(intent.Side),
				Type:              grid.OrderTypeLimit,
				Price:             price,
				Quantity:          intent.Quantity,
				FilledQuantity:    decimal.Zero(),
				Status:            grid.OrderOpen,
				Mode:              grid.ModePaper,
			})
			*nextOrderID = *nextOrderID + 1

			setGridLevelState(active, grids, active.ID, *intent.GridLevelSequence, grid.LevelOpen)
		}
	}
}

func isOpenOrder(status grid.OrderStatus) bool {
	return status == grid.OrderPending || status == grid.OrderOpen || status == grid.OrderPartiallyFilled
}

func hasOpenOrderAtLevel(orders []grid.Order, gridID int64, sequence int) bool {
	for _, order := range orders {
		if order.GridID == gridID && order.GridLevelSequence == sequence && isOpenOrder(order.Status) {
			return true
		}
	}

	return false
}

func gridLevel(active *grid.Grid, sequence int) *grid.GridLevel {
	if active == nil {
		return nil
	}

	for index := range active.Levels {
		if active.Levels[index].Sequence == sequence {
			return &active.Levels[index]
		}
	}

	return nil
}

func setGridLevelState(
	active *grid.Grid,
	grids *[]grid.Grid,
	gridID int64,
	sequence int,
	state grid.LevelState,
) {
	for index := range *grids {
		if (*grids)[index].ID != gridID {
			continue
		}

		for levelIndex := range (*grids)[index].Levels {
			if (*grids)[index].Levels[levelIndex].Sequence == sequence {
				(*grids)[index].Levels[levelIndex].State = state
			}
		}
	}

	if active != nil && active.ID == gridID {
		for index := range active.Levels {
			if active.Levels[index].Sequence == sequence {
				active.Levels[index].State = state
			}
		}
	}
}

func stringPointer(value string) *string {
	return &value
}

func intPointer(value int) *int {
	return &value
}

func fixed(t *testing.T, value decimal.Decimal) string {
	t.Helper()

	formatted, err := value.ToFixed(decimal.Scale)
	if err != nil {
		t.Fatal(err)
	}

	return formatted
}
