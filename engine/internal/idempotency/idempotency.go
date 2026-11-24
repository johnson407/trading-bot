// Package idempotency stores completed command ids in Redis. A command is
// marked complete only after its durable Postgres effects commit, and the
// consumer XACKs only after this write succeeds.
package idempotency

import (
	"context"
	"fmt"
	"strings"

	goredis "github.com/redis/go-redis/v9"

	"github.com/johnson407/trading-bot/engine/internal/protocol"
)

// Store is the Redis-backed idempotency store.
type Store struct {
	client *goredis.Client
}

// New returns a store backed by client.
func New(client *goredis.Client) *Store {
	return &Store{client: client}
}

// Completed reports whether id was already completed or definitively rejected.
func (s *Store) Completed(ctx context.Context, id string) (bool, error) {
	if err := validateID(id); err != nil {
		return false, err
	}

	count, err := s.client.Exists(ctx, protocol.IdempotencyKey(id)).Result()
	if err != nil {
		return false, fmt.Errorf("read idempotency key: %w", err)
	}

	return count > 0, nil
}

// MarkCompleted records id for at least seven days.
func (s *Store) MarkCompleted(ctx context.Context, id string) error {
	if err := validateID(id); err != nil {
		return err
	}

	if err := s.client.Set(ctx, protocol.IdempotencyKey(id), "1", protocol.IdempotencyTTL).Err(); err != nil {
		return fmt.Errorf("write idempotency key: %w", err)
	}

	return nil
}

func validateID(id string) error {
	if strings.TrimSpace(id) == "" {
		return fmt.Errorf("command id is empty")
	}

	return nil
}
