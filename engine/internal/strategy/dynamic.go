package strategy

import (
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// DynamicGrid is the runtime dynamic-grid strategy.
type DynamicGrid struct{}

// NewDynamicGrid returns the dynamic-grid strategy.
func NewDynamicGrid() *DynamicGrid {
	return &DynamicGrid{}
}

// Key mirrors DynamicGridStrategy::KEY.
func (d *DynamicGrid) Key() string {
	return "dynamic-grid"
}

// Initialize mirrors DynamicGridStrategy::initialize.
func (d *DynamicGrid) Initialize(input Input) (Decision, error) {
	if _, err := d.spacingHysteresis(input); err != nil {
		return Decision{}, err
	}

	if !input.State.IsNew() {
		return idle(input.State), nil
	}

	center, err := d.centerPrice(input)
	if err != nil {
		return Decision{}, err
	}

	spacing, err := d.calculateSpacing(input, center)
	if err != nil {
		return Decision{}, err
	}

	levels, err := d.levelsFor(input, center, spacing)
	if err != nil {
		return Decision{}, err
	}

	perSide, err := levelsPerSide(d.Key(), input.Config)
	if err != nil {
		return Decision{}, err
	}

	state := input.State.Merge(map[string]any{
		"initialized":          true,
		"center_price":         center.String(),
		"spacing_value":        spacing.String(),
		"levels_per_side":      perSide,
		"last_recalculated_at": formatDateTimeAtom(timestampOf(input.Market)),
		"recalculation_count":  0,
		"fill_count":           0,
	})

	return Decision{State: state, Levels: levels}, nil
}

// OnTick mirrors DynamicGridStrategy::onTick.
func (d *DynamicGrid) OnTick(input Input) (Decision, error) {
	hysteresis, err := d.spacingHysteresis(input)
	if err != nil {
		return Decision{}, err
	}

	if input.State.IsNew() {
		initialized, err := d.Initialize(input)
		if err != nil {
			return Decision{}, err
		}

		orders, err := d.orderIntents(input.WithState(initialized.State), initialized.Levels)
		if err != nil {
			return Decision{}, err
		}

		initialized.Orders = orders

		return initialized, nil
	}

	last, err := lastRecalculatedAt(input.State)
	if err != nil {
		return Decision{}, err
	}

	interval, err := d.recalculationInterval(input)
	if err != nil {
		return Decision{}, err
	}

	if timestampOf(input.Market).Unix()-last.Unix() < int64(interval) {
		levels, err := d.GetGridLevels(input)
		if err != nil {
			return Decision{}, err
		}

		return Decision{State: input.State, Levels: levels}, nil
	}

	center, err := decimal.Of(input.State.Get("center_price", nil))
	if err != nil {
		return Decision{}, err
	}

	spacing, err := d.calculateSpacing(input, center)
	if err != nil {
		return Decision{}, err
	}

	current, err := persistedSpacing(input.State)
	if err != nil {
		return Decision{}, err
	}

	if hysteresis.IsPositive() {
		delta := spacing.Sub(current).Abs().Mul(decimal.FromInt(100))
		threshold := hysteresis.Mul(current)

		if delta.LessThan(threshold) {
			levels, err := d.levelsFor(input, center, current)
			if err != nil {
				return Decision{}, err
			}

			state := input.State.Merge(map[string]any{
				"spacing_value":        current.String(),
				"last_recalculated_at": formatDateTimeAtom(timestampOf(input.Market)),
				"recalculation_count":  asInt(input.State.Get("recalculation_count", 0)) + 1,
			})

			orders, err := d.orderIntents(input.WithState(state), levels)
			if err != nil {
				return Decision{}, err
			}

			return Decision{State: state, Levels: levels, Orders: orders}, nil
		}
	}

	levels, err := d.levelsFor(input, center, spacing)
	if err != nil {
		return Decision{}, err
	}

	prices := make(map[string]struct{}, len(levels))
	for _, level := range levels {
		prices[level.Price.String()] = struct{}{}
	}

	var cancels []string

	remaining := make([]OpenOrderSnapshot, 0, len(input.Bot.OpenOrders))

	for _, order := range input.Bot.OpenOrders {
		if _, present := prices[order.Price.String()]; present {
			remaining = append(remaining, order)
		} else {
			cancels = append(cancels, order.ClientOrderID)
		}
	}

	state := input.State.Merge(map[string]any{
		"spacing_value":        spacing.String(),
		"last_recalculated_at": formatDateTimeAtom(timestampOf(input.Market)),
		"recalculation_count":  asInt(input.State.Get("recalculation_count", 0)) + 1,
	})

	input = input.WithState(state).WithBot(input.Bot.WithOpenOrders(remaining))

	orders, err := d.orderIntents(input, levels)
	if err != nil {
		return Decision{}, err
	}

	return Decision{
		State:                state,
		Levels:               levels,
		Orders:               orders,
		CancelClientOrderIDs: cancels,
	}, nil
}

// OnFill mirrors DynamicGridStrategy::onFill.
func (d *DynamicGrid) OnFill(input Input, fill FillEvent) (Decision, error) {
	base := input.State.Merge(map[string]any{
		"last_fill_side": string(fill.Side),
		"fill_count":     asInt(input.State.Get("fill_count", 0)) + 1,
	})

	levels, err := d.GetGridLevels(input.WithState(base))
	if err != nil {
		return Decision{}, err
	}

	adjacent := adjacentLevel(levels, fill.GridLevelSequence, fill.Side)
	state := ApplyFillToLevelState(base, fill, adjacent)

	var orders []OrderIntent
	if adjacent != nil {
		orders = append(orders, OrderIntent{
			Side:              oppositeSide(fill.Side),
			Price:             adjacent.Price,
			Quantity:          fill.Quantity,
			GridLevelSequence: intPointer(adjacent.Sequence),
			Reason:            "strategy.opposite",
		})
	}

	return Decision{State: state, Levels: levels, Orders: orders}, nil
}

// OnCancel mirrors DynamicGridStrategy::onCancel.
func (d *DynamicGrid) OnCancel(input Input, cancel CancelEvent) (Decision, error) {
	return idle(input.State.Merge(map[string]any{
		"last_canceled_client_order_id": cancel.ClientOrderID,
	})), nil
}

// ShouldStop mirrors DynamicGridStrategy::shouldStop.
func (d *DynamicGrid) ShouldStop(input Input) bool {
	return false
}

// GetGridLevels mirrors DynamicGridStrategy::getGridLevels.
func (d *DynamicGrid) GetGridLevels(input Input) ([]GridLevelPlan, error) {
	if input.State.IsNew() {
		center, err := d.centerPrice(input)
		if err != nil {
			return nil, err
		}

		spacing, err := d.calculateSpacing(input, center)
		if err != nil {
			return nil, err
		}

		return d.levelsFor(input, center, spacing)
	}

	center, err := decimal.Of(input.State.Get("center_price", nil))
	if err != nil {
		return nil, err
	}

	spacing, err := persistedSpacing(input.State)
	if err != nil {
		return nil, err
	}

	return d.levelsFor(input, center, spacing)
}

func (d *DynamicGrid) orderIntents(input Input, levels []GridLevelPlan) ([]OrderIntent, error) {
	quantity, err := orderQuantity(d.Key(), input.Config)
	if err != nil {
		return nil, err
	}

	orders := make([]OrderIntent, 0, len(levels))

	for _, level := range levels {
		if !LevelIsArmed(input.State, level) {
			continue
		}

		if input.Bot.HasOpenOrderAt(level.Price) {
			continue
		}

		orders = append(orders, OrderIntent{
			Side:              level.Side,
			Price:             level.Price,
			Quantity:          quantity,
			GridLevelSequence: intPointer(level.Sequence),
			Reason:            "strategy.tick",
		})
	}

	return orders, nil
}

func (d *DynamicGrid) levelsFor(
	input Input,
	center decimal.Decimal,
	spacing decimal.Decimal,
) ([]GridLevelPlan, error) {
	perSide, err := levelsPerSide(d.Key(), input.Config)
	if err != nil {
		return nil, err
	}

	return BuildLevels(d.Key(), center, SpacingArithmetic, spacing, perSide)
}

func (d *DynamicGrid) calculateSpacing(input Input, center decimal.Decimal) (decimal.Decimal, error) {
	prices := make([]decimal.Decimal, 0, len(input.Market.RecentPrices)+1)
	prices = append(prices, input.Market.RecentPrices...)
	prices = append(prices, input.Market.Price)

	if len(prices) < 2 {
		return d.initialSpacing(input)
	}

	minimum := prices[0]
	maximum := prices[0]

	for _, price := range prices {
		if minimum.LessThan(price) {
			// keep minimum
		} else {
			minimum = price
		}

		if maximum.GreaterThan(price) {
			// keep maximum
		} else {
			maximum = price
		}
	}

	midpoint, err := minimum.Add(maximum).Div(decimal.FromInt(2))
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !midpoint.IsPositive() {
		initial, err := d.initialSpacing(input)
		if err != nil {
			return decimal.Decimal{}, err
		}

		return d.clamp(initial, input)
	}

	volatility, err := maximum.Sub(minimum).Div(midpoint)
	if err != nil {
		return decimal.Decimal{}, err
	}

	spacing := center.Mul(volatility)

	multiplier, err := d.volatilityMultiplier(input)
	if err != nil {
		return decimal.Decimal{}, err
	}

	spacing = spacing.Mul(multiplier)

	return d.clamp(spacing, input)
}

func (d *DynamicGrid) clamp(spacing decimal.Decimal, input Input) (decimal.Decimal, error) {
	minimum, err := d.minSpacing(input)
	if err != nil {
		return decimal.Decimal{}, err
	}

	maximum, err := d.maxSpacing(input)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if spacing.LessThan(minimum) {
		return minimum, nil
	}

	if spacing.GreaterThan(maximum) {
		return maximum, nil
	}

	return spacing, nil
}

func (d *DynamicGrid) centerPrice(input Input) (decimal.Decimal, error) {
	center := input.Market.Price

	if input.Config.Has("center_price") {
		value, err := input.Config.Decimal("center_price", nil)
		if err != nil {
			return decimal.Decimal{}, err
		}

		center = value
	}

	if !center.IsPositive() {
		return decimal.Decimal{}, invalidConfig(d.Key(), "center_price must be positive")
	}

	return center, nil
}

func persistedSpacing(state State) (decimal.Decimal, error) {
	spacing, err := decimal.Of(state.Get("spacing_value", nil))
	if err != nil || !spacing.IsPositive() {
		return decimal.Decimal{}, invalidConfig("dynamic-grid", "spacing_value must be a positive decimal")
	}

	return spacing, nil
}

func (d *DynamicGrid) spacingHysteresis(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("spacing_hysteresis_percent", "20")
	if err != nil {
		return decimal.Decimal{}, invalidConfig(d.Key(), "spacing_hysteresis_percent must be a decimal number")
	}

	if value.LessThan(decimal.Zero()) || value.GreaterThan(decimal.FromInt(90)) {
		return decimal.Decimal{}, invalidConfig(d.Key(), "spacing_hysteresis_percent must be between 0 and 90")
	}

	return value, nil
}

func (d *DynamicGrid) minSpacing(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("min_spacing", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !value.IsPositive() {
		return decimal.Decimal{}, invalidConfig(d.Key(), "min_spacing must be positive")
	}

	return value, nil
}

func (d *DynamicGrid) maxSpacing(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("max_spacing", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	minimum, err := d.minSpacing(input)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if value.LessThan(minimum) {
		return decimal.Decimal{}, invalidConfig(d.Key(), "max_spacing must be at least min_spacing")
	}

	return value, nil
}

func (d *DynamicGrid) initialSpacing(input Input) (decimal.Decimal, error) {
	if input.Config.Has("initial_spacing") {
		return input.Config.Decimal("initial_spacing", nil)
	}

	return d.minSpacing(input)
}

func (d *DynamicGrid) volatilityMultiplier(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("volatility_multiplier", "1")
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !value.IsPositive() {
		return decimal.Decimal{}, invalidConfig(d.Key(), "volatility_multiplier must be positive")
	}

	return value, nil
}

func (d *DynamicGrid) recalculationInterval(input Input) (int, error) {
	value, ok := configInt(input.Config.Get("recalculation_interval_seconds", 60))
	if !ok {
		return 0, invalidConfig(d.Key(), "recalculation_interval_seconds must be an integer")
	}

	if value < 0 {
		return 0, invalidConfig(d.Key(), "recalculation_interval_seconds must not be negative")
	}

	return value, nil
}

func lastRecalculatedAt(state State) (time.Time, error) {
	value := state.Get("last_recalculated_at", "")

	text, ok := value.(string)
	if !ok || text == "" {
		return time.Now().UTC(), nil
	}

	return time.Parse(time.RFC3339, text)
}
