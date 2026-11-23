package tick_test

import (
	"context"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/goldenfixture"
	"github.com/johnson407/trading-bot/engine/internal/tick"
)

type tickLoopFixture struct {
	DispatchDueCheck struct {
		Cases []struct {
			Label               string `json:"label"`
			Status              string `json:"status"`
			TickIntervalSeconds int    `json:"tick_interval_seconds"`
			LastTickAgeSeconds  *int   `json:"last_tick_age_seconds"`
			Dispatched          int    `json:"dispatched"`
			ExitCode            int    `json:"exit_code"`
		} `json:"cases"`
	} `json:"dispatch_due_check"`

	DispatchCacheGuard struct {
		FirstDispatch                int `json:"first_dispatch"`
		SecondDispatchInsideInterval int `json:"second_dispatch_inside_interval"`
		AfterCacheForget             int `json:"after_cache_forget"`
	} `json:"dispatch_cache_guard"`

	RunBotTickJob struct {
		Tries     int    `json:"tries"`
		UniqueFor int    `json:"unique_for"`
		UniqueID  string `json:"unique_id"`
		Overlap   struct {
			Middleware          string `json:"middleware"`
			Key                 string `json:"key"`
			ExpireAfterSeconds  int    `json:"expire_after_seconds"`
			ReleaseAfterSeconds int    `json:"release_after_seconds"`
		} `json:"overlap"`
	} `json:"run_bot_tick_job"`

	Health struct {
		Cases []struct {
			Label                 string `json:"label"`
			TickIntervalSeconds   int    `json:"tick_interval_seconds"`
			StaleAfterSeconds     int    `json:"stale_after_seconds"`
			HealthIntervalSeconds int    `json:"health_interval_seconds"`
			AgeSeconds            int    `json:"age_seconds"`
			PausedCount           int    `json:"paused_count"`
			Paused                bool   `json:"paused"`
		} `json:"cases"`
	} `json:"health"`
}

func loadTickFixture(t *testing.T) tickLoopFixture {
	t.Helper()

	var fixture tickLoopFixture
	if err := goldenfixture.Load("tick_loop", &fixture); err != nil {
		t.Fatal(err)
	}

	return fixture
}

func TestGoldenTickLoopDispatchIntervalOverlapAndSkip(t *testing.T) {
	fixture := loadTickFixture(t)
	now := time.Date(2026, time.November, 23, 0, 0, 0, 0, time.UTC)

	for _, testCase := range fixture.DispatchDueCheck.Cases {
		t.Run(testCase.Label, func(t *testing.T) {
			var lastTickAt *time.Time
			if testCase.LastTickAgeSeconds != nil {
				value := now.Add(-time.Duration(*testCase.LastTickAgeSeconds) * time.Second)
				lastTickAt = &value
			}

			bot := tick.Bot{
				ID:                  1,
				Status:              testCase.Status,
				TickIntervalSeconds: testCase.TickIntervalSeconds,
				LastTickAt:          lastTickAt,
			}

			dispatched := 0
			guard := tick.NewMemoryGuard()

			if bot.Running() && tick.IsDue(bot.LastTickAt, now, bot.TickIntervalSeconds) {
				if !guard.Add(tick.DispatchKey(bot.ID), tick.Interval(bot.TickIntervalSeconds)) {
					t.Fatal("first due dispatch must pass the cache guard")
				}

				dispatched = 1

				if guard.Add(tick.DispatchKey(bot.ID), tick.Interval(bot.TickIntervalSeconds)) {
					t.Fatal("second dispatch inside the interval must be blocked")
				}
			}

			if dispatched != testCase.Dispatched {
				t.Fatalf("dispatched = %d, want %d", dispatched, testCase.Dispatched)
			}

			if testCase.ExitCode != 0 {
				t.Fatalf("fixture exit code = %d, Go scheduler has no command exit code", testCase.ExitCode)
			}
		})
	}
}

func TestGoldenTickLoopDispatchCacheGuard(t *testing.T) {
	fixture := loadTickFixture(t)
	expected := fixture.DispatchCacheGuard

	guard := tick.NewMemoryGuard()
	key := tick.DispatchKey(42)
	dispatched := 0

	if guard.Add(key, 60*time.Second) {
		dispatched++
	}

	first := dispatched
	if guard.Add(key, 60*time.Second) {
		dispatched++
	}

	second := dispatched

	guard.Forget(key)
	if guard.Add(key, 60*time.Second) {
		dispatched++
	}

	third := dispatched

	if first != expected.FirstDispatch || second != expected.SecondDispatchInsideInterval || third != expected.AfterCacheForget {
		t.Fatalf("cache guard = %d/%d/%d, want %d/%d/%d",
			first, second, third,
			expected.FirstDispatch, expected.SecondDispatchInsideInterval, expected.AfterCacheForget,
		)
	}
}

