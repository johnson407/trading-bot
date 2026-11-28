package backtest

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/risk"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

func TestRunContextStopsWhenCanceled(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	_, err := NewEngine().RunContext(ctx, Configuration{StrategyKey: "simple-grid"}, nil, nil)
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("RunContext error = %v, want context.Canceled", err)
	}
}

func TestDynamicFirstCandlePlacesLadder(t *testing.T) {
	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	candles := []Candle{
		{
			OpenTime: start,
			Open:     mustDecimal(t, "100"),
			High:     mustDecimal(t, "101"),
			Low:      mustDecimal(t, "99"),
			Close:    mustDecimal(t, "100"),
			Volume:   mustDecimal(t, "1"),
		},
		{
			OpenTime: start.Add(time.Hour),
			Open:     mustDecimal(t, "100"),
			High:     mustDecimal(t, "100"),
			Low:      mustDecimal(t, "89"),
			Close:    mustDecimal(t, "90"),
			Volume:   mustDecimal(t, "1"),
		},
	}

	result, err := NewEngine().Run(Configuration{
		Symbol:            "BTC/USDT",
		StrategyKey:       "dynamic-grid",
		Timeframe:         Timeframe1h,
		StartAt:           start,
		EndAt:             start.Add(2 * time.Hour),
		CapitalAllocation: mustDecimal(t, "1000"),
		FeeRate:           mustDecimal(t, "0"),
		StrategyConfiguration: map[string]any{
			"levels_per_side":                2,
			"order_quantity":                 "1",
			"min_spacing":                    "10",
			"max_spacing":                    "20",
			"initial_spacing":                "10",
			"volatility_multiplier":          "1",
			"recalculation_interval_seconds": 7200,
			"spacing_hysteresis_percent":     0,
		},
		RiskLimits: RiskLimits{
			MaxPositionNotional: mustDecimal(t, "1000"),
			MaxOpenOrders:       20,
			DailyLossLimit:      mustDecimal(t, "100"),
		},
		ExitRules: risk.NewExitRules(nil, nil, nil, nil),
	}, candles, nil)
	if err != nil {
		t.Fatalf("run replay: %v", err)
	}

	if len(result.Fills) != 1 {
		t.Fatalf("fills = %d, want 1", len(result.Fills))
	}

	fill := result.Fills[0]
	if string(fill.Side) != "buy" {
		t.Fatalf("fill side = %s, want buy", fill.Side)
	}
	if !fill.Price.Equals(mustDecimal(t, "90")) {
		t.Fatalf("fill price = %s, want 90", fill.Price)
	}

	secondClose, err := candles[1].CloseTime(Timeframe1h)
	if err != nil {
		t.Fatalf("second close time: %v", err)
	}
	if !fill.FilledAt.Equal(secondClose) {
		t.Fatalf(
			"fill time = %s, want %s: the ladder was not placed on the first candle",
			fill.FilledAt,
			secondClose,
		)
	}
}

func TestDynamicReplacementReplenishesSharedPricesInReplay(t *testing.T) {
	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	candles := []Candle{
		{
			OpenTime: start,
			Open:     mustDecimal(t, "100"),
			High:     mustDecimal(t, "100"),
			Low:      mustDecimal(t, "100"),
			Close:    mustDecimal(t, "100"),
			Volume:   mustDecimal(t, "1"),
		},
		{
			OpenTime: start.Add(time.Hour),
			Open:     mustDecimal(t, "100"),
			High:     mustDecimal(t, "110"),
			Low:      mustDecimal(t, "100"),
			Close:    mustDecimal(t, "110"),
			Volume:   mustDecimal(t, "1"),
		},
		{
			OpenTime: start.Add(2 * time.Hour),
			Open:     mustDecimal(t, "110"),
			High:     mustDecimal(t, "110"),
			Low:      mustDecimal(t, "90"),
			Close:    mustDecimal(t, "90"),
			Volume:   mustDecimal(t, "1"),
		},
		{
			OpenTime: start.Add(3 * time.Hour),
			Open:     mustDecimal(t, "90"),
			High:     mustDecimal(t, "90"),
			Low:      mustDecimal(t, "80"),
			Close:    mustDecimal(t, "80"),
			Volume:   mustDecimal(t, "1"),
		},
	}

	result, err := NewEngine().Run(Configuration{
		Symbol:            "BTC/USDT",
		StrategyKey:       "dynamic-grid",
		Timeframe:         Timeframe1h,
		StartAt:           start,
		EndAt:             start.Add(4 * time.Hour),
		CapitalAllocation: mustDecimal(t, "1000"),
		FeeRate:           mustDecimal(t, "0"),
		StrategyConfiguration: map[string]any{
			"levels_per_side":                2,
			"order_quantity":                 "1",
			"min_spacing":                    "5",
			"max_spacing":                    "20",
			"initial_spacing":                "10",
			"volatility_multiplier":          "1",
			"recalculation_interval_seconds": 5400,
			"spacing_hysteresis_percent":     0,
		},
		RiskLimits: RiskLimits{
			MaxPositionNotional: mustDecimal(t, "1000"),
			MaxOpenOrders:       20,
			DailyLossLimit:      mustDecimal(t, "100"),
		},
		ExitRules: risk.NewExitRules(nil, nil, nil, nil),
	}, candles, nil)
	if err != nil {
		t.Fatalf("run replay: %v", err)
	}

	// The first candle places 80/90/110/120. The second candle is not yet due
	// for recalculation; the third is due and widens spacing to 20, producing
	// 60/80/120/140 with 80 and 120 shared with the old ladder. The fourth
	// candle fills the shared buy at 80, so a lost shared-price order is visible.
	filledBuyAt := make(map[string]bool, len(result.Fills))
	for _, fill := range result.Fills {
		if fill.Side == strategy.SideBuy {
			filledBuyAt[fill.Price.String()] = true
		}
	}
	if !filledBuyAt["80"] {
		t.Fatalf("missing shared-price buy fill at 80 after replacement: %+v", result.Fills)
	}
}

