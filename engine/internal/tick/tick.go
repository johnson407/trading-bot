// Package tick ports the PHP bots:dispatch due-check, the RunBotTick
// uniqueness/overlap policy, and the BotHealthMonitor stale threshold. It is
// intentionally not wired into main yet: Laravel remains the trading runtime.
package tick

import (
	"context"
	"fmt"
	"sync"
	"time"
)

// Defaults from dispatch/health behaviour and design.md.
const (
	DefaultTickIntervalSeconds   = 60
	MinimumTickIntervalSeconds   = 1
	DefaultStaleAfterSeconds     = 180
	DefaultHealthIntervalSeconds = 30
	DefaultPollInterval          = time.Second
)

// RunBotTick policy recorded by the PHP golden fixture.
const (
	JobTries            = 1
	JobUniqueFor        = 10 * time.Second
	OverlapExpireAfter  = 30 * time.Second
	OverlapReleaseAfter = time.Duration(0)
)

// Bot is the due-check view of a bots row.
type Bot struct {
	ID                  int64
	Status              string
	TickIntervalSeconds int
	LastTickAt          *time.Time
}

// Running reports whether bots:dispatch would consider the bot.
func (b Bot) Running() bool {
	return b.Status == "running"
}

// Interval returns the effective tick interval. PHP clamps values below one
// second to 1 second.
func Interval(seconds int) time.Duration {
	if seconds < MinimumTickIntervalSeconds {
		seconds = MinimumTickIntervalSeconds
	}

	return time.Duration(seconds) * time.Second
}

// IsDue mirrors the bots:dispatch test:
//
//	if last_tick_at !== null && last_tick_at > now - interval { skip }
//
// A nil last tick is due immediately. Equality is due.
func IsDue(lastTickAt *time.Time, now time.Time, tickIntervalSeconds int) bool {
	if lastTickAt == nil {
		return true
	}

	dueAt := now.Add(-Interval(tickIntervalSeconds))

	return !lastTickAt.After(dueAt)
}

// DispatchKey is the per-bot cache guard key from DispatchBotTicks.
func DispatchKey(botID int64) string {
	return fmt.Sprintf("bot-dispatch:%d", botID)
}

// UniqueID is the RunBotTick::uniqueId key.
func UniqueID(botID int64) string {
	return fmt.Sprintf("bot-tick:%d", botID)
}

// OverlapKey is the WithoutOverlapping key used by RunBotTick.
func OverlapKey(botID int64) string {
	return UniqueID(botID)
}

// Guard mirrors Laravel Cache::add(key, 1, $interval) and Cache::forget(key).
type Guard interface {
	Add(key string, ttl time.Duration) bool
	Forget(key string)
}

// MemoryGuard is an in-process Guard for tests and the future engine wiring.
// A Redis-backed Guard can replace it without changing the dispatcher.
type MemoryGuard struct {
	mu      sync.Mutex
	entries map[string]time.Time
	now     func() time.Time
}

// NewMemoryGuard creates an empty in-process guard.
func NewMemoryGuard() *MemoryGuard {
	return &MemoryGuard{
		entries: make(map[string]time.Time),
		now:     time.Now,
	}
}

// Add records the key for ttl unless it is still present, matching Cache::add.
func (g *MemoryGuard) Add(key string, ttl time.Duration) bool {
	g.mu.Lock()
	defer g.mu.Unlock()

	now := g.now()
	if expiresAt, exists := g.entries[key]; exists && now.Before(expiresAt) {
		return false
	}

	g.entries[key] = now.Add(ttl)

	return true
}

// Forget removes the key, matching Cache::forget.
func (g *MemoryGuard) Forget(key string) {
	g.mu.Lock()
	defer g.mu.Unlock()

	delete(g.entries, key)
}

// InFlight enforces at most one evaluation per bot, matching the unique job and
// WithoutOverlapping middleware in RunBotTick.
type InFlight struct {
	mu     sync.Mutex
	active map[int64]struct{}
}

// NewInFlight creates an empty in-flight tracker.
func NewInFlight() *InFlight {
	return &InFlight{active: make(map[int64]struct{})}
}