func TestGoldenTickLoopJobUniquenessAndOverlapPolicy(t *testing.T) {
	fixture := loadTickFixture(t)
	job := fixture.RunBotTickJob

	if job.Tries != tick.JobTries {
		t.Fatalf("tries = %d, want %d", tick.JobTries, job.Tries)
	}

	if time.Duration(job.UniqueFor)*time.Second != tick.JobUniqueFor {
		t.Fatalf("unique_for = %ds, want %s", job.UniqueFor, tick.JobUniqueFor)
	}

	if job.UniqueID != tick.UniqueID(42) {
		t.Fatalf("unique_id = %s, want %s", tick.UniqueID(42), job.UniqueID)
	}

	if job.Overlap.Key != tick.OverlapKey(42) {
		t.Fatalf("overlap key = %s, want %s", tick.OverlapKey(42), job.Overlap.Key)
	}

	if time.Duration(job.Overlap.ExpireAfterSeconds)*time.Second != tick.OverlapExpireAfter {
		t.Fatalf("expire_after = %ds, want %s", job.Overlap.ExpireAfterSeconds, tick.OverlapExpireAfter)
	}

	if time.Duration(job.Overlap.ReleaseAfterSeconds)*time.Second != tick.OverlapReleaseAfter {
		t.Fatalf("release_after = %ds, want %s", job.Overlap.ReleaseAfterSeconds, tick.OverlapReleaseAfter)
	}
}

func TestGoldenTickLoopHealthStaleThreshold(t *testing.T) {
	fixture := loadTickFixture(t)
	now := time.Date(2026, time.November, 23, 0, 0, 0, 0, time.UTC)

	for _, testCase := range fixture.Health.Cases {
		t.Run(testCase.Label, func(t *testing.T) {
			age := time.Duration(testCase.AgeSeconds) * time.Second
			createdAt := now.Add(-age)

			var lastTickAt, lastSuccessfulTickAt *time.Time

			switch testCase.Label {
			case "last_successful_tick_takes_precedence":
				staleTick := now.Add(-500 * time.Second)
				successful := now.Add(-10 * time.Second)
				lastTickAt = &staleTick
				lastSuccessfulTickAt = &successful
			case "created_at_is_the_fallback":
				// last_tick_at and last_successful_tick_at are both null.
			default:
				value := now.Add(-age)
				lastTickAt = &value
				lastSuccessfulTickAt = &value
			}

			last := tick.LastActivity(lastSuccessfulTickAt, lastTickAt, &createdAt)
			threshold := tick.EffectiveStaleThreshold(
				testCase.StaleAfterSeconds,
				testCase.HealthIntervalSeconds,
				testCase.TickIntervalSeconds,
			)
			stale := tick.IsStale(last, now, threshold)

			if stale != testCase.Paused {
				t.Fatalf("stale = %v, want %v (threshold %s)", stale, testCase.Paused, threshold)
			}

			pausedCount := 0
			if stale {
				pausedCount = 1
			}

			if pausedCount != testCase.PausedCount {
				t.Fatalf("paused count = %d, want %d", pausedCount, testCase.PausedCount)
			}
		})
	}
}

type fakeSource struct {
	bots []tick.Bot
}

func (f fakeSource) RunningBots(context.Context) ([]tick.Bot, error) {
	return f.bots, nil
}

func TestSchedulerKeepsOneEvaluationInFlight(t *testing.T) {
	now := time.Date(2026, time.November, 23, 0, 0, 0, 0, time.UTC)
	started := make(chan struct{}, 1)
	release := make(chan struct{})
	done := make(chan struct{})

	scheduler := tick.NewScheduler(fakeSource{
		bots: []tick.Bot{{
			ID:                  7,
			Status:              "running",
			TickIntervalSeconds: 60,
		}},
	}, func(context.Context, tick.Bot) error {
		started <- struct{}{}
		<-release
		close(done)

		return nil
	})
	scheduler.Now = func() time.Time { return now }

	first, err := scheduler.RunOnce(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	if first != 1 {
		t.Fatalf("first pass dispatched %d, want 1", first)
	}

	<-started

	// Forget the per-interval guard so the second pass reaches the in-flight
	// check instead of being stopped by the cache.
	scheduler.Guard.Forget(tick.DispatchKey(7))

	second, err := scheduler.RunOnce(context.Background())
	if err != nil {
		t.Fatal(err)
	}

	if second != 0 {
		t.Fatalf("second pass dispatched %d while the first was in flight, want 0", second)
	}

	if !scheduler.InFlight.Active(7) {
		t.Fatal("evaluation must be marked in flight")
	}

	close(release)
	<-done

	deadline := time.Now().Add(time.Second)
	for scheduler.InFlight.Active(7) && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}

	if scheduler.InFlight.Active(7) {
		t.Fatal("in-flight marker was not released")
	}
}
