package strategy

import (
	"strings"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func testMartingaleInput() Input {
	return Input{
		Market: MarketSnapshot{Price: decimal.MustParse("100")},
		Config: Config{
			Key: "martingale-grid",
			Values: map[string]any{
				"center_price":        "100",
				"levels_per_side":     2,
				"spacing_type":        "arithmetic",
				"spacing_value":       "10",
				"base_order_quantity": "1",
				"size_multiplier":     "2",
				"max_order_quantity":  "10",
				"max_increases":       10,
			},
		},
		State: NewState(),
	}
}

func TestMartingaleInitializeRejectsDynamicSpacing(t *testing.T) {
	input := testMartingaleInput()
	input.Config.Values["spacing_type"] = "dynamic"

	_, err := NewMartingaleGrid().Initialize(input)
	if err == nil || !strings.Contains(err.Error(), "spacing_type") {
		t.Fatalf("expected spacing_type configuration error, got %v", err)
	}
}

func TestMartingaleGridBuyPlacesSellForFilledQuantity(t *testing.T) {
	martingale := NewMartingaleGrid()
	input := testMartingaleInput()
	initialized, err := martingale.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	first, err := martingale.OnFill(input.WithState(initialized.State), FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("80"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(1),
	})
	if err != nil {
		t.Fatalf("first OnFill returned error: %v", err)
	}

	if len(first.Orders) != 1 {
		t.Fatalf("expected one order, got %+v", first.Orders)
	}
	if first.Orders[0].Side != SideSell {
		t.Fatalf("expected a sell at the adjacent higher level, got %s", first.Orders[0].Side)
	}
	if !first.Orders[0].Price.Equals(decimal.MustParse("90")) {
		t.Fatalf("expected price 90, got %s", first.Orders[0].Price)
	}
	if !first.Orders[0].Quantity.Equals(decimal.MustParse("1")) {
		t.Fatalf("take-profit quantity must be the filled quantity 1, got %s", first.Orders[0].Quantity)
	}
	if got := first.State.Get("current_quantity", nil); got != "2" {
		t.Fatalf("expected current_quantity 2, got %v", got)
	}

	second, err := martingale.OnFill(input.WithState(first.State), FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("90"),
		Quantity:          decimal.MustParse("2"),
		GridLevelSequence: intPointer(2),
	})
	if err != nil {
		t.Fatalf("second OnFill returned error: %v", err)
	}

	if len(second.Orders) != 1 {
		t.Fatalf("expected one order, got %+v", second.Orders)
	}
	if second.Orders[0].Side != SideSell {
		t.Fatalf("expected a sell at the adjacent higher level, got %s", second.Orders[0].Side)
	}
	if !second.Orders[0].Price.Equals(decimal.MustParse("110")) {
		t.Fatalf("expected price 110, got %s", second.Orders[0].Price)
	}
	if !second.Orders[0].Quantity.Equals(decimal.MustParse("2")) {
		t.Fatalf("take-profit quantity must be the filled quantity 2, got %s", second.Orders[0].Quantity)
	}
	if got := second.State.Get("current_quantity", nil); got != "4" {
		t.Fatalf("expected current_quantity 4, got %v", got)
	}
}

