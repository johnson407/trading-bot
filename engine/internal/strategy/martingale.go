package strategy

import (
	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// MartingaleGrid ports App\Strategies\MartingaleGridStrategy.
type MartingaleGrid struct{}

// NewMartingaleGrid returns the martingale-grid strategy.
func NewMartingaleGrid() *MartingaleGrid {
	return &MartingaleGrid{}
}

// Key mirrors MartingaleGridStrategy::KEY.
func (m *MartingaleGrid) Key() string {
	return "martingale-grid"
}

// Initialize mirrors MartingaleGridStrategy::initialize.
func (m *MartingaleGrid) Initialize(input Input) (Decision, error) {
	if !input.State.IsNew() {
		return idle(input.State), nil
	}

	center, err := m.centerPrice(input)
	if err != nil {
		return Decision{}, err
	}

	levels, err := m.levelsFor(input, center)
	if err != nil {
		return Decision{}, err
	}

	spacingType, err := m.spacingType(input)
	if err != nil {
		return Decision{}, err
	}

	spacing, err := m.spacingValue(input)
	if err != nil {
		return Decision{}, err
	}

	perSide, err := levelsPerSide(m.Key(), input.Config)
	if err != nil {
		return Decision{}, err
	}

	base, err := m.baseQuantity(input)
	if err != nil {
		return Decision{}, err
	}

	state := input.State.Merge(map[string]any{
		"initialized":      true,
		"center_price":     center.String(),
		"spacing_type":     string(spacingType),
		"spacing_value":    spacing.String(),
		"levels_per_side":  perSide,
		"current_quantity": base.String(),
		"increase_count":   0,
		"cap_reached":      false,
		"fill_count":       0,
	})

	return Decision{State: state, Levels: levels}, nil
}

// OnTick mirrors MartingaleGridStrategy::onTick.
func (m *MartingaleGrid) OnTick(input Input) (Decision, error) {
	if input.State.IsNew() {
		initialized, err := m.Initialize(input)
		if err != nil {
			return Decision{}, err
		}

		orders, err := m.orderIntents(input.WithState(initialized.State), initialized.Levels)
		if err != nil {
			return Decision{}, err
		}

		initialized.Orders = orders

		return initialized, nil
	}

	levels, err := m.GetGridLevels(input)
	if err != nil {
		return Decision{}, err
	}

	orders, err := m.orderIntents(input, levels)
	if err != nil {
		return Decision{}, err
	}

	return Decision{State: input.State, Levels: levels, Orders: orders}, nil
}

// OnFill mirrors MartingaleGridStrategy::onFill.
func (m *MartingaleGrid) OnFill(input Input, fill FillEvent) (Decision, error) {
	base, err := m.baseQuantity(input)
	if err != nil {
		return Decision{}, err
	}

	current, err := decimal.Of(input.State.Get("current_quantity", base.String()))
	if err != nil {
		return Decision{}, err
	}

	increaseCount := asInt(input.State.Get("increase_count", 0))

	maxIncreases, err := m.maxIncreases(input)
	if err != nil {
		return Decision{}, err
	}

	maxQuantity, err := m.maxQuantity(input)
	if err != nil {
		return Decision{}, err
	}

	capReached := stateBool(input.State.Get("cap_reached", false))

	var events []any

	next := current

	if !capReached && increaseCount < maxIncreases {
		multiplier, err := m.sizeMultiplier(input)
		if err != nil {
			return Decision{}, err
		}

		candidate := current.Mul(multiplier)
		if candidate.GreaterThan(maxQuantity) {
			next = maxQuantity
		} else {
			next = candidate
		}

		increaseCount++

		if next.GreaterThanOrEqual(maxQuantity) || increaseCount >= maxIncreases {
			capReached = true
			events = append(events, map[string]any{
				"type":    "martingale.cap_reached",
				"message": "Martingale size cap reached",
				"context": map[string]any{
					"quantity":           next.String(),
					"increase_count":     increaseCount,
					"max_order_quantity": maxQuantity.String(),
					"max_increases":      maxIncreases,
				},
			})
		}
	}

	state := input.State.Merge(map[string]any{
		"last_fill_side":   string(fill.Side),
		"fill_count":       asInt(input.State.Get("fill_count", 0)) + 1,
		"current_quantity": next.String(),
		"increase_count":   increaseCount,
		"cap_reached":      capReached,
	})

	levels, err := m.GetGridLevels(input.WithState(state))
	if err != nil {
		return Decision{}, err
	}

	adjacent := adjacentLevel(levels, fill.GridLevelSequence, fill.Side)
	state = ApplyFillToLevelState(state, fill, adjacent)

	var orders []OrderIntent
	if adjacent != nil {
		orders = append(orders, OrderIntent{
			Side:              adjacent.Side,
			Price:             adjacent.Price,
			Quantity:          next,
			GridLevelSequence: intPointer(adjacent.Sequence),
			Reason:            "strategy.opposite",
		})
	}

	return Decision{State: state, Levels: levels, Orders: orders, Events: events}, nil
}

// OnCancel mirrors MartingaleGridStrategy::onCancel.
func (m *MartingaleGrid) OnCancel(input Input, cancel CancelEvent) (Decision, error) {
	return idle(input.State.Merge(map[string]any{
		"last_canceled_client_order_id": cancel.ClientOrderID,
	})), nil
}

// ShouldStop mirrors MartingaleGridStrategy::shouldStop.
func (m *MartingaleGrid) ShouldStop(input Input) bool {
	return false
}

// GetGridLevels mirrors MartingaleGridStrategy::getGridLevels.
func (m *MartingaleGrid) GetGridLevels(input Input) ([]GridLevelPlan, error) {
	if input.State.IsNew() {
		center, err := m.centerPrice(input)
		if err != nil {
			return nil, err
		}

		return m.levelsFor(input, center)
	}

	center, err := decimal.Of(input.State.Get("center_price", nil))
	if err != nil {
		return nil, err
	}

	return m.levelsFor(input, center)
}

func (m *MartingaleGrid) orderIntents(input Input, levels []GridLevelPlan) ([]OrderIntent, error) {
	base, err := m.baseQuantity(input)
	if err != nil {
		return nil, err
	}

	quantity, err := decimal.Of(input.State.Get("current_quantity", base.String()))
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

func (m *MartingaleGrid) levelsFor(input Input, center decimal.Decimal) ([]GridLevelPlan, error) {
	spacingType, err := m.spacingType(input)
	if err != nil {
		return nil, err
	}

	spacing, err := m.spacingValue(input)
	if err != nil {
		return nil, err
	}

	perSide, err := levelsPerSide(m.Key(), input.Config)
	if err != nil {
		return nil, err
	}

	return BuildLevels(m.Key(), center, spacingType, spacing, perSide)
}

func (m *MartingaleGrid) centerPrice(input Input) (decimal.Decimal, error) {
	center, err := input.Config.Decimal("center_price", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !center.IsPositive() {
		return decimal.Decimal{}, invalidConfig(m.Key(), "center_price must be positive")
	}

	return center, nil
}

func (m *MartingaleGrid) baseQuantity(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("base_order_quantity", input.Config.Get("order_quantity", nil))
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !value.IsPositive() {
		return decimal.Decimal{}, invalidConfig(m.Key(), "base_order_quantity must be positive")
	}

	return value, nil
}

func (m *MartingaleGrid) sizeMultiplier(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("size_multiplier", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !value.GreaterThan(decimal.FromInt(1)) {
		return decimal.Decimal{}, invalidConfig(m.Key(), "size_multiplier must be greater than 1")
	}

	return value, nil
}

func (m *MartingaleGrid) maxQuantity(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("max_order_quantity", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !value.IsPositive() {
		return decimal.Decimal{}, invalidConfig(m.Key(), "max_order_quantity must be positive")
	}

	base, err := m.baseQuantity(input)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if value.LessThan(base) {
		return decimal.Decimal{}, invalidConfig(m.Key(), "max_order_quantity must be at least base_order_quantity")
	}

	return value, nil
}

func (m *MartingaleGrid) maxIncreases(input Input) (int, error) {
	value, ok := configInt(input.Config.Get("max_increases", 0))
	if !ok {
		return 0, invalidConfig(m.Key(), "max_increases must be an integer")
	}

	if value < 0 {
		return 0, invalidConfig(m.Key(), "max_increases must not be negative")
	}

	return value, nil
}

func (m *MartingaleGrid) spacingType(input Input) (SpacingType, error) {
	value := input.Config.Get("spacing_type", string(SpacingArithmetic))

	text, ok := value.(string)
	if !ok {
		return "", invalidConfig(m.Key(), "spacing_type must be arithmetic or geometric")
	}

	spacingType, err := parseSpacingType(text)
	if err != nil {
		return "", invalidConfig(m.Key(), "spacing_type must be arithmetic or geometric")
	}

	return spacingType, nil
}

func (m *MartingaleGrid) spacingValue(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("spacing_value", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !value.IsPositive() {
		return decimal.Decimal{}, invalidConfig(m.Key(), "spacing_value must be positive")
	}

	return value, nil
}

// stateBool mirrors PHP's (bool) cast for persisted state flags.
func stateBool(value any) bool {
	switch typed := value.(type) {
	case bool:
		return typed
	case int:
		return typed != 0
	case int64:
		return typed != 0
	case float64:
		return typed != 0
	case string:
		return typed != "" && typed != "0"
	default:
		return false
	}
}
