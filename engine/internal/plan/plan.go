// Package plan evaluates a non-persisted bot setup with the same Go strategy
// implementations the runtime uses. It never touches a database or an exchange
// and makes no private calls. Laravel calls it for previews and for
// create/update validation so both paths cannot disagree.
package plan

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

const centerPriceBand = "0.5"

// Request is a preview/setup evaluation. Decimal fields accept strings or
// integers, matching the strategy configuration contract; floats are rejected.
type Request struct {
	StrategyKey       string         `json:"strategy_key"`
	Symbol            string         `json:"symbol"`
	Exchange          string         `json:"exchange"`
	Configuration     map[string]any `json:"configuration"`
	CapitalAllocation any            `json:"capital_allocation"`
	BaseInventory     any            `json:"base_inventory"`
	MarketPrice       any            `json:"market_price"`
	Limits            *Limits        `json:"limits"`
	Market            *Market        `json:"market"`
}

// Limits are the effective risk limits used for advisory warnings.
type Limits struct {
	MaxOpenOrders       any `json:"max_open_orders"`
	MaxPositionNotional any `json:"max_position_notional"`
}

// Market is the already-resolved public market data, if any.
type Market struct {
	Symbol          string `json:"symbol"`
	Last            any    `json:"last"`
	MinAmount       any    `json:"min_amount"`
	MinNotional     any    `json:"min_notional"`
	PricePrecision  *int   `json:"price_precision"`
	AmountPrecision *int   `json:"amount_precision"`
}

// Level is one planned grid level.
type Level struct {
	Sequence int    `json:"sequence"`
	Side     string `json:"side"`
	Price    string `json:"price"`
	Quantity string `json:"quantity"`
	Notional string `json:"notional"`
}

// Problem is a validation error or advisory warning.
type Problem struct {
	Code     string  `json:"code"`
	Field    *string `json:"field"`
	Message  string  `json:"message"`
	Severity string  `json:"severity"`
}

// Response mirrors App\Domain\Grid\ValueObjects\GridPlan::toArray.
type Response struct {
	StrategyKey   string    `json:"strategy_key"`
	CenterPrice   string    `json:"center_price"`
	OrderQuantity string    `json:"order_quantity"`
	SpacingType   string    `json:"spacing_type"`
	SpacingValue  string    `json:"spacing_value"`
	LevelsPerSide int       `json:"levels_per_side"`
	OrderCount    int       `json:"order_count"`
	BuyNotional   string    `json:"buy_notional"`
	SellNotional  string    `json:"sell_notional"`
	Levels        []Level   `json:"levels"`
	Errors        []Problem `json:"errors"`
	Warnings      []Problem `json:"warnings"`
	Market        *Market   `json:"market,omitempty"`
}

