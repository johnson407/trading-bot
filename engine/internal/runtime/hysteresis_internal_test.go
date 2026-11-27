package runtime

import (
	"context"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/grid"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

func TestSyncGridDoesNotReplaceMatchingHysteresisLevels(t *testing.T) {
	active := &gridState{
		ID: 1,
		Levels: []grid.GridLevel{
			{Sequence: 1, Price: decimal.MustParse("90"), Side: grid.SideBuy},
			{Sequence: 2, Price: decimal.MustParse("110"), Side: grid.SideSell},
		},
	}
	levels := []strategy.GridLevelPlan{
		{Sequence: 1, Price: decimal.MustParse("90"), Side: strategy.SideBuy},
		{Sequence: 2, Price: decimal.MustParse("110"), Side: strategy.SideSell},
	}

	stats := newTickStats(1, time.Unix(1, 0))
	state, replaced, err := (&Runtime{}).syncGrid(context.Background(), &Bot{ID: 1}, levels, active, stats)
	if err != nil {
		t.Fatalf("syncGrid returned error: %v", err)
	}
	if state != active {
		t.Fatalf("expected active grid to be retained, got %#v", state)
	}
	if replaced {
		t.Fatal("expected matching levels to keep the active grid")
	}
	if stats.gridsMatched != 1 || stats.gridsReplaced != 0 || stats.ordersCanceled != 0 {
		t.Fatalf("unexpected stats: matched=%d replaced=%d canceled=%d", stats.gridsMatched, stats.gridsReplaced, stats.ordersCanceled)
	}
}
