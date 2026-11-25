package strategy

import (
	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// TrailingGrid is the runtime trailing-grid strategy.
type TrailingGrid struct{}

// NewTrailingGrid returns the trailing-grid strategy.
func NewTrailingGrid() *TrailingGrid {
	return &TrailingGrid{}
}

// Key mirrors TrailingGridStrategy::KEY.
func (t *TrailingGrid) Key() string {
	return "trailing-grid"
}

// Initialize mirrors TrailingGridStrategy::initialize.
func (t *TrailingGrid) Initialize(input Input) (Decision, error) {
	if !input.State.IsNew() {
		return idle(input.State), nil
	}

	center, err := t.initialCenter(input)
	if err != nil {
		return Decision{}, err
	}

	levels, err := t.levelsFor(input, center)
	if err != nil {
		return Decision{}, err
	}

	spacingType, err := t.spacingType(input)
	if err != nil {
		return Decision{}, err
	}

	spacing, err := t.spacingValue(input)
	if err != nil {
		return Decision{}, err
	}

	perSide, err := levelsPerSide(t.Key(), input.Config)
	if err != nil {
		return Decision{}, err
	}

	distance, err := t.trailingDistance(input)
	if err != nil {
		return Decision{}, err
	}

	state := input.State.Merge(map[string]any{
		"initialized":       true,
		"center_price":      center.String(),
		"spacing_type":      string(spacingType),
		"spacing_value":     spacing.String(),
		"levels_per_side":   perSide,
		"trailing_distance": distance.String(),
		"recenter_count":    0,
		"last_fill_side":    nil,
		"fill_count":        0,
	})

	return Decision{State: state, Levels: levels}, nil
}

// OnTick mirrors TrailingGridStrategy::onTick.
func (t *TrailingGrid) OnTick(input Input) (Decision, error) {
	if input.State.IsNew() {
		initialized, err := t.Initialize(input)
		if err != nil {
			return Decision{}, err
		}

		orders, err := t.orderIntents(input.WithState(initialized.State), initialized.Levels)
		if err != nil {
			return Decision{}, err
		}

		initialized.Orders = orders

		return initialized, nil
	}

	center, err := decimal.Of(input.State.Get("center_price", nil))
	if err != nil {
		return Decision{}, err
	}

	distance, err := t.trailingDistance(input)
	if err != nil {
		return Decision{}, err
	}

	if input.Market.Price.Sub(center).Abs().LessThan(distance) {
		levels, err := t.GetGridLevels(input)
		if err != nil {
			return Decision{}, err
		}

		orders, err := t.orderIntents(input, levels)
		if err != nil {
			return Decision{}, err
		}

		return Decision{State: input.State, Levels: levels, Orders: orders}, nil
	}

	newCenter := input.Market.Price

	levels, err := t.levelsFor(input, newCenter)
	if err != nil {
		return Decision{}, err
	}

	levelPrices := make(map[string]struct{}, len(levels))
	for _, level := range levels {
		levelPrices[level.Price.String()] = struct{}{}
	}

	var cancels []string

	remaining := make([]OpenOrderSnapshot, 0, len(input.Bot.OpenOrders))

	for _, order := range input.Bot.OpenOrders {
		if _, present := levelPrices[order.Price.String()]; present {
			remaining = append(remaining, order)
		} else {
			cancels = append(cancels, order.ClientOrderID)
		}
	}

	state := input.State.Merge(map[string]any{
		"center_price":        newCenter.String(),
		"recenter_count":      asInt(input.State.Get("recenter_count", 0)) + 1,
		"last_recenter_price": newCenter.String(),
	})

	input = input.WithState(state).WithBot(input.Bot.WithOpenOrders(remaining))

	orders, err := t.orderIntents(input, levels)
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

// OnFill mirrors TrailingGridStrategy::onFill.
func (t *TrailingGrid) OnFill(input Input, fill FillEvent) (Decision, error) {
	base := input.State.Merge(map[string]any{
		"last_fill_side": string(fill.Side),
		"fill_count":     asInt(input.State.Get("fill_count", 0)) + 1,
	})

	levels, err := t.GetGridLevels(input.WithState(base))
	if err != nil {
		return Decision{}, err
	}

	adjacent := adjacentLevel(levels, fill.GridLevelSequence, fill.Side)
	state := ApplyFillToLevelState(base, fill, adjacent)

	var orders []OrderIntent
	if adjacent != nil {
		orders = append(orders, OrderIntent{
			Side:              adjacent.Side,
			Price:             adjacent.Price,
			Quantity:          fill.Quantity,
			GridLevelSequence: intPointer(adjacent.Sequence),
			Reason:            "strategy.opposite",
		})
	}

	return Decision{State: state, Levels: levels, Orders: orders}, nil
}

// OnCancel mirrors TrailingGridStrategy::onCancel.
func (t *TrailingGrid) OnCancel(input Input, cancel CancelEvent) (Decision, error) {
	return idle(input.State.Merge(map[string]any{
		"last_canceled_client_order_id": cancel.ClientOrderID,
	})), nil
}

// ShouldStop mirrors TrailingGridStrategy::shouldStop.
func (t *TrailingGrid) ShouldStop(input Input) bool {
	return false
}

// GetGridLevels mirrors TrailingGridStrategy::getGridLevels.
func (t *TrailingGrid) GetGridLevels(input Input) ([]GridLevelPlan, error) {
	if input.State.IsNew() {
		center, err := t.initialCenter(input)
		if err != nil {
			return nil, err
		}

		return t.levelsFor(input, center)
	}

	center, err := decimal.Of(input.State.Get("center_price", nil))
	if err != nil {
		return nil, err
	}

	return t.levelsFor(input, center)
}

func (t *TrailingGrid) orderIntents(input Input, levels []GridLevelPlan) ([]OrderIntent, error) {
	quantity, err := orderQuantity(t.Key(), input.Config)
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

func (t *TrailingGrid) levelsFor(input Input, center decimal.Decimal) ([]GridLevelPlan, error) {
	spacingType, err := t.spacingType(input)
	if err != nil {
		return nil, err
	}

	spacing, err := t.spacingValue(input)
	if err != nil {
		return nil, err
	}

	perSide, err := levelsPerSide(t.Key(), input.Config)
	if err != nil {
		return nil, err
	}

	return BuildLevels(t.Key(), center, spacingType, spacing, perSide)
}

func (t *TrailingGrid) initialCenter(input Input) (decimal.Decimal, error) {
	center := input.Market.Price

	if input.Config.Has("center_price") {
		value, err := input.Config.Decimal("center_price", nil)
		if err != nil {
			return decimal.Decimal{}, err
		}

		center = value
	}

	if !center.IsPositive() {
		return decimal.Decimal{}, invalidConfig(t.Key(), "center_price must be positive")
	}

	return center, nil
}

func (t *TrailingGrid) trailingDistance(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("trailing_distance", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !value.IsPositive() {
		return decimal.Decimal{}, invalidConfig(t.Key(), "trailing_distance must be positive")
	}

	return value, nil
}

func (t *TrailingGrid) spacingType(input Input) (SpacingType, error) {
	value := input.Config.Get("spacing_type", string(SpacingArithmetic))

	text, ok := value.(string)
	if !ok {
		return "", invalidConfig(t.Key(), "spacing_type must be arithmetic or geometric")
	}

	spacingType, err := parseSpacingType(text)
	if err != nil {
		return "", invalidConfig(t.Key(), "spacing_type must be arithmetic or geometric")
	}

	return spacingType, nil
}

func (t *TrailingGrid) spacingValue(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("spacing_value", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !value.IsPositive() {
		return decimal.Decimal{}, invalidConfig(t.Key(), "spacing_value must be positive")
	}

	return value, nil
}
