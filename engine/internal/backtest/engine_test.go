package backtest

import (
	"context"
	"errors"
	"testing"
)

func TestRunContextStopsWhenCanceled(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	_, err := NewEngine().RunContext(ctx, Configuration{StrategyKey: "simple-grid"}, nil, nil)
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("RunContext error = %v, want context.Canceled", err)
	}
}
