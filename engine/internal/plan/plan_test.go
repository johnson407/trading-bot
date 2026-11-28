package plan_test

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/plan"
)

func simpleGridRequest() plan.Request {
	return plan.Request{
		StrategyKey: "simple-grid",
		Symbol:      "BTC/USDT",
		Configuration: map[string]any{
			"center_price":    "50000",
			"levels_per_side": 6,
			"spacing_type":    "arithmetic",
			"spacing_value":   "100",
			"order_quantity":  "0.001",
		},
		CapitalAllocation: "1000",
		BaseInventory:     "0",
	}
}

func codes(problems []plan.Problem) []string {
	result := make([]string, 0, len(problems))
	for _, problem := range problems {
		result = append(result, problem.Code)
	}

	return result
}

func TestEvaluateSimpleGridMatchesPreviewTotals(t *testing.T) {
	response := plan.Evaluate(simpleGridRequest())

	if len(response.Errors) != 0 {
		t.Fatalf("errors = %+v, want none", response.Errors)
	}
	if len(response.Levels) != 12 {
		t.Fatalf("levels = %d, want 12", len(response.Levels))
	}
	if response.Levels[0].Sequence != 1 || response.Levels[0].Side != "buy" || response.Levels[0].Price != "49400" {
		t.Errorf("first level = %+v, want buy 49400 sequence 1", response.Levels[0])
	}
	if response.Levels[5].Price != "49900" || response.Levels[6].Price != "50100" {
		t.Errorf("center-adjacent levels = %s/%s, want 49900/50100", response.Levels[5].Price, response.Levels[6].Price)
	}
	if response.Levels[11].Side != "sell" || response.Levels[11].Price != "50600" {
		t.Errorf("last level = %+v, want sell 50600", response.Levels[11])
	}
	if response.OrderCount != 12 {
		t.Errorf("order count = %d, want 12", response.OrderCount)
	}
	if response.BuyNotional != "297.9" || response.SellNotional != "302.1" {
		t.Errorf("notional = %s/%s, want 297.9/302.1", response.BuyNotional, response.SellNotional)
	}
	if response.OrderQuantity != "0.001" || response.LevelsPerSide != 6 || response.SpacingType != "arithmetic" {
		t.Errorf("summary = %+v", response)
	}
}

func TestEvaluateMapsInvalidConfigurationToTheSameField(t *testing.T) {
	request := simpleGridRequest()
	request.Configuration["spacing_type"] = "geometric"
	request.Configuration["spacing_value"] = "1"

	response := plan.Evaluate(request)

	if len(response.Errors) != 1 {
		t.Fatalf("errors = %+v, want one", response.Errors)
	}
	if response.Errors[0].Code != "plan.invalid_configuration" {
		t.Errorf("code = %s, want plan.invalid_configuration", response.Errors[0].Code)
	}
	if response.Errors[0].Field == nil || *response.Errors[0].Field != "spacing_value" {
		t.Errorf("field = %v, want spacing_value", response.Errors[0].Field)
	}
	if !strings.HasSuffix(response.Errors[0].Message, ".") {
		t.Errorf("message = %q, want a trailing period", response.Errors[0].Message)
	}
}

func TestEvaluateMapsRejectedDynamicSpacingToSpacingType(t *testing.T) {
	cases := []struct {
		name    string
		request plan.Request
	}{
		{
			name: "trailing",
			request: plan.Request{
				StrategyKey: "trailing-grid",
				Configuration: map[string]any{
					"center_price":      "100",
					"levels_per_side":   2,
					"spacing_type":      "dynamic",
					"spacing_value":     "10",
					"order_quantity":    "1",
					"trailing_distance": "10",
				},
			},
		},
		{
			name: "martingale",
			request: plan.Request{
				StrategyKey: "martingale-grid",
				Configuration: map[string]any{
					"center_price":        "100",
					"levels_per_side":     2,
					"spacing_type":        "dynamic",
					"spacing_value":       "10",
					"base_order_quantity": "1",
					"size_multiplier":     "2",
					"max_order_quantity":  "10",
					"max_increases":       3,
				},
			},
		},
	}

	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			response := plan.Evaluate(testCase.request)

			if len(response.Errors) != 1 {
				t.Fatalf("errors = %+v, want one", response.Errors)
			}
			if response.Errors[0].Field == nil || *response.Errors[0].Field != "spacing_type" {
				t.Errorf("field = %v, want spacing_type", response.Errors[0].Field)
			}
		})
	}
}