// TryBegin reserves the bot. It returns false when an evaluation is already
// running.
func (i *InFlight) TryBegin(botID int64) bool {
	i.mu.Lock()
	defer i.mu.Unlock()

	if _, running := i.active[botID]; running {
		return false
	}

	i.active[botID] = struct{}{}

	return true
}

// Finish releases the bot.
func (i *InFlight) Finish(botID int64) {
	i.mu.Lock()
	defer i.mu.Unlock()

	delete(i.active, botID)
}

// Active reports whether an evaluation is in progress.
func (i *InFlight) Active(botID int64) bool {
	i.mu.Lock()
	defer i.mu.Unlock()

	_, active := i.active[botID]

	return active
}

// Source lists the running bots for one dispatcher pass.
type Source interface {
	RunningBots(ctx context.Context) ([]Bot, error)
}

// Scheduler is the Go equivalent of the 1-second bots:dispatch command. Run is
// not started by main in phase 4; later phases supply a PostgreSQL Source.
type Scheduler struct {
	Source    Source
	Evaluate  func(ctx context.Context, bot Bot) error
	Guard     Guard
	InFlight  *InFlight
	Now       func() time.Time
	PollEvery time.Duration
}

// NewScheduler builds a scheduler with defaults. Evaluate is required.
func NewScheduler(source Source, evaluate func(ctx context.Context, bot Bot) error) *Scheduler {
	return &Scheduler{
		Source:    source,
		Evaluate:  evaluate,
		Guard:     NewMemoryGuard(),
		InFlight:  NewInFlight(),
		Now:       time.Now,
		PollEvery: DefaultPollInterval,
	}
}

// Run performs the due-check once a second until ctx is cancelled.
func (s *Scheduler) Run(ctx context.Context) error {
	pollEvery := s.PollEvery
	if pollEvery <= 0 {
		pollEvery = DefaultPollInterval
	}

	ticker := time.NewTicker(pollEvery)
	defer ticker.Stop()

	for {
		if _, err := s.RunOnce(ctx); err != nil {
			return err
		}

		select {
		case <-ctx.Done():
			return nil
		case <-ticker.C:
		}
	}
}

// RunOnce performs one bots:dispatch pass and returns the number of started
// evaluations. A bot is dispatched only when it is running, due, not blocked by
// the per-interval cache guard, and has no evaluation in flight.
func (s *Scheduler) RunOnce(ctx context.Context) (int, error) {
	running, err := s.Source.RunningBots(ctx)
	if err != nil {
		return 0, err
	}

	now := s.Now()
	dispatched := 0

	for _, bot := range running {
		if !bot.Running() || !IsDue(bot.LastTickAt, now, bot.TickIntervalSeconds) {
			continue
		}

		interval := Interval(bot.TickIntervalSeconds)
		if !s.Guard.Add(DispatchKey(bot.ID), interval) {
			continue
		}

		if !s.InFlight.TryBegin(bot.ID) {
			continue
		}

		dispatched++

		go func(bot Bot) {
			defer s.InFlight.Finish(bot.ID)

			_ = s.Evaluate(ctx, bot)
		}(bot)
	}

	return dispatched, nil
}

// EffectiveStaleThreshold mirrors BotHealthMonitor:
//
//	max(stale_after_seconds, max(1, tick_interval_seconds) + health_interval_seconds)
func EffectiveStaleThreshold(staleAfterSeconds, healthIntervalSeconds, tickIntervalSeconds int) time.Duration {
	if tickIntervalSeconds < MinimumTickIntervalSeconds {
		tickIntervalSeconds = MinimumTickIntervalSeconds
	}

	threshold := tickIntervalSeconds + healthIntervalSeconds
	if staleAfterSeconds > threshold {
		threshold = staleAfterSeconds
	}

	return time.Duration(threshold) * time.Second
}

// LastActivity mirrors:
//
//	last_successful_tick_at ?? last_tick_at ?? created_at
func LastActivity(lastSuccessfulTickAt, lastTickAt, createdAt *time.Time) *time.Time {
	if lastSuccessfulTickAt != nil {
		return lastSuccessfulTickAt
	}

	if lastTickAt != nil {
		return lastTickAt
	}

	return createdAt
}

// IsStale reports whether the last activity is strictly older than the cutoff.
func IsStale(last *time.Time, now time.Time, threshold time.Duration) bool {
	return last != nil && last.Before(now.Add(-threshold))
}
