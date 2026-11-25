package heartbeat_test

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/heartbeat"
)

type recordedSet struct {
	key   string
	value []byte
	ttl   time.Duration
	calls int
}

func (r *recordedSet) Set(_ context.Context, key string, value []byte, ttl time.Duration) error {
	r.key = key
	r.value = value
	r.ttl = ttl
	r.calls++

	return nil
}

func TestWritePlaceholderUsesDesignKeyAndTTL(t *testing.T) {
	t.Parallel()

	setter := &recordedSet{}
	stub := heartbeat.NewWriter(setter)

	if err := stub.WritePlaceholder(context.Background(), 0); err != nil {
		t.Fatalf("WritePlaceholder() error = %v", err)
	}

	if setter.key != "engine:heartbeat" {
		t.Errorf("key = %q, want engine:heartbeat", setter.key)
	}

	if setter.ttl != 10*time.Second {
		t.Errorf("ttl = %s, want 10s", setter.ttl)
	}

	var payload heartbeat.Payload
	if err := json.Unmarshal(setter.value, &payload); err != nil {
		t.Fatalf("payload is not JSON: %v", err)
	}

	if payload.RunningBots != 0 || payload.Revision != 1 {
		t.Errorf("payload = %+v, want revision 1 and zero running bots", payload)
	}

	timestamp, err := time.Parse(time.RFC3339, payload.TS)
	if err != nil {
		t.Fatalf("payload ts %q is not RFC 3339: %v", payload.TS, err)
	}

	if timestamp.Location() != time.UTC {
		t.Errorf("payload ts location = %v, want UTC", timestamp.Location())
	}

	for _, forbidden := range []string{"password", "api_key", "secret", "app_key"} {
		if strings.Contains(strings.ToLower(string(setter.value)), forbidden) {
			t.Errorf("payload contains forbidden field %q: %s", forbidden, setter.value)
		}
	}
}

func TestWritePlaceholderIncrementsRevision(t *testing.T) {
	t.Parallel()

	setter := &recordedSet{}
	stub := heartbeat.NewWriter(setter)

	if err := stub.WritePlaceholder(context.Background(), 0); err != nil {
		t.Fatalf("first WritePlaceholder() error = %v", err)
	}

	if err := stub.WritePlaceholder(context.Background(), 3); err != nil {
		t.Fatalf("second WritePlaceholder() error = %v", err)
	}

	var payload heartbeat.Payload
	if err := json.Unmarshal(setter.value, &payload); err != nil {
		t.Fatalf("payload is not JSON: %v", err)
	}

	if payload.Revision != 2 || payload.RunningBots != 3 {
		t.Errorf("payload = %+v, want revision 2 and three running bots", payload)
	}

	if setter.calls != 2 {
		t.Errorf("setter calls = %d, want 2", setter.calls)
	}
}
