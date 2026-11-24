package backtest

import (
	"context"
	"testing"
	"time"
)

type recordingStaleStore struct {
	cutoff  time.Time
	message string
	calls   int
}

func (s *recordingStaleStore) FailStaleRuns(_ context.Context, cutoff time.Time, message string) (int64, error) {
	s.calls++
	s.cutoff = cutoff
	s.message = message
	return 3, nil
}

func TestFailStaleRunsUsesTimeoutPlusGrace(t *testing.T) {
	store := &recordingStaleStore{}
	before := time.Now().UTC()

	count, err := FailStaleRuns(context.Background(), store, 10*time.Minute)
	if err != nil {
		t.Fatalf("FailStaleRuns: %v", err)
	}
	if count != 3 {
		t.Fatalf("count = %d, want 3", count)
	}
	if store.calls != 1 {
		t.Fatalf("calls = %d, want 1", store.calls)
	}
	if store.message != StaleRunMessage {
		t.Fatalf("message = %q, want %q", store.message, StaleRunMessage)
	}

	wantCutoff := before.Add(-(10*time.Minute + StaleGrace))
	if diff := store.cutoff.Sub(wantCutoff); diff < -time.Second || diff > time.Second {
		t.Fatalf("cutoff = %s, want about %s", store.cutoff, wantCutoff)
	}

	store = &recordingStaleStore{}
	if _, err := FailStaleRuns(context.Background(), store, 0); err != nil {
		t.Fatalf("FailStaleRuns: %v", err)
	}
	wantDefault := time.Now().UTC().Add(-(10*time.Minute + StaleGrace))
	if diff := store.cutoff.Sub(wantDefault); diff < -time.Second || diff > time.Second {
		t.Fatalf("default cutoff = %s, want about %s", store.cutoff, wantDefault)
	}
}