func TestEvaluateMapsInvalidHysteresisToItsField(t *testing.T) {
	request := plan.Request{
		StrategyKey: "dynamic-grid",
		Symbol:      "BTC/USDT",
		Configuration: map[string]any{
			"center_price":               "50000",
			"levels_per_side":            2,
			"min_spacing":                "50",
			"max_spacing":                "500",
			"order_quantity":             "0.001",
			"spacing_hysteresis_percent": "91",
		},
		CapitalAllocation: "1000",
		BaseInventory:     "0",
	}

	response := plan.Evaluate(request)

	if len(response.Errors) != 1 {
		t.Fatalf("errors = %+v, want one", response.Errors)
	}
	if response.Errors[0].Field == nil || *response.Errors[0].Field != "spacing_hysteresis_percent" {
		t.Errorf("field = %v, want spacing_hysteresis_percent", response.Errors[0].Field)
	}
}

func TestEvaluateReportsExchangeMinimumsAndAdvisories(t *testing.T) {
	request := simpleGridRequest()
	request.Configuration["order_quantity"] = "0.001"
	request.MarketPrice = "120000"
	request.Market = &plan.Market{
		Symbol:      "BTC/USDT",
		Last:        "120000",
		MinAmount:   "0.01",
		MinNotional: "1000",
	}
	request.Limits = &plan.Limits{MaxOpenOrders: 10, MaxPositionNotional: "100"}

	response := plan.Evaluate(request)

	if got := codes(response.Errors); len(got) != 2 || got[0] != "plan.minimum_amount" || got[1] != "plan.minimum_notional" {
		t.Errorf("errors = %v, want minimum amount and notional", got)
	}

	warnings := codes(response.Warnings)
	for _, want := range []string{"plan.max_open_orders", "plan.max_position_notional", "plan.center_price_band", "plan.unfunded_sells"} {
		found := false
		for _, code := range warnings {
			if code == want {
				found = true
			}
		}
		if !found {
			t.Errorf("warnings = %v, want %s", warnings, want)
		}
	}
}

func TestEvaluateRejectsPriceThatRoundsToZero(t *testing.T) {
	request := simpleGridRequest()
	request.Configuration["center_price"] = "0.03"
	request.Configuration["spacing_value"] = "0.01"
	request.Configuration["levels_per_side"] = 1
	request.Configuration["order_quantity"] = "1"
	pricePrecision := 0
	request.Market = &plan.Market{PricePrecision: &pricePrecision}

	response := plan.Evaluate(request)

	if len(response.Errors) != 1 || response.Errors[0].Code != "plan.precision" {
		t.Fatalf("errors = %+v, want one precision error", response.Errors)
	}
	if response.Errors[0].Field == nil || *response.Errors[0].Field != "spacing_value" {
		t.Errorf("field = %v, want spacing_value", response.Errors[0].Field)
	}
}

func TestEvaluateRejectsQuantityThatRoundsToZero(t *testing.T) {
	request := simpleGridRequest()
	request.Configuration["order_quantity"] = "0.001"
	amountPrecision := 2
	request.Market = &plan.Market{AmountPrecision: &amountPrecision}

	response := plan.Evaluate(request)

	if len(response.Errors) != 1 || response.Errors[0].Code != "plan.precision" {
		t.Fatalf("errors = %+v, want one precision error", response.Errors)
	}
	if response.Errors[0].Field == nil || *response.Errors[0].Field != "order_quantity" {
		t.Errorf("field = %v, want order_quantity", response.Errors[0].Field)
	}
}

