package strategy

import (
	"fmt"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// SimpleGrid is the runtime simple-grid strategy.
type SimpleGrid struct{}

// NewSimpleGrid returns the simple-grid strategy.
func NewSimpleGrid() *SimpleGrid {
	return &SimpleGrid{}
}

// Key mirrors SimpleGridStrategy::KEY.
func (s *SimpleGrid) Key() string {
	return "simple-grid"
}

// Initialize mirrors SimpleGridStrategy::initialize.
func (s *SimpleGrid) Initialize(input Input) (Decision, error) {
	if !input.State.IsNew() {
		return idle(input.State), nil
	}

	center, err := s.centerPrice(input)
	if err != nil {
		return Decision{}, err
	}

	spacingType, err := s.spacingType(input)
	if err != nil {
		return Decision{}, err
	}

	spacing, err := s.spacingValue(input)
	if err != nil {
		return Decision{}, err
	}

	perSide, err := levelsPerSide(s.Key(), input.Config)
	if err != nil {
		return Decision{}, err
	}

	levels, err := BuildLevels(s.Key(), center, spacingType, spacing, perSide)
	if err != nil {
		return Decision{}, err
	}

	state := input.State.Merge(map[string]any{
		"initialized":     true,
		"center_price":    center.String(),
		"spacing_type":    string(spacingType),
		"spacing_value":   spacing.String(),
		"levels_per_side": perSide,
		"last_fill_side":  nil,
		"fill_count":      0,
	})

	return Decision{State: state, Levels: levels}, nil
}

// OnTick mirrors SimpleGridStrategy::onTick.
func (s *SimpleGrid) OnTick(input Input) (Decision, error) {
	if input.State.IsNew() {
		initialized, err := s.Initialize(input)
		if err != nil {
			return Decision{}, err
		}

		orders, err := s.orderIntents(input.WithState(initialized.State))
		if err != nil {
			return Decision{}, err
		}

		initialized.Orders = orders

		return initialized, nil
	}

	levels, err := s.GetGridLevels(input)
	if err != nil {
		return Decision{}, err
	}

	orders, err := s.orderIntents(input)
	if err != nil {
		return Decision{}, err
	}

	return Decision{State: input.State, Levels: levels, Orders: orders}, nil
}

// OnFill mirrors SimpleGridStrategy::onFill.
func (s *SimpleGrid) OnFill(input Input, fill FillEvent) (Decision, error) {
	levels, err := s.GetGridLevels(input)
	if err != nil {
		return Decision{}, err
	}

	next := adjacentLevel(levels, fill.GridLevelSequence, fill.Side)
	state := ApplyFillToLevelState(input.State.Merge(map[string]any{
		"last_fill_side":  string(fill.Side),
		"fill_count":      asInt(input.State.Get("fill_count", 0)) + 1,
		"last_fill_price": fill.Price.String(),
	}), fill, next)

	var orders []OrderIntent
	if next != nil {
		orders = append(orders, OrderIntent{
			Side:              oppositeSide(fill.Side),
			Price:             next.Price,
			Quantity:          fill.Quantity,
			GridLevelSequence: intPointer(next.Sequence),
			Reason:            "strategy.opposite",
		})
	}

	return Decision{State: state, Levels: levels, Orders: orders}, nil
}

// OnCancel mirrors SimpleGridStrategy::onCancel.
func (s *SimpleGrid) OnCancel(input Input, cancel CancelEvent) (Decision, error) {
	return idle(input.State.Merge(map[string]any{
		"last_canceled_client_order_id": cancel.ClientOrderID,
	})), nil
}

// ShouldStop mirrors SimpleGridStrategy::shouldStop.
func (s *SimpleGrid) ShouldStop(input Input) bool {
	return false
}

// GetGridLevels mirrors SimpleGridStrategy::getGridLevels. The PHP default
// arguments are evaluated eagerly, so configuration is validated even when the
// state already stores the values.
func (s *SimpleGrid) GetGridLevels(input Input) ([]GridLevelPlan, error) {
	centerDefault, err := s.centerPrice(input)
	if err != nil {
		return nil, err
	}

	typeDefault, err := s.spacingType(input)
	if err != nil {
		return nil, err
	}

	spacingDefault, err := s.spacingValue(input)
	if err != nil {
		return nil, err
	}

	perSide, err := levelsPerSide(s.Key(), input.Config)
	if err != nil {
		return nil, err
	}

	center := centerDefault
	if value := input.State.Get("center_price", nil); value != nil {
		center, err = decimal.Of(value)
		if err != nil {
			return nil, err
		}
	}

	typeValue := string(typeDefault)
	if value := input.State.Get("spacing_type", nil); value != nil {
		typeValue = fmt.Sprint(value)
	}

	spacingType, err := parseSpacingType(typeValue)
	if err != nil {
		return nil, err
	}

	spacing := spacingDefault
	if value := input.State.Get("spacing_value", nil); value != nil {
		spacing, err = decimal.Of(value)
		if err != nil {
			return nil, err
		}
	}

	return BuildLevels(s.Key(), center, spacingType, spacing, perSide)
}

func (s *SimpleGrid) orderIntents(input Input) ([]OrderIntent, error) {
	quantity, err := orderQuantity(s.Key(), input.Config)
	if err != nil {
		return nil, err
	}

	levels, err := s.GetGridLevels(input)
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

func (s *SimpleGrid) centerPrice(input Input) (decimal.Decimal, error) {
	center, err := input.Config.Decimal("center_price", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !center.IsPositive() {
		return decimal.Decimal{}, invalidConfig(s.Key(), "center_price must be positive")
	}

	return center, nil
}

func (s *SimpleGrid) spacingType(input Input) (SpacingType, error) {
	value := input.Config.Get("spacing_type", string(SpacingArithmetic))

	text, ok := value.(string)
	if !ok {
		return "", invalidConfig(s.Key(), "spacing_type must be arithmetic or geometric")
	}

	spacingType, err := parseSpacingType(text)
	if err != nil {
		return "", invalidConfig(s.Key(), "spacing_type must be arithmetic or geometric")
	}

	if spacingType == SpacingDynamic {
		return "", invalidConfig(s.Key(), "simple-grid supports arithmetic or geometric spacing")
	}

	return spacingType, nil
}

func (s *SimpleGrid) spacingValue(input Input) (decimal.Decimal, error) {
	value, err := input.Config.Decimal("spacing_value", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !value.IsPositive() {
		return decimal.Decimal{}, invalidConfig(s.Key(), "spacing_value must be positive")
	}

	spacingType, err := s.spacingType(input)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if spacingType == SpacingGeometric && value.GreaterThanOrEqual(decimal.FromInt(1)) {
		return decimal.Decimal{}, invalidConfig(s.Key(), "geometric spacing must be less than 1")
	}

	return value, nil
}
