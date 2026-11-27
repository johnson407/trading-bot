// Package heartbeat writes and checks the engine heartbeat described in the
// change design (openspec/changes/adopt-hybrid-go-engine/design.md, "Heartbeat").
//
// The engine SETs engine:heartbeat with a 10-second TTL and refreshes it every
// five seconds. The payload carries the heartbeat timestamp, revision, running
// bot count, engine start time, live-trading gate, and market-data snapshot. A
// missing or expired key is a warning only: nothing here pauses bots, cancels
// orders, or activates the kill-switch.
package heartbeat

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"time"
)

// Key is the Redis key Laravel health and the dashboard read.
const Key = "engine:heartbeat"

// TTL matches the design: refreshed at most every five seconds, expiring in
// ten seconds so a dead engine becomes visibly unavailable.
const TTL = 10 * time.Second

// RefreshInterval is the maximum age of a freshly written heartbeat. The design
// requires a refresh at least every five seconds.
const RefreshInterval = 5 * time.Second

// Setter is the minimal Redis capability the writer needs. go-redis satisfies
// it via a small adapter in main.
type Setter interface {
	Set(ctx context.Context, key string, value []byte, ttl time.Duration) error
}

// Reader is the minimal Redis capability the freshness check needs.
type Reader interface {
	Get(ctx context.Context, key string) ([]byte, error)
}

// MarketData is the secret-free market-data state included in a heartbeat.
type MarketData struct {
	Configured    bool       `json:"configured"`
	Mode          string     `json:"mode"`
	Degraded      bool       `json:"degraded"`
	Reason        string     `json:"reason"`
	Symbols       int        `json:"symbols"`
	LastMessageAt *time.Time `json:"last_message_at,omitempty"`
}

// Snapshot is the complete state written to the heartbeat.
type Snapshot struct {
	RunningBots int
	LiveTrading bool
	StartedAt   time.Time
	MarketData  MarketData
}

// Payload is the JSON stored at engine:heartbeat. It carries no secrets.
type Payload struct {
	TS          string     `json:"ts"`
	Revision    int64      `json:"revision"`
	RunningBots int        `json:"running_bots"`
	StartedAt   string     `json:"started_at"`
	LiveTrading bool       `json:"live_trading"`
	MarketData  MarketData `json:"market_data"`
}

// Writer writes heartbeats from the scheduler's snapshot.
type Writer struct {
	setter   Setter
	now      func() time.Time
	revision int64
}

// NewWriter returns a heartbeat writer backed by setter.
func NewWriter(setter Setter) *Writer {
	return &Writer{setter: setter, now: time.Now}
}

// Write SETs engine:heartbeat with a TTL of ten seconds and a UTC RFC 3339
// timestamp. The snapshot supplies the running count, live gate, engine start
// time, and market-data state.
func (s *Writer) Write(ctx context.Context, snapshot Snapshot) error {
	s.revision++

	marketData := snapshot.MarketData
	if marketData.LastMessageAt != nil {
		lastMessage := marketData.LastMessageAt.UTC()
		marketData.LastMessageAt = &lastMessage
	}

	payload := Payload{
		TS:          s.now().UTC().Format(time.RFC3339),
		Revision:    s.revision,
		RunningBots: snapshot.RunningBots,
		StartedAt:   snapshot.StartedAt.UTC().Format(time.RFC3339),
		LiveTrading: snapshot.LiveTrading,
		MarketData:  marketData,
	}

	encoded, err := json.Marshal(payload)
	if err != nil {
		return err
	}

	return s.setter.Set(ctx, Key, encoded, TTL)
}

// Run writes immediately and then refreshes every interval until ctx is
// cancelled. It never pauses bots, cancels orders, or activates the
// kill-switch; an expired key is a dashboard/readiness warning only.
func (s *Writer) Run(ctx context.Context, interval time.Duration, provider func() Snapshot) {
	if interval <= 0 {
		interval = RefreshInterval
	}

	write := func() {
		snapshot := Snapshot{}
		if provider != nil {
			snapshot = provider()
		}

		if err := s.Write(ctx, snapshot); err != nil && ctx.Err() == nil {
			// Log only the error type; a Redis URL must not leak into logs.
			slog.Warn("heartbeat write failed", "error_type", fmt.Sprintf("%T", err))
		}
	}

	write()

	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			write()
		}
	}
}

// CheckFresh accepts a heartbeat whose UTC timestamp is no older than TTL. A
// missing key, malformed payload, or stale timestamp is an unavailable engine.
func CheckFresh(ctx context.Context, reader Reader, now time.Time) error {
	value, err := reader.Get(ctx, Key)
	if err != nil {
		return fmt.Errorf("heartbeat missing: %w", err)
	}

	// Decode only the timestamp. Optional status fields must never make a
	// fresh heartbeat look invalid or trigger a restart.
	var payload struct {
		TS string `json:"ts"`
	}
	if err := json.Unmarshal(value, &payload); err != nil {
		return fmt.Errorf("heartbeat payload is invalid: %w", err)
	}

	timestamp, err := time.Parse(time.RFC3339, payload.TS)
	if err != nil {
		return fmt.Errorf("heartbeat timestamp is invalid: %w", err)
	}

	if timestamp.After(now.UTC().Add(RefreshInterval)) {
		return fmt.Errorf("heartbeat timestamp is in the future")
	}

	if now.UTC().Sub(timestamp) > TTL {
		return fmt.Errorf("heartbeat expired")
	}

	return nil
}