// Evaluate runs the Go strategy once against an empty state, exactly like the
// first preview tick.
func Evaluate(request Request) Response {
	configuration := strategy.Config{Key: request.StrategyKey, Values: request.Configuration}

	center, err := centerPrice(configuration, request.MarketPrice)
	if err != nil {
		return failedPlan(request, err, decimal.Zero())
	}

	baseInventory, err := decimalOrZero(request.BaseInventory)
	if err != nil {
		return failedPlan(request, err, center)
	}

	capital, err := decimalOrZero(request.CapitalAllocation)
	if err != nil {
		return failedPlan(request, err, center)
	}

	strategyImpl, err := strategy.NewStrategy(request.StrategyKey)
	if err != nil {
		return failedPlan(request, err, center)
	}

	// Laravel caps the form at 50 levels. Enforce the same bound here so a
	// direct internal caller cannot make the evaluator allocate a huge ladder.
	perSide, levelsErr := configuration.Int("levels_per_side", configuration.Get("level_count", nil))
	if levelsErr == nil && perSide > 50 {
		return failedPlan(request, fmt.Errorf("levels_per_side must be at most 50"), center)
	}

	marketPrice := center
	if !center.IsPositive() {
		marketPrice = decimal.FromInt(1)
	}

	decision, err := strategyImpl.OnTick(strategy.Input{
		Market: strategy.MarketSnapshot{Symbol: request.Symbol, Price: marketPrice},
		Bot: strategy.BotSnapshot{
			BotID:             0,
			BaseInventory:     baseInventory,
			QuoteInventory:    capital,
			AverageEntryPrice: decimal.Zero(),
			RealizedPnL:       decimal.Zero(),
			CapitalAllocation: capital,
		},
		Config: configuration,
		State:  strategy.NewState(),
	})
	if err != nil {
		return failedPlan(request, err, center)
	}

	quantities := make(map[int]decimal.Decimal, len(decision.Orders))
	for _, order := range decision.Orders {
		if order.GridLevelSequence != nil {
			quantities[*order.GridLevelSequence] = order.Quantity
		}
	}

	levels := make([]Level, 0, len(decision.Levels))
	buyNotional := decimal.Zero()
	sellNotional := decimal.Zero()
	orderCount := 0

	for _, level := range decision.Levels {
		quantity := decimal.Zero()
		if planned, ok := quantities[level.Sequence]; ok {
			quantity = planned
		}

		notional := level.Price.Mul(quantity)
		if quantity.IsPositive() {
			orderCount++
		}

		if level.Side == strategy.SideBuy {
			buyNotional = buyNotional.Add(notional)
		} else {
			sellNotional = sellNotional.Add(notional)
		}

		levels = append(levels, Level{
			Sequence: level.Sequence,
			Side:     string(level.Side),
			Price:    level.Price.String(),
			Quantity: quantity.String(),
			Notional: notional.String(),
		})
	}

	orderQuantity, err := orderQuantityOf(configuration, decision.Orders)
	if err != nil {
		return failedPlan(request, err, center)
	}

	spacingValue := decimal.Zero()
	if configuration.Has("spacing_value") {
		spacingValue, err = configuration.Decimal("spacing_value", nil)
		if err != nil {
			return failedPlan(request, err, center)
		}
	}

	levels, orderQuantity, precisionProblems := applyMarketPrecision(request.Market, levels, orderQuantity)

	buyNotional = decimal.Zero()
	sellNotional = decimal.Zero()
	orderCount = 0
	for _, level := range levels {
		notional := decimal.MustParse(level.Notional)
		if decimal.MustParse(level.Quantity).IsPositive() {
			orderCount++
		}
		if level.Side == string(strategy.SideBuy) {
			buyNotional = buyNotional.Add(notional)
		} else {
			sellNotional = sellNotional.Add(notional)
		}
	}

	problems := precisionProblems
	problems = append(problems, minimumProblems(request, levels, orderQuantity)...)
	problems = append(problems, warningProblems(request, center, orderCount, buyNotional, sellNotional, baseInventory)...)

	response := Response{
		StrategyKey:   request.StrategyKey,
		CenterPrice:   center.String(),
		OrderQuantity: orderQuantity.String(),
		SpacingType:   spacingTypeOf(request.Configuration),
		SpacingValue:  spacingValue.String(),
		LevelsPerSide: intOf(request.Configuration["levels_per_side"]),
		OrderCount:    orderCount,
		BuyNotional:   buyNotional.String(),
		SellNotional:  sellNotional.String(),
		Levels:        levels,
		Errors:        []Problem{},
		Warnings:      []Problem{},
	}

	for _, problem := range problems {
		if problem.Severity == "" {
			problem.Severity = "error"
		}

		if problem.Severity == "warning" {
			response.Warnings = append(response.Warnings, problem)
		} else {
			response.Errors = append(response.Errors, problem)
		}
	}

	response.Market = request.Market

	return response
}

func centerPrice(configuration strategy.Config, marketPrice any) (decimal.Decimal, error) {
	if configuration.Has("center_price") {
		return configuration.Decimal("center_price", nil)
	}

	if marketPrice != nil {
		return decimal.Of(marketPrice)
	}

	return decimal.Zero(), nil
}

func decimalOrZero(value any) (decimal.Decimal, error) {
	if value == nil {
		return decimal.Zero(), nil
	}

	return decimal.Of(value)
}

func orderQuantityOf(configuration strategy.Config, orders []strategy.OrderIntent) (decimal.Decimal, error) {
	if configuration.Has("order_quantity") {
		return configuration.Decimal("order_quantity", nil)
	}

	if len(orders) > 0 {
		return orders[0].Quantity, nil
	}

	return decimal.Zero(), nil
}

