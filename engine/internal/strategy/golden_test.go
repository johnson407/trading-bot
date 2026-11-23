package strategy_test

import (
	"bytes"
	"encoding/json"
	"strconv"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/goldenfixture"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

const (
	goldenClock = "2026-11-23T00:00:00+00:00"
	goldenFee   = "0.001"
)

type strategiesFixture struct {
	Format       string `json:"format"`
	Clock        string `json:"clock"`
	DecimalScale int    `json:"decimal_scale"`
	FeeRate      string `json:"fee_rate"`
	Strategies   map[string]struct {
		Cases []fixtureCase `json:"cases"`
	} `json:"strategies"`
}

type fixtureCase struct {
	Label     string           `json:"label"`
	Operation string           `json:"operation"`
	Input     fixtureInput     `json:"input"`
	Fill      *fixtureFill     `json:"fill"`
	Decision  *fixtureDecision `json:"decision"`
	Levels    []fixtureLevel   `json:"levels"`
	Stop      *bool            `json:"stop"`
	Error     *fixtureError    `json:"error"`
}

type fixtureInput struct {
	Market struct {
		Symbol       string     `json:"symbol"`
		Price        string     `json:"price"`
		Bid          *string    `json:"bid"`
		Ask          *string    `json:"ask"`
		RecentPrices []string   `json:"recent_prices"`
		Timestamp    *time.Time `json:"timestamp"`
	} `json:"market"`
	Bot struct {
		BotID             int                `json:"bot_id"`
		BaseInventory     string             `json:"base_inventory"`
		QuoteInventory    string             `json:"quote_inventory"`
		AverageEntryPrice string             `json:"average_entry_price"`
		RealizedPnL       string             `json:"realized_pnl"`
		CapitalAllocation string             `json:"capital_allocation"`
		OpenOrders        []fixtureOpenOrder `json:"open_orders"`
	} `json:"bot"`
	Config struct {
		Key    string        `json:"key"`
		Values fixtureValues `json:"values"`
	} `json:"config"`
	State fixtureState `json:"state"`
}

type fixtureValues map[string]any

func (v *fixtureValues) UnmarshalJSON(data []byte) error {
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.UseNumber()

	var values map[string]any
	if err := decoder.Decode(&values); err != nil {
		return err
	}

	*v = values

	return nil
}

type fixtureState struct {
	Version int            `json:"version"`
	Data    map[string]any `json:"data"`
}

func (s *fixtureState) UnmarshalJSON(data []byte) error {
	var raw struct {
		Version int             `json:"version"`
		Data    json.RawMessage `json:"data"`
	}

	if err := json.Unmarshal(data, &raw); err != nil {
		return err
	}

	s.Version = raw.Version

	if len(raw.Data) == 0 || string(raw.Data) == "null" || string(raw.Data) == "[]" {
		s.Data = map[string]any{}

		return nil
	}

	decoder := json.NewDecoder(bytes.NewReader(raw.Data))
	decoder.UseNumber()

	return decoder.Decode(&s.Data)
}

type fixtureOpenOrder struct {
	ClientOrderID     string `json:"client_order_id"`
	Side              string `json:"side"`
	Price             string `json:"price"`
	Quantity          string `json:"quantity"`
	GridLevelSequence *int   `json:"grid_level_sequence"`
}

type fixtureFill struct {
	ExchangeFillID    string  `json:"exchange_fill_id"`
	Side              string  `json:"side"`
	Price             string  `json:"price"`
	Quantity          string  `json:"quantity"`
	Fee               string  `json:"fee"`
	ClientOrderID     *string `json:"client_order_id"`
	GridLevelSequence *int    `json:"grid_level_sequence"`
}

type fixtureDecision struct {
	State                fixtureState   `json:"state"`
	Levels               []fixtureLevel `json:"levels"`
	Orders               []fixtureOrder `json:"orders"`
	Stop                 bool           `json:"stop"`
	Events               []any          `json:"events"`
	CancelClientOrderIDs []string       `json:"cancel_client_order_ids"`
}

type fixtureLevel struct {
	Sequence int    `json:"sequence"`
	Price    string `json:"price"`
	Side     string `json:"side"`
}

type fixtureOrder struct {
	Side              string `json:"side"`
	Price             string `json:"price"`
	Quantity          string `json:"quantity"`
	GridLevelSequence *int   `json:"grid_level_sequence"`
	Reason            string `json:"reason"`
}

type fixtureError struct {
	Exception string `json:"exception"`
	Message   string `json:"message"`
}

func TestGoldenStrategies(t *testing.T) {
	var fixture strategiesFixture
	if err := goldenfixture.Load("strategies", &fixture); err != nil {
		t.Fatal(err)
	}

	assertGoldenMetadata(t, fixture)

	strategies := []struct {
		name       string
		strategies strategy.Strategy
	}{
		{name: "simple-grid", strategies: strategy.NewSimpleGrid()},
		{name: "dynamic-grid", strategies: strategy.NewDynamicGrid()},
	}

	for _, entry := range strategies {
		data, ok := fixture.Strategies[entry.name]
		if !ok {
			t.Fatalf("fixture strategy %q not found", entry.name)
		}

		if len(data.Cases) == 0 {
			t.Fatalf("fixture strategy %q has no cases", entry.name)
		}

		t.Run(entry.name, func(t *testing.T) {
			for _, testCase := range data.Cases {
				testCase := testCase

				t.Run(testCase.Label, func(t *testing.T) {
					runGoldenCase(t, entry.strategies, testCase)
				})
			}
		})
	}
}

func runGoldenCase(t *testing.T, implementation strategy.Strategy, testCase fixtureCase) {
	t.Helper()

	input := buildInput(t, testCase)

	if testCase.Operation == "getGridLevels" {
		levels, err := implementation.GetGridLevels(input)
		if testCase.Error != nil {
			assertError(t, err, testCase.Error)
			return
		}

		if err != nil {
			t.Fatalf("unexpected error: %v", err)
		}

		assertLevels(t, levels, testCase.Levels)
		return
	}

	if testCase.Operation == "shouldStop" {
		got := implementation.ShouldStop(input)
		if testCase.Stop == nil {
			t.Fatal("fixture case has no stop value")
		}

		if got != *testCase.Stop {
			t.Fatalf("shouldStop = %v, want %v", got, *testCase.Stop)
		}

		return
	}

	var (
		decision strategy.Decision
		err      error
	)

	switch testCase.Operation {
	case "initialize":
		decision, err = implementation.Initialize(input)
	case "onTick":
		decision, err = implementation.OnTick(input)
	case "onFill":
		if testCase.Fill == nil {
			t.Fatal("fixture onFill case has no fill")
		}

		decision, err = implementation.OnFill(input, buildFill(t, *testCase.Fill))
	case "onCancel":
		decision, err = implementation.OnCancel(input, strategy.CancelEvent{
			ClientOrderID:     "client-1",
			GridLevelSequence: intPointer(2),
			Reason:            "manual",
		})
	default:
		t.Fatalf("unsupported fixture operation %q", testCase.Operation)
	}

	if testCase.Error != nil {
		assertError(t, err, testCase.Error)
		return
	}

	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if testCase.Decision == nil {
		t.Fatal("fixture case has no decision")
	}

	assertDecision(t, decision, *testCase.Decision)
}

func buildInput(t *testing.T, testCase fixtureCase) strategy.Input {
	t.Helper()

	market := strategy.MarketSnapshot{
		Symbol:    testCase.Input.Market.Symbol,
		Price:     mustDecimal(t, testCase.Input.Market.Price),
		Bid:       optionalDecimal(t, testCase.Input.Market.Bid),
		Ask:       optionalDecimal(t, testCase.Input.Market.Ask),
		Timestamp: testCase.Input.Market.Timestamp,
	}

	for _, price := range testCase.Input.Market.RecentPrices {
		market.RecentPrices = append(market.RecentPrices, mustDecimal(t, price))
	}

	openOrders := make([]strategy.OpenOrderSnapshot, 0, len(testCase.Input.Bot.OpenOrders))
	for _, order := range testCase.Input.Bot.OpenOrders {
		openOrders = append(openOrders, strategy.OpenOrderSnapshot{
			ClientOrderID:     order.ClientOrderID,
			Side:              strategy.Side(order.Side),
			Price:             mustDecimal(t, order.Price),
			Quantity:          mustDecimal(t, order.Quantity),
			GridLevelSequence: order.GridLevelSequence,
		})
	}

	bot := strategy.BotSnapshot{
		BotID:             testCase.Input.Bot.BotID,
		BaseInventory:     mustDecimal(t, testCase.Input.Bot.BaseInventory),
		QuoteInventory:    mustDecimal(t, testCase.Input.Bot.QuoteInventory),
		AverageEntryPrice: mustDecimal(t, testCase.Input.Bot.AverageEntryPrice),
		RealizedPnL:       mustDecimal(t, testCase.Input.Bot.RealizedPnL),
		CapitalAllocation: mustDecimal(t, testCase.Input.Bot.CapitalAllocation),
		OpenOrders:        openOrders,
	}

	state := strategy.State{
		Version: testCase.Input.State.Version,
		Data:    testCase.Input.State.Data,
	}

	if state.Data == nil {
		state.Data = map[string]any{}
	}

	return strategy.Input{
		Market: market,
		Bot:    bot,
		Config: strategy.Config{
			Key:    testCase.Input.Config.Key,
			Values: map[string]any(testCase.Input.Config.Values),
		},
		State: state,
	}
}

func buildFill(t *testing.T, fill fixtureFill) strategy.FillEvent {
	t.Helper()

	return strategy.FillEvent{
		ExchangeFillID:    fill.ExchangeFillID,
		Side:              strategy.Side(fill.Side),
		Price:             mustDecimal(t, fill.Price),
		Quantity:          mustDecimal(t, fill.Quantity),
		Fee:               mustDecimal(t, fill.Fee),
		ClientOrderID:     fill.ClientOrderID,
		GridLevelSequence: fill.GridLevelSequence,
	}
}

func assertGoldenMetadata(t *testing.T, fixture strategiesFixture) {
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

func assertDecision(t *testing.T, got strategy.Decision, want fixtureDecision) {
	t.Helper()

	assertState(t, got.State, want.State)
	assertLevels(t, got.Levels, want.Levels)
	assertOrders(t, got.Orders, want.Orders)

	if got.Stop != want.Stop {
		t.Fatalf("stop = %v, want %v", got.Stop, want.Stop)
	}

	if len(got.Events) != len(want.Events) {
		t.Fatalf("event count = %d, want %d", len(got.Events), len(want.Events))
	}

	if len(got.CancelClientOrderIDs) != len(want.CancelClientOrderIDs) {
		t.Fatalf("cancel count = %d, want %d", len(got.CancelClientOrderIDs), len(want.CancelClientOrderIDs))
	}

	for index, wantID := range want.CancelClientOrderIDs {
		if got.CancelClientOrderIDs[index] != wantID {
			t.Fatalf("cancel[%d] = %q, want %q", index, got.CancelClientOrderIDs[index], wantID)
		}
	}
}

func assertState(t *testing.T, got strategy.State, want fixtureState) {
	t.Helper()

	if got.Version != want.Version {
		t.Fatalf("state version = %d, want %d", got.Version, want.Version)
	}

	gotJSON := canonicalJSON(t, got.Data)
	wantJSON := canonicalJSON(t, want.Data)

	if gotJSON != wantJSON {
		t.Fatalf("state = %s, want %s", gotJSON, wantJSON)
	}
}

func canonicalJSON(t *testing.T, value any) string {
	t.Helper()

	encoded, err := json.Marshal(value)
	if err != nil {
		t.Fatal(err)
	}

	return string(encoded)
}

func assertLevels(t *testing.T, got []strategy.GridLevelPlan, want []fixtureLevel) {
	t.Helper()

	if len(got) != len(want) {
		t.Fatalf("level count = %d, want %d", len(got), len(want))
	}

	for index, expected := range want {
		actual := got[index]

		if actual.Sequence != expected.Sequence ||
			actual.Price.String() != expected.Price ||
			string(actual.Side) != expected.Side {
			t.Fatalf("level[%d] = {%d %s %s}, want {%d %s %s}",
				index,
				actual.Sequence, actual.Price.String(), actual.Side,
				expected.Sequence, expected.Price, expected.Side,
			)
		}
	}
}

func assertOrders(t *testing.T, got []strategy.OrderIntent, want []fixtureOrder) {
	t.Helper()

	if len(got) != len(want) {
		t.Fatalf("order intent count = %d, want %d", len(got), len(want))
	}

	for index, expected := range want {
		actual := got[index]

		if string(actual.Side) != expected.Side ||
			actual.Price.String() != expected.Price ||
			actual.Quantity.String() != expected.Quantity ||
			!sameIntPointer(actual.GridLevelSequence, expected.GridLevelSequence) ||
			actual.Reason != expected.Reason {
			t.Fatalf("order[%d] = {%s %s %s %s %s}, want {%s %s %s %v %s}",
				index,
				actual.Side, actual.Price.String(), actual.Quantity.String(), pointerString(actual.GridLevelSequence), actual.Reason,
				expected.Side, expected.Price, expected.Quantity, pointerString(expected.GridLevelSequence), expected.Reason,
			)
		}
	}
}

func assertError(t *testing.T, err error, want *fixtureError) {
	t.Helper()

	if err == nil {
		t.Fatalf("expected error %q, got nil", want.Message)
	}

	if err.Error() != want.Message {
		t.Fatalf("error = %q, want %q", err.Error(), want.Message)
	}
}

func sameIntPointer(left, right *int) bool {
	if left == nil || right == nil {
		return left == nil && right == nil
	}

	return *left == *right
}

func pointerString(value *int) string {
	if value == nil {
		return "nil"
	}

	return strconv.Itoa(*value)
}

func mustDecimal(t *testing.T, value string) decimal.Decimal {
	t.Helper()

	parsed, err := decimal.Parse(value)
	if err != nil {
		t.Fatal(err)
	}

	return parsed
}

func optionalDecimal(t *testing.T, value *string) *decimal.Decimal {
	t.Helper()

	if value == nil {
		return nil
	}

	parsed := mustDecimal(t, *value)

	return &parsed
}

func intPointer(value int) *int {
	return &value
}
