package strategy

import (
	"fmt"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func levelsPerSide(strategyKey string, config Config) (int, error) {
	value, ok := configInt(config.Get("levels_per_side", config.Get("level_count", nil)))
	if !ok {
		return 0, invalidConfig(strategyKey, "levels_per_side must be an integer")
	}

	if value < 1 {
		return 0, invalidConfig(strategyKey, "levels_per_side must be at least 1")
	}

	return value, nil
}

func orderQuantity(strategyKey string, config Config) (decimal.Decimal, error) {
	quantity, err := config.Decimal("order_quantity", nil)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !quantity.IsPositive() {
		return decimal.Decimal{}, invalidConfig(strategyKey, "order_quantity must be positive")
	}

	return quantity, nil
}

func adjacentLevel(levels []GridLevelPlan, sequence *int, side Side) *GridLevelPlan {
	if sequence == nil {
		return nil
	}

	target := *sequence + 1
	if side != SideBuy {
		target = *sequence - 1
	}

	for index := range levels {
		if levels[index].Sequence == target {
			level := levels[index]

			return &level
		}
	}

	return nil
}

func timestampOf(market MarketSnapshot) time.Time {
	if market.Timestamp != nil {
		return *market.Timestamp
	}

	return time.Now().UTC()
}

func formatDateTimeAtom(value time.Time) string {
	return value.Format("2006-01-02T15:04:05-07:00")
}

func parseSpacingType(value string) (SpacingType, error) {
	switch SpacingType(value) {
	case SpacingArithmetic, SpacingGeometric, SpacingDynamic:
		return SpacingType(value), nil
	default:
		return "", fmt.Errorf("Invalid spacing type: %s", value)
	}
}