// applyMarketPrecision simulates the venue's truncation so a setup that would
// round to zero or below a minimum fails before it can be saved. It is an
// approximation: live still applies the authoritative tick/step filters.
func applyMarketPrecision(market *Market, levels []Level, orderQuantity decimal.Decimal) ([]Level, decimal.Decimal, []Problem) {
	if market == nil {
		return levels, orderQuantity, nil
	}

	var problems []Problem
	priceRounded := false
	quantityRounded := false
	pricePlaces := 0
	if market.PricePrecision != nil {
		pricePlaces = *market.PricePrecision
	}
	amountPlaces := 0
	if market.AmountPrecision != nil {
		amountPlaces = *market.AmountPrecision
	}

	adjusted := make([]Level, 0, len(levels))

	for _, level := range levels {
		price := decimal.MustParse(level.Price)
		if market.PricePrecision != nil {
			rounded, err := price.Round(*market.PricePrecision, decimal.RoundDown)
			if err == nil {
				price = rounded
			}
		}
		if !price.IsPositive() {
			if !priceRounded {
				problems = append(problems, Problem{
					Code:    "plan.precision",
					Field:   field("spacing_value"),
					Message: fmt.Sprintf("A level price rounds to zero at %d decimal places; increase spacing or use a larger center price.", pricePlaces),
				})
				priceRounded = true
			}
			price = decimal.Zero()
		}

		quantity := decimal.MustParse(level.Quantity)
		if quantity.IsPositive() && market.AmountPrecision != nil {
			rounded, err := quantity.Round(*market.AmountPrecision, decimal.RoundDown)
			if err == nil {
				quantity = rounded
			}
			if !quantity.IsPositive() && !quantityRounded {
				problems = append(problems, Problem{
					Code:    "plan.precision",
					Field:   field("order_quantity"),
					Message: fmt.Sprintf("Order quantity rounds to zero at %d decimal places; increase the order quantity.", amountPlaces),
				})
				quantityRounded = true
			}
		}

		notional := price.Mul(quantity)
		adjusted = append(adjusted, Level{
			Sequence: level.Sequence,
			Side:     level.Side,
			Price:    price.String(),
			Quantity: quantity.String(),
			Notional: notional.String(),
		})
	}

	if orderQuantity.IsPositive() && market.AmountPrecision != nil {
		rounded, err := orderQuantity.Round(*market.AmountPrecision, decimal.RoundDown)
		if err == nil {
			orderQuantity = rounded
		}
		if !orderQuantity.IsPositive() && !quantityRounded {
			problems = append(problems, Problem{
				Code:    "plan.precision",
				Field:   field("order_quantity"),
				Message: fmt.Sprintf("Order quantity rounds to zero at %d decimal places; increase the order quantity.", amountPlaces),
			})
		}
	}

	return adjusted, orderQuantity, problems
}

func minimumProblems(request Request, levels []Level, orderQuantity decimal.Decimal) []Problem {
	if request.Market == nil || len(levels) == 0 {
		return nil
	}

	marketSymbol := request.Market.Symbol
	if marketSymbol == "" {
		marketSymbol = request.Symbol
	}

	var problems []Problem

	minAmount, err := decimalOrZero(request.Market.MinAmount)
	if err == nil && minAmount.IsPositive() && orderQuantity.IsPositive() && orderQuantity.LessThan(minAmount) {
		problems = append(problems, Problem{
			Code:    "plan.minimum_amount",
			Field:   field("order_quantity"),
			Message: fmt.Sprintf("Order quantity %s is below the exchange minimum of %s for %s.", orderQuantity.String(), minAmount.String(), marketSymbol),
		})
	}

	minNotional, err := decimalOrZero(request.Market.MinNotional)
	if err == nil && minNotional.IsPositive() {
		var smallest *decimal.Decimal
		for _, level := range levels {
			notional, parseErr := decimal.Parse(level.Notional)
			if parseErr != nil || !notional.IsPositive() {
				continue
			}

			if smallest == nil || notional.LessThan(*smallest) {
				value := notional
				smallest = &value
			}
		}

		if smallest != nil && smallest.LessThan(minNotional) {
			problems = append(problems, Problem{
				Code:    "plan.minimum_notional",
				Field:   field("order_quantity"),
				Message: fmt.Sprintf("Order notional %s is below the exchange minimum of %s for %s.", smallest.String(), minNotional.String(), marketSymbol),
			})
		}
	}

	return problems
}