func TestReplacementPlacesEveryLevelInNewLadder(t *testing.T) {
	engine := NewEngine()
	book := NewSimulatedOrderBook("USDT")
	portfolio := NewInMemoryPortfolio(mustDecimal(t, "10000"))
	portfolio.Base = mustDecimal(t, "100")
	portfolio.AverageEntry = mustDecimal(t, "100")
	riskGuard := NewBacktestRiskGuard(RiskLimits{
		MaxPositionNotional: mustDecimal(t, "100000"),
		MaxOpenOrders:       20,
		DailyLossLimit:      mustDecimal(t, "1000"),
	}, mustDecimal(t, "0"))

	oldLevels := testLevels("92", "96", "104", "108")
	active, activeSet, canceled := engine.syncGrid(nil, false, oldLevels, book)
	if len(canceled) != 0 {
		t.Fatalf("initial sync canceled %d orders, want 0", len(canceled))
	}
	engine.placeIntents(testIntents(oldLevels), book, riskGuard, portfolio, 0)

	// The spacing change keeps 96 and 104 while adding 98 and 102.
	newLevels := testLevels("96", "98", "102", "104")
	active, activeSet, canceled = engine.syncGrid(active, activeSet, newLevels, book)
	if len(canceled) != 4 {
		t.Fatalf("replacement canceled %d orders, want 4", len(canceled))
	}
	engine.placeIntents(testIntents(newLevels), book, riskGuard, portfolio, 1)

	open := book.OpenOrders()
	if len(open) != 4 {
		t.Fatalf("open orders = %d, want every level in the new ladder: %+v", len(open), open)
	}

	openPrices := make(map[string]bool, len(open))
	for _, order := range open {
		openPrices[order.Price.String()] = true
	}
	for _, price := range []string{"96", "98", "102", "104"} {
		if !openPrices[price] {
			t.Fatalf("missing order at %s after replacement: %+v", price, open)
		}
	}
}

func testLevels(prices ...string) []strategy.GridLevelPlan {
	levels := make([]strategy.GridLevelPlan, 0, len(prices))
	for index, price := range prices {
		side := strategy.SideBuy
		if decimal.MustParse(price).GreaterThan(decimal.MustParse("100")) {
			side = strategy.SideSell
		}
		levels = append(levels, strategy.GridLevelPlan{
			Sequence: index + 1,
			Price:    decimal.MustParse(price),
			Side:     side,
		})
	}

	return levels
}

func testIntents(levels []strategy.GridLevelPlan) []strategy.OrderIntent {
	intents := make([]strategy.OrderIntent, 0, len(levels))
	for _, level := range levels {
		intents = append(intents, strategy.OrderIntent{
			Side:     level.Side,
			Price:    level.Price,
			Quantity: decimal.MustParse("1"),
			Reason:   "test",
		})
	}

	return intents
}

func TestMergeReplacementOrdersKeepsFirstPassWhenSecondIsEmpty(t *testing.T) {
	first := []strategy.OrderIntent{{Reason: "first-pass"}}

	merged := mergeReplacementOrders(first, nil)
	if len(merged) != 1 || merged[0].Reason != "first-pass" {
		t.Fatalf("empty second pass discarded the first pass: %+v", merged)
	}

	second := []strategy.OrderIntent{{Reason: "second-pass"}}
	merged = mergeReplacementOrders(first, second)
	if len(merged) != 1 || merged[0].Reason != "second-pass" {
		t.Fatalf("non-empty second pass should win: %+v", merged)
	}
}

func mustDecimal(t *testing.T, value string) decimal.Decimal {
	t.Helper()

	parsed, err := decimal.Of(value)
	if err != nil {
		t.Fatalf("parse decimal %q: %v", value, err)
	}

	return parsed
}
