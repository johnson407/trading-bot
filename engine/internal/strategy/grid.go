package strategy

import (
	"fmt"
	"sort"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// BuildLevels ports GeneratesGridLevels::buildLevels. The ladder is sorted
// ascending, sequences are 1-based, and a price equal to the center is a sell
// level (PHP assigns buy only when price < center).
func BuildLevels(
	strategyKey string,
	center decimal.Decimal,
	spacingType SpacingType,
	spacing decimal.Decimal,
	perSide int,
) ([]GridLevelPlan, error) {
	if perSide < 1 {
		return nil, invalidConfig(strategyKey, "levels_per_side must be at least 1")
	}

	if !spacing.IsPositive() {
		return nil, invalidConfig(strategyKey, "spacing_value must be positive")
	}

	if spacingType == SpacingGeometric && spacing.GreaterThanOrEqual(decimal.FromInt(1)) {
		return nil, invalidConfig(strategyKey, "geometric spacing must be less than 1")
	}

	levels := make([]GridLevelPlan, 0, perSide*2)

	for index := 1; index <= perSide; index++ {
		var price decimal.Decimal

		if spacingType == SpacingArithmetic {
			price = center.Sub(spacing.Mul(decimal.FromInt(index)))
		} else {
			geometric, err := geometricPrice(center, spacing, index, false)
			if err != nil {
				return nil, err
			}

			price = geometric
		}

		if !price.IsPositive() {
			return nil, invalidConfig(strategyKey, "generated a non-positive level price; reduce spacing or level count")
		}

		levels = append(levels, GridLevelPlan{Price: price, Side: SideBuy})
	}

	for index := 1; index <= perSide; index++ {
		var price decimal.Decimal

		if spacingType == SpacingArithmetic {
			price = center.Add(spacing.Mul(decimal.FromInt(index)))
		} else {
			geometric, err := geometricPrice(center, spacing, index, true)
			if err != nil {
				return nil, err
			}

			price = geometric
		}

		levels = append(levels, GridLevelPlan{Price: price, Side: SideSell})
	}

	// PHP 8 usort is stable, so equal prices keep the below/above insertion
	// order before the duplicate check rejects them.
	sort.SliceStable(levels, func(left, right int) bool {
		return levels[left].Price.Compare(levels[right].Price) < 0
	})

	assigned := make([]GridLevelPlan, 0, len(levels))
	seen := make(map[string]struct{}, len(levels))

	for index, level := range levels {
		key := level.Price.String()

		if _, duplicate := seen[key]; duplicate {
			return nil, invalidConfig(strategyKey, fmt.Sprintf("duplicate grid level price [%s]", key))
		}

		seen[key] = struct{}{}

		side := SideSell
		if level.Price.LessThan(center) {
			side = SideBuy
		}

		assigned = append(assigned, GridLevelPlan{
			Sequence: index + 1,
			Price:    level.Price,
			Side:     side,
		})
	}

	return assigned, nil
}

// ApplyFillToLevelState ports GeneratesGridLevels::applyFillToLevelState.
func ApplyFillToLevelState(state State, fill FillEvent, next *GridLevelPlan) State {
	disarmed := disarmedLevels(state)
	price := fill.Price.String()

	// A fill at the paired level re-arms the original level.
	for key, entry := range disarmed {
		values, ok := entry.(map[string]any)
		if !ok {
			continue
		}

		// Only the opposite side of the original fill re-arms the level.
		// Entries without a recorded side cannot be matched safely, so they
		// stay disarmed rather than reintroducing same-side re-arming.
		side, ok := values["side"].(string)
		if !ok || side == string(fill.Side) {
			continue
		}

		pair, ok := values["pair"].(string)
		if ok && pair == price {
			delete(disarmed, key)
		}
	}

	if fill.GridLevelSequence != nil {
		var pair any
		if next != nil {
			pair = next.Price.String()
		}

		disarmed[price] = map[string]any{
			"side": string(fill.Side),
			"pair": pair,
		}
	}

	return state.Merge(map[string]any{"disarmed_levels": disarmed})
}

// oppositeSide returns the side that closes the filled position.
func oppositeSide(side Side) Side {
	if side == SideBuy {
		return SideSell
	}

	return SideBuy
}

// LevelIsArmed ports GeneratesGridLevels::levelIsArmed.
func LevelIsArmed(state State, level GridLevelPlan) bool {
	value := state.Get("disarmed_levels", nil)

	switch disarmed := value.(type) {
	case map[string]any:
		_, present := disarmed[level.Price.String()]

		return !present
	case []any:
		return true
	default:
		return true
	}
}

// PruneDisarmedLevels removes disarm entries whose paired price no longer
// exists in the recalculated ladder. An entry without a recorded pair stays
// disarmed because there is nothing safe to reconcile it against.
func PruneDisarmedLevels(state State, levels []GridLevelPlan) State {
	disarmed := disarmedLevels(state)
	if len(disarmed) == 0 {
		return state
	}

	prices := make(map[string]struct{}, len(levels))
	for _, level := range levels {
		prices[level.Price.String()] = struct{}{}
	}

	changed := false
	for key, entry := range disarmed {
		values, ok := entry.(map[string]any)
		if !ok {
			continue
		}

		pair, ok := values["pair"].(string)
		if !ok || pair == "" {
			continue
		}

		if _, present := prices[pair]; !present {
			delete(disarmed, key)
			changed = true
		}
	}

	if !changed {
		return state
	}

	return state.Merge(map[string]any{"disarmed_levels": disarmed})
}

func disarmedLevels(state State) map[string]any {
	value := state.Get("disarmed_levels", nil)

	switch typed := value.(type) {
	case map[string]any:
		copied := make(map[string]any, len(typed))
		for key, entry := range typed {
			copied[key] = entry
		}

		return copied
	case []any:
		return map[string]any{}
	default:
		return map[string]any{}
	}
}

func geometricPrice(
	center decimal.Decimal,
	spacing decimal.Decimal,
	step int,
	above bool,
) (decimal.Decimal, error) {
	factor := decimal.FromInt(1).Add(spacing)
	multiplier := decimal.FromInt(1)

	for index := 0; index < step; index++ {
		multiplier = multiplier.Mul(factor)
	}

	if above {
		return center.Mul(multiplier), nil
	}

	return center.Div(multiplier)
}
