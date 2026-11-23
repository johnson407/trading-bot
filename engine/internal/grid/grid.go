// Package grid ports the PHP grid generator, derivation helpers, and the
// BotTickPipeline grid create/match/cancel/recenter behaviour. It is pure: it
// never touches a database, an exchange, or the PHP runtime.
package grid

import (
	"fmt"
	"sort"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// SpacingType mirrors App\Domain\Grid\Enums\GridSpacingType.
type SpacingType string

const (
	SpacingArithmetic SpacingType = "arithmetic"
	SpacingGeometric  SpacingType = "geometric"
	SpacingDynamic    SpacingType = "dynamic"
)

// Side mirrors App\Domain\Order\Enums\OrderSide.
type Side string

const (
	SideBuy  Side = "buy"
	SideSell Side = "sell"
)

// GridStatus mirrors App\Domain\Grid\Enums\GridStatus.
type GridStatus string

const (
	GridActive  GridStatus = "active"
	GridStopped GridStatus = "stopped"
)

// LevelState mirrors App\Domain\Grid\Enums\GridLevelState.
type LevelState string

const (
	LevelIdle     LevelState = "idle"
	LevelOpen     LevelState = "open"
	LevelFilled   LevelState = "filled"
	LevelCanceled LevelState = "canceled"
)

// OrderStatus mirrors App\Domain\Order\Enums\OrderStatus.
type OrderStatus string

const (
	OrderPending         OrderStatus = "pending"
	OrderOpen            OrderStatus = "open"
	OrderPartiallyFilled OrderStatus = "partially_filled"
	OrderFilled          OrderStatus = "filled"
	OrderCanceled        OrderStatus = "canceled"
	OrderRejected        OrderStatus = "rejected"
)

// OrderType and Mode are kept as strings because the fixtures record the PHP
// enum values verbatim.
const (
	OrderTypeLimit = "limit"
	ModePaper      = "paper"
)

// Level is a generated grid level plan (App\Domain\Strategy\ValueObjects\
// GridLevelPlan). Sequence is 1-based and prices are sorted ascending.
type Level struct {
	Sequence int
	Price    decimal.Decimal
	Side     Side
}

// GridLevel is a persisted grid level.
type GridLevel struct {
	Sequence int
	Price    decimal.Decimal
	Side     Side
	State    LevelState
}

// Grid is a persisted grid.
type Grid struct {
	ID           int64
	CenterPrice  decimal.Decimal
	SpacingType  SpacingType
	SpacingValue decimal.Decimal
	LevelCount   int
	Status       GridStatus
	Levels       []GridLevel
}

// Order is the subset of a persisted order needed by grid sync.
type Order struct {
	ID                int64
	GridID            int64
	GridLevelSequence int
	Side              Side
	Type              string
	Price             decimal.Decimal
	Quantity          decimal.Decimal
	FilledQuantity    decimal.Decimal
	Status            OrderStatus
	Mode              string
}

// InvalidGridError mirrors App\Domain\Grid\Exceptions\InvalidGridException.
type InvalidGridError struct {
	Reason string
}

func (e *InvalidGridError) Error() string {
	return "Invalid grid: " + e.Reason
}

func invalidGrid(reason string) error {
	return &InvalidGridError{Reason: reason}
}

// Generate builds the symmetric ladder exactly as GridGenerator::generate does.
// Dynamic spacing uses the arithmetic ladder; geometric spacing divides and
// multiplies by (1 + spacing)^index.
func Generate(centerPrice, spacingValue any, spacingType SpacingType, levelsPerSide int) ([]Level, error) {
	center, err := decimal.Of(centerPrice)
	if err != nil {
		return nil, invalidGrid(err.Error())
	}

	spacing, err := decimal.Of(spacingValue)
	if err != nil {
		return nil, invalidGrid(err.Error())
	}

	if !center.IsPositive() {
		return nil, invalidGrid("center price must be positive")
	}

	if !spacing.IsPositive() {
		return nil, invalidGrid("spacing must be positive")
	}

	if levelsPerSide < 1 {
		return nil, invalidGrid("levels per side must be at least 1")
	}

	if spacingType == SpacingGeometric {
		one := decimal.FromInt(1)
		if spacing.GreaterThanOrEqual(one) {
			return nil, invalidGrid("geometric spacing must be less than 1")
		}
	}

	prices := make([]decimal.Decimal, 0, levelsPerSide*2)

	for index := 1; index <= levelsPerSide; index++ {
		var below, above decimal.Decimal

		if spacingType == SpacingGeometric {
			factor, powErr := decimal.FromInt(1).Add(spacing).Pow(index)
			if powErr != nil {
				return nil, invalidGrid(powErr.Error())
			}

			below, err = center.Div(factor)
			if err != nil {
				return nil, invalidGrid(err.Error())
			}

			above = center.Mul(factor)
		} else {
			offset := spacing.Mul(decimal.FromInt(index))
			below = center.Sub(offset)
			above = center.Add(offset)
		}

		if !below.IsPositive() || !above.IsPositive() {
			return nil, invalidGrid("generated levels must have positive prices")
		}

		prices = append(prices, below, above)
	}

	// PHP usort is stable since PHP 8; Equal values keep the below/above order.
	sort.SliceStable(prices, func(left, right int) bool {
		return prices[left].LessThan(prices[right])
	})

	levels := make([]Level, 0, len(prices))
	seen := make(map[string]struct{}, len(prices))

	for index, price := range prices {
		key, err := price.ToFixed(decimal.Scale)
		if err != nil {
			return nil, invalidGrid(err.Error())
		}

		if _, duplicate := seen[key]; duplicate {
			return nil, invalidGrid(fmt.Sprintf("duplicate level price [%s]", key))
		}

		seen[key] = struct{}{}

		side := SideSell
		if price.LessThan(center) {
			side = SideBuy
		}

		levels = append(levels, Level{
			Sequence: index + 1,
			Price:    price,
			Side:     side,
		})
	}

	return levels, nil
}

// PercentToFraction mirrors GridDerivation::percentToFraction. Percent numbers
// are percent form: 0.4 means 0.4% and becomes 0.004.
func PercentToFraction(percent any) (decimal.Decimal, error) {
	parsed, err := decimal.Of(percent)
	if err != nil {
		return decimal.Decimal{}, err
	}

	fraction, err := parsed.Div(decimal.FromInt(100))
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !fraction.IsPositive() {
		return decimal.Decimal{}, fmt.Errorf("Spacing percent must be positive.")
	}

	return fraction, nil
}

// OrderQuantity mirrors GridDerivation::orderQuantity.
func OrderQuantity(capital, utilization any, levelsPerSide int, centerPrice any) (decimal.Decimal, error) {
	if levelsPerSide < 1 {
		return decimal.Decimal{}, fmt.Errorf("Levels per side must be at least 1.")
	}

	center, err := decimal.Of(centerPrice)
	if err != nil {
		return decimal.Decimal{}, err
	}

	if !center.IsPositive() {
		return decimal.Decimal{}, fmt.Errorf("Center price must be positive.")
	}

	parsedCapital, err := decimal.Of(capital)
	if err != nil {
		return decimal.Decimal{}, err
	}

	parsedUtilization, err := decimal.Of(utilization)
	if err != nil {
		return decimal.Decimal{}, err
	}

	scaled, err := parsedCapital.Mul(parsedUtilization).Div(decimal.FromInt(levelsPerSide))
	if err != nil {
		return decimal.Decimal{}, err
	}

	return scaled.Div(center)
}

// LevelsMatch mirrors BotTickPipeline::levelsMatch.
func LevelsMatch(existing []GridLevel, levels []Level) bool {
	if len(existing) != len(levels) {
		return false
	}

	for index, plan := range levels {
		level := existing[index]

		if level.Sequence != plan.Sequence || !level.Price.Equals(plan.Price) || level.Side != plan.Side {
			return false
		}
	}

	return true
}

// SyncResult is the pure result of one grid sync.
type SyncResult struct {
	// Grid is the active grid after sync, or nil when there is no grid and no
	// levels were supplied.
	Grid *Grid

	// Previous is the old grid marked stopped when a replacement happened.
	Previous *Grid

	// CanceledOrders are copies of the previous grid's pending/open/partially
	// filled orders with status canceled. The caller persists them.
	CanceledOrders []Order

	// Created reports that Grid is a newly created grid.
	Created bool

	// Matched reports that the existing grid already matched the plan.
	Matched bool
}

// Sync mirrors BotTickPipeline::syncGrid:
//   - empty levels keep the active grid unchanged;
//   - matching levels keep the active grid and its orders;
//   - otherwise cancel the old grid's open orders, stop it, and create a new
//     active grid from the plans.
//
// newGridID is supplied by the caller because PHP gets it from the database.
func Sync(active *Grid, orders []Order, levels []Level, strategyKey string, newGridID int64) (SyncResult, error) {
	if len(levels) == 0 {
		return SyncResult{Grid: active, Matched: active != nil}, nil
	}

	if active != nil && LevelsMatch(active.Levels, levels) {
		return SyncResult{Grid: active, Matched: true}, nil
	}

	result := SyncResult{}

	if active != nil {
		previous := *active
		previous.Status = GridStopped
		previous.Levels = append([]GridLevel(nil), active.Levels...)

		result.Previous = &previous

		for _, order := range orders {
			if order.GridID != active.ID || !isOpenish(order.Status) {
				continue
			}

			canceled := order
			canceled.Status = OrderCanceled
			result.CanceledOrders = append(result.CanceledOrders, canceled)
		}
	}

	first := levels[0].Price
	last := levels[len(levels)-1].Price

	center, err := first.Add(last).Div(decimal.FromInt(2))
	if err != nil {
		return SyncResult{}, err
	}

	spacing := decimal.Zero()
	if len(levels) > 1 {
		spacing = levels[1].Price.Sub(levels[0].Price).Abs()
	}

	spacingType := SpacingArithmetic
	if strategyKey == "dynamic-grid" {
		spacingType = SpacingDynamic
	}

	created := &Grid{
		ID:           newGridID,
		CenterPrice:  center,
		SpacingType:  spacingType,
		SpacingValue: spacing,
		LevelCount:   len(levels),
		Status:       GridActive,
		Levels:       make([]GridLevel, 0, len(levels)),
	}

	for _, plan := range levels {
		created.Levels = append(created.Levels, GridLevel{
			Sequence: plan.Sequence,
			Price:    plan.Price,
			Side:     plan.Side,
			State:    LevelIdle,
		})
	}

	result.Grid = created
	result.Created = true

	return result, nil
}

func isOpenish(status OrderStatus) bool {
	switch status {
	case OrderPending, OrderOpen, OrderPartiallyFilled:
		return true
	default:
		return false
	}
}
