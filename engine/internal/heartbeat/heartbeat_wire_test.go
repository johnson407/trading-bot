package heartbeat_test

import (
	"context"
	"encoding/json"
	"errors"
	"sync"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/heartbeat"
)

type safeSet struct {
	mu    sync.Mutex
	value []byte
	calls int
}

func (s *safeSet) Set(_ context.Context, _ string, value []byte, _ time.Duration) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.value = append([]byte(nil), value...)
	s.calls++

	return nil
}

func (s *safeSet) snapshot() ([]byte, int) {
	s.mu.Lock()
	defer s.mu.Unlock()

	return append([]byte(nil), s.value...), s.calls
}

type fakeReader struct {
	value []byte
	err   error
}

func (f fakeReader) Get(context.Context, string) ([]byte, error) {
	return f.value, f.err
}

func payloadAt(t *testing.T, timestamp time.Time) []byte {
	t.Helper()

	encoded, err := json.Marshal(heartbeat.Payload{
		TS:          timestamp.UTC().Format(time.RFC3339),
		Revision:    3,
		RunningBots: 2,
	})
	if err != nil {
		t.Fatalf("marshal payload: %v", err)
	}

	return encoded
}

func TestCheckFreshAcceptsRecentHeartbeat(t *testing.T) {
	t.Parallel()

	now := time.Now().UTC()
	reader := fakeReader{value: payloadAt(t, now.Add(-2*time.Second))}

	if err := heartbeat.CheckFresh(context.Background(), reader, now); err != nil {
		t.Fatalf("CheckFresh() error = %v, want nil", err)
	}
}

func TestCheckFreshRejectsExpiredOrMissingHeartbeat(t *testing.T) {
	t.Parallel()

	now := time.Now().UTC()

	cases := map[string]fakeReader{
		"expired": {value: payloadAt(t, now.Add(-11*time.Second))},
		"missing": {err: errors.New("redis: nil")},
		"garbage": {value: []byte("not-json")},
		"future":  {value: payloadAt(t, now.Add(time.Minute))},
	}

	for name, reader := range cases {
		if err := heartbeat.CheckFresh(context.Background(), reader, now); err == nil {
			t.Errorf("%s: CheckFresh() error = nil, want error", name)
		}
	}
}

func TestRunRefreshesAtLeastEveryFiveSeconds(t *testing.T) {
	t.Parallel()

	setter := &safeSet{}
	writer := heartbeat.NewWriter(setter)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	done := make(chan struct{})
	go func() {
		writer.Run(ctx, 5*time.Millisecond, func() int { return 4 })
		close(done)
	}()

	deadline := time.Now().Add(time.Second)
	for {
		value, calls := setter.snapshot()
		if calls >= 2 && len(value) > 0 {
			var payload heartbeat.Payload
			if err := json.Unmarshal(value, &payload); err != nil {
				t.Fatalf("payload is not JSON: %v", err)
			}

			if payload.RunningBots != 4 || payload.Revision < 2 {
				t.Errorf("payload = %+v, want running_bots 4 and increasing revision", payload)
			}

			break
		}

		if time.Now().After(deadline) {
			t.Fatal("Run() did not refresh the heartbeat")
		}

		time.Sleep(time.Millisecond)
	}

	cancel()

	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("Run() did not stop after context cancellation")
	}
}