func warningProblems(
	request Request,
	center decimal.Decimal,
	orderCount int,
	buyNotional decimal.Decimal,
	sellNotional decimal.Decimal,
	baseInventory decimal.Decimal,
) []Problem {
	var problems []Problem

	if request.Limits != nil {
		maxOrders := 20
		if value, ok := intValue(request.Limits.MaxOpenOrders); ok {
			maxOrders = value
		}

		if orderCount > maxOrders {
			problems = append(problems, Problem{
				Code:     "plan.max_open_orders",
				Field:    field("level_count"),
				Message:  fmt.Sprintf("This setup places %d orders, above the current limit of %d. Reduce the level count or raise the limit on the Risk Limits page.", orderCount, maxOrders),
				Severity: "warning",
			})
		}

		maxNotional := decimal.MustParse("1000")
		if request.Limits.MaxPositionNotional != nil {
			if parsed, err := decimal.Of(request.Limits.MaxPositionNotional); err == nil {
				maxNotional = parsed
			}
		}

		if buyNotional.GreaterThan(maxNotional) {
			problems = append(problems, Problem{
				Code:     "plan.max_position_notional",
				Field:    field("capital_allocation"),
				Message:  fmt.Sprintf("Buy-side notional %s exceeds the current limit of %s. Reduce size or raise the limit on the Risk Limits page.", buyNotional.String(), maxNotional.String()),
				Severity: "warning",
			})
		}
	}

	if request.MarketPrice != nil && center.IsPositive() {
		if marketPrice, err := decimal.Of(request.MarketPrice); err == nil && marketPrice.IsPositive() {
			difference, err := center.Sub(marketPrice).Abs().Div(marketPrice)
			if err == nil && difference.GreaterThan(decimal.MustParse(centerPriceBand)) {
				percentage, err := difference.Mul(decimal.FromInt(100)).Round(2, decimal.RoundHalfUp)
				if err == nil {
					problems = append(problems, Problem{
						Code:     "plan.center_price_band",
						Field:    field("center_price"),
						Message:  fmt.Sprintf("The center price is %s%% away from the latest market price of %s.", percentage.String(), marketPrice.String()),
						Severity: "warning",
					})
				}
			}
		}
	}

	if sellNotional.IsPositive() && baseInventory.IsZero() {
		problems = append(problems, Problem{
			Code:     "plan.unfunded_sells",
			Field:    nil,
			Message:  "Sell levels stay deferred until buy orders fill because the bot starts with no base inventory.",
			Severity: "warning",
		})
	}

	return problems
}

func failedPlan(request Request, err error, center decimal.Decimal) Response {
	reason := err.Error()
	if index := strings.Index(reason, "]: "); index >= 0 {
		reason = reason[index+3:]
	}
	reason = strings.TrimRight(reason, ".")

	return Response{
		StrategyKey:   request.StrategyKey,
		CenterPrice:   center.String(),
		OrderQuantity: "0",
		SpacingType:   spacingTypeOf(request.Configuration),
		SpacingValue:  "0",
		LevelsPerSide: intOf(request.Configuration["levels_per_side"]),
		OrderCount:    0,
		BuyNotional:   "0",
		SellNotional:  "0",
		Levels:        []Level{},
		Errors: []Problem{{
			Code:     "plan.invalid_configuration",
			Field:    field(fieldFor(reason)),
			Message:  reason + ".",
			Severity: "error",
		}},
		Warnings: []Problem{},
		Market:   request.Market,
	}
}

func spacingTypeOf(configuration map[string]any) string {
	if value, ok := configuration["spacing_type"]; ok && value != nil {
		if text, ok := value.(string); ok && text != "" {
			return text
		}
	}

	return "arithmetic"
}

func fieldFor(reason string) string {
	switch {
	case strings.Contains(reason, "center_price"):
		return "center_price"
	case strings.Contains(reason, "levels_per_side"):
		return "level_count"
	case strings.Contains(reason, "spacing_type"):
		return "spacing_type"
	case strings.Contains(reason, "trailing_distance"):
		return "trailing_distance"
	case strings.Contains(reason, "size_multiplier"):
		return "size_multiplier"
	case strings.Contains(reason, "max_order_quantity"):
		return "max_order_quantity"
	case strings.Contains(reason, "max_increases"):
		return "max_increases"
	case strings.Contains(reason, "max_spacing"):
		return "max_spacing"
	case strings.Contains(reason, "min_spacing"):
		return "min_spacing"
	case strings.Contains(reason, "volatility_multiplier"):
		return "volatility_multiplier"
	case strings.Contains(reason, "recalculation_interval"):
		return "recalculation_interval_seconds"
	case strings.Contains(reason, "spacing_hysteresis_percent"):
		return "spacing_hysteresis_percent"
	case strings.Contains(reason, "order_quantity"):
		return "order_quantity"
	case strings.Contains(reason, "spacing"),
		strings.Contains(reason, "level price"),
		strings.Contains(reason, "duplicate"):
		return "spacing_value"
	default:
		return "strategy_key"
	}
}

func field(value string) *string {
	return &value
}

func intOf(value any) int {
	parsed, _ := intValue(value)

	return parsed
}

func intValue(value any) (int, bool) {
	switch typed := value.(type) {
	case nil:
		return 0, false
	case int:
		return typed, true
	case int64:
		return int(typed), true
	case json.Number:
		if strings.ContainsAny(typed.String(), ".eE") {
			return 0, false
		}
		parsed, err := strconv.Atoi(typed.String())

		return parsed, err == nil
	case string:
		parsed, err := strconv.Atoi(typed)

		return parsed, err == nil
	default:
		return 0, false
	}
}
