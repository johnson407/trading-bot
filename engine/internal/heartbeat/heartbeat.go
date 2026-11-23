// Package heartbeat holds the documentation-only heartbeat stub for phase 1.
//
// It implements the Redis key shape described in the change design
// (openspec/changes/adopt-hybrid-go-engine/design.md, "Heartbeat") but is
// deliberately NOT wired into the server, a scheduler, or any trading path.
// Phase 1 has no command consumer, no market data subscription, and no order
// logic. A later phase must call this from the engine loop and gate readiness
// on the heartbeat; until then, calling it is an explicit operator/tooling
// action only.
package heartbeat

import (
	"context"
	"encoding/json"
	"time"
)

// Key is the Redis key Laravel health and the dashboard read.
const Key = "engine:heartbeat"

// TTL matches the design: refreshed at most every five seconds, expiring in
// ten seconds so a dead engine becomes visibly unavailable.
const TTL = 10 * time.Second

// Setter is the minimal Redis capability the stub needs. go-redis satisfies it
// via a small adapter in main once the heartbeat is actually wired.
type Setter interface {
	Set(ctx context.Context, key string, value []byte, ttl time.Duration) error
}

// Payload is the JSON stored at engine:heartbeat. It carries no secrets.
type Payload struct {
	TS          string `json:"ts"`
	Revision    int64  `json:"revision"`
	RunningBots int    `json:"running_bots"`
}

// Stub writes placeholder heartbeats. It performs no trading work.
type Stub struct {
	setter   Setter
	now      func() time.Time
	revision int64
}

// NewStub returns a heartbeat stub backed by setter.
func NewStub(setter Setter) *Stub {
	return &Stub{setter: setter, now: time.Now}
}

// WritePlaceholder SETs engine:heartbeat with a TTL of ten seconds and a UTC
// RFC 3339 timestamp. Phase 1 hard-codes zero running bots.
func (s *Stub) WritePlaceholder(ctx context.Context, runningBots int) error {
	s.revision++

	payload := Payload{
		TS:          s.now().UTC().Format(time.RFC3339),
		Revision:    s.revision,
		RunningBots: runningBots,
	}

	encoded, err := json.Marshal(payload)
	if err != nil {
		return err
	}

	return s.setter.Set(ctx, Key, encoded, TTL)
}