func TestMartingaleGridSellPlacesBuyAtBaseSize(t *testing.T) {
	martingale := NewMartingaleGrid()
	input := testMartingaleInput()
	initialized, err := martingale.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	state := initialized.State.Merge(map[string]any{
		"current_quantity": "2",
		"increase_count":   1,
	})

	decision, err := martingale.OnFill(input.WithState(state), FillEvent{
		Side:              SideSell,
		Price:             decimal.MustParse("120"),
		Quantity:          decimal.MustParse("2"),
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
	if !order.Quantity.Equals(decimal.MustParse("1")) {
		t.Fatalf("entry after a sell must reset to the base size 1, got %s", order.Quantity)
	}
	if got := decision.State.Get("current_quantity", nil); got != "1" {
		t.Fatalf("expected current_quantity 1, got %v", got)
	}
	if got := decision.State.Get("increase_count", nil); got != 0 {
		t.Fatalf("expected increase_count 0, got %v", got)
	}
	if got := decision.State.Get("cap_reached", nil); got != false {
		t.Fatalf("expected cap_reached false, got %v", got)
	}
}

func TestMartingaleGridBuyThenSellResetsToBase(t *testing.T) {
	martingale := NewMartingaleGrid()
	input := testMartingaleInput()
	initialized, err := martingale.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	bought, err := martingale.OnFill(input.WithState(initialized.State), FillEvent{
		Side:              SideBuy,
		Price:             decimal.MustParse("80"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(1),
	})
	if err != nil {
		t.Fatalf("buy OnFill returned error: %v", err)
	}
	if got := bought.State.Get("current_quantity", nil); got != "2" {
		t.Fatalf("expected current_quantity 2 after buy, got %v", got)
	}

	sold, err := martingale.OnFill(input.WithState(bought.State), FillEvent{
		Side:              SideSell,
		Price:             decimal.MustParse("90"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(2),
	})
	if err != nil {
		t.Fatalf("sell OnFill returned error: %v", err)
	}
	if got := sold.State.Get("current_quantity", nil); got != "1" {
		t.Fatalf("expected current_quantity 1 after sell, got %v", got)
	}
	if got := sold.State.Get("increase_count", nil); got != 0 {
		t.Fatalf("expected increase_count 0 after sell, got %v", got)
	}
	if len(sold.Orders) != 1 || !sold.Orders[0].Quantity.Equals(decimal.MustParse("1")) {
		t.Fatalf("expected a base-size rebuy, got %+v", sold.Orders)
	}
}

func TestMartingaleGridSellAtCapClearsCap(t *testing.T) {
	martingale := NewMartingaleGrid()
	input := testMartingaleInput()
	initialized, err := martingale.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	state := initialized.State.Merge(map[string]any{
		"current_quantity": "10",
		"increase_count":   10,
		"cap_reached":      true,
	})

	decision, err := martingale.OnFill(input.WithState(state), FillEvent{
		Side:              SideSell,
		Price:             decimal.MustParse("120"),
		Quantity:          decimal.MustParse("10"),
		GridLevelSequence: intPointer(4),
	})
	if err != nil {
		t.Fatalf("OnFill returned error: %v", err)
	}
	if got := decision.State.Get("current_quantity", nil); got != "1" {
		t.Fatalf("expected current_quantity 1, got %v", got)
	}
	if got := decision.State.Get("increase_count", nil); got != 0 {
		t.Fatalf("expected increase_count 0, got %v", got)
	}
	if got := decision.State.Get("cap_reached", nil); got != false {
		t.Fatalf("expected cap_reached false, got %v", got)
	}
}

func TestMartingaleGridStartingInventorySellResetsToBase(t *testing.T) {
	martingale := NewMartingaleGrid()
	input := testMartingaleInput()
	initialized, err := martingale.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	decision, err := martingale.OnFill(input.WithState(initialized.State), FillEvent{
		Side:              SideSell,
		Price:             decimal.MustParse("120"),
		Quantity:          decimal.MustParse("1"),
		GridLevelSequence: intPointer(4),
	})
	if err != nil {
		t.Fatalf("OnFill returned error: %v", err)
	}
	if got := decision.State.Get("current_quantity", nil); got != "1" {
		t.Fatalf("expected current_quantity 1, got %v", got)
	}
	if got := decision.State.Get("increase_count", nil); got != 0 {
		t.Fatalf("expected increase_count 0, got %v", got)
	}
	if got := decision.State.Get("cap_reached", nil); got != false {
		t.Fatalf("expected cap_reached false, got %v", got)
	}
	if len(decision.Orders) != 1 || !decision.Orders[0].Quantity.Equals(decimal.MustParse("1")) {
		t.Fatalf("expected a base-size rebuy, got %+v", decision.Orders)
	}
}

func TestMartingaleInitializeRejectsInvalidSizeConfiguration(t *testing.T) {
	cases := []struct {
		name  string
		key   string
		value any
		want  string
	}{
		{name: "zero base", key: "base_order_quantity", value: "0", want: "base_order_quantity must be positive"},
		{name: "zero maximum", key: "max_order_quantity", value: "0", want: "max_order_quantity must be positive"},
		{name: "maximum below base", key: "max_order_quantity", value: "0.5", want: "max_order_quantity must be at least base_order_quantity"},
		{name: "multiplier of one", key: "size_multiplier", value: "1", want: "size_multiplier must be greater than 1"},
		{name: "negative increases", key: "max_increases", value: -1, want: "max_increases must not be negative"},
	}

	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			input := testMartingaleInput()
			input.Config.Values[testCase.key] = testCase.value

			_, err := NewMartingaleGrid().Initialize(input)
			if err == nil || !strings.Contains(err.Error(), testCase.want) {
				t.Fatalf("Initialize error = %v, want %q", err, testCase.want)
			}
		})
	}
}

func TestMartingaleExistingStateTickRejectsInvalidConfiguration(t *testing.T) {
	martingale := NewMartingaleGrid()
	input := testMartingaleInput()
	initialized, err := martingale.Initialize(input)
	if err != nil {
		t.Fatalf("Initialize returned error: %v", err)
	}

	input.Config.Values["max_order_quantity"] = "0.5"

	decision, err := martingale.OnTick(input.WithState(initialized.State))
	if err == nil || !strings.Contains(err.Error(), "max_order_quantity must be at least base_order_quantity") {
		t.Fatalf("OnTick error = %v, want max below base error", err)
	}
	if len(decision.Orders) != 0 {
		t.Fatalf("invalid existing-state tick emitted orders: %+v", decision.Orders)
	}
}