func TestEvaluateTruncatesPricesAndQuantitiesBeforeMinimums(t *testing.T) {
	request := simpleGridRequest()
	request.Configuration["center_price"] = "100"
	request.Configuration["spacing_value"] = "10"
	request.Configuration["levels_per_side"] = 1
	request.Configuration["order_quantity"] = "1.239"
	pricePrecision := 1
	amountPrecision := 2
	request.Market = &plan.Market{
		PricePrecision:  &pricePrecision,
		AmountPrecision: &amountPrecision,
		MinNotional:     "110.8",
	}

	response := plan.Evaluate(request)

	if len(response.Errors) != 1 || response.Errors[0].Code != "plan.minimum_notional" {
		t.Fatalf("errors = %+v, want one minimum notional error", response.Errors)
	}
	if response.Levels[0].Price != "90" || response.Levels[1].Price != "110" {
		t.Errorf("levels = %+v, want truncated 90/110", response.Levels)
	}
	if response.Levels[0].Quantity != "1.23" || response.Levels[0].Notional != "110.7" {
		t.Errorf("level = %+v, want quantity 1.23 notional 110.7", response.Levels[0])
	}
	if response.OrderQuantity != "1.23" {
		t.Errorf("order quantity = %s, want 1.23", response.OrderQuantity)
	}
}

func TestEvaluateCapsTheLadderAtFiftyLevels(t *testing.T) {
	response := plan.Evaluate(plan.Request{
		StrategyKey: "simple-grid",
		Configuration: map[string]any{
			"center_price":    "100",
			"levels_per_side": 51,
			"spacing_type":    "arithmetic",
			"spacing_value":   "1",
			"order_quantity":  "1",
		},
	})

	if len(response.Errors) != 1 || response.Errors[0].Field == nil || *response.Errors[0].Field != "level_count" {
		t.Fatalf("errors = %#v, want one level_count error", response.Errors)
	}
	if len(response.Levels) != 0 {
		t.Errorf("levels = %d, want none", len(response.Levels))
	}
}

func TestEvaluateRejectsUnknownStrategyWithoutPanicking(t *testing.T) {
	request := simpleGridRequest()
	request.StrategyKey = "nope"

	response := plan.Evaluate(request)

	if len(response.Errors) != 1 || response.Errors[0].Field == nil || *response.Errors[0].Field != "strategy_key" {
		t.Fatalf("response = %+v, want a strategy_key error", response)
	}
}

func TestHandlerRequiresTokenAndRejectsMalformedJSON(t *testing.T) {
	handler := plan.NewHandler("secret", nil)

	body, _ := json.Marshal(simpleGridRequest())

	request := httptest.NewRequest(http.MethodPost, "/v1/plan/evaluate", bytes.NewReader(body))
	response := httptest.NewRecorder()
	handler.ServeHTTP(response, request)
	if response.Code != http.StatusUnauthorized {
		t.Errorf("status = %d, want 401 without a token", response.Code)
	}

	request = httptest.NewRequest(http.MethodPost, "/v1/plan/evaluate", strings.NewReader("{"))
	request.Header.Set("Authorization", "Bearer secret")
	response = httptest.NewRecorder()
	handler.ServeHTTP(response, request)
	if response.Code != http.StatusBadRequest {
		t.Errorf("status = %d, want 400 for malformed JSON", response.Code)
	}

	request = httptest.NewRequest(http.MethodPost, "/v1/plan/evaluate", bytes.NewReader(body))
	request.Header.Set("Authorization", "Bearer secret")
	response = httptest.NewRecorder()
	handler.ServeHTTP(response, request)
	if response.Code != http.StatusOK {
		t.Errorf("status = %d, want 200", response.Code)
	}

	var decoded plan.Response
	if err := json.Unmarshal(response.Body.Bytes(), &decoded); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if decoded.StrategyKey != "simple-grid" || len(decoded.Levels) != 12 {
		t.Errorf("response = %+v", decoded)
	}
}
