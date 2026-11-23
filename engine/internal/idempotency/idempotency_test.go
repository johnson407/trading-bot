package idempotency_test

import (
	"context"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	goredis "github.com/redis/go-redis/v9"

	"github.com/johnson407/trading-bot/engine/internal/idempotency"
	"github.com/johnson407/trading-bot/engine/internal/protocol"
)

func newStore(t *testing.T) (*idempotency.Store, *miniredis.Miniredis, *goredis.Client) {
	t.Helper()

	mini := miniredis.RunT(t)
	client := goredis.NewClient(&goredis.Options{Addr: mini.Addr()})
	t.Cleanup(func() { _ = client.Close() })

	return idempotency.New(client), mini, client
}

func TestMarkCompletedUsesSevenDayTTL(t *testing.T) {
	t.Parallel()

	store, mini, _ := newStore(t)
	ctx := context.Background()

	if err := store.MarkCompleted(ctx, "command-1"); err != nil {
		t.Fatalf("MarkCompleted() error = %v", err)
	}

	completed, err := store.Completed(ctx, "command-1")
	if err != nil {
		t.Fatalf("Completed() error = %v", err)
	}

	if !completed {
		t.Fatal("Completed() = false, want true")
	}

	ttl := mini.TTL(protocol.IdempotencyKey("command-1"))
	if ttl < 7*24*time.Hour {
		t.Errorf("ttl = %s, want at least 7 days", ttl)
	}
}

func TestCompletedIsFalseForUnknownID(t *testing.T) {
	t.Parallel()

	store, _, _ := newStore(t)

	completed, err := store.Completed(context.Background(), "unknown")
	if err != nil {
		t.Fatalf("Completed() error = %v", err)
	}

	if completed {
		t.Fatal("Completed() = true, want false")
	}
}

func TestEmptyIDIsRejected(t *testing.T) {
	t.Parallel()

	store, _, _ := newStore(t)

	if err := store.MarkCompleted(context.Background(), " "); err == nil {
		t.Error("MarkCompleted(empty) error = nil, want error")
	}

	if _, err := store.Completed(context.Background(), ""); err == nil {
		t.Error("Completed(empty) error = nil, want error")
	}
}
