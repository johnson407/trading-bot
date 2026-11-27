package runtime

import (
	"bytes"
	"context"
	"fmt"
	"log/slog"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/protocol"
)

type autoResumeBotOptions struct {
	mode          string
	exchange      string
	status        string
	desiredStatus string
	pauseReason   *string
	fresh         bool
	archived      bool
	killSwitch    bool
}

func autoResumeTestPool(t *testing.T) *pgxpool.Pool {
	t.Helper()

	dsn := os.Getenv("RUNTIME_TEST_DSN")
	if dsn == "" {
		t.Skip("set RUNTIME_TEST_DSN to run the PostgreSQL runtime test")
	}

	ctx := context.Background()
	pool, err := pgxpool.New(ctx, dsn)
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	t.Cleanup(pool.Close)

	return pool
}

func autoResumeTestUser(t *testing.T, ctx context.Context, pool *pgxpool.Pool) int64 {
	t.Helper()

	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Auto-resume test', $1, 'x', now(), now()) RETURNING id`,
		fmt.Sprintf("auto-resume-%d@example.test", time.Now().UnixNano()),
	).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID)
	})

	return userID
}

func insertAutoResumeBot(t *testing.T, ctx context.Context, pool *pgxpool.Pool, userID int64, opts autoResumeBotOptions) int64 {
	t.Helper()

	if opts.mode == "" {
		opts.mode = "paper"
	}
	if opts.exchange == "" {
		opts.exchange = "binance"
	}
	if opts.status == "" {
		opts.status = "paused"
	}
	if opts.desiredStatus == "" {
		opts.desiredStatus = "running"
	}

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		    capital_allocation, quote_inventory, tick_interval_seconds, settings,
		    last_successful_tick_at, pause_reason, pause_reason_recorded_at,
		    deleted_at, kill_switch_requested_at, created_at, updated_at)
		 VALUES ($1, $2, $3, 'BTC/USDT', 'simple-grid', $4, $5, $6,
		    '1000', '1000', 1, '{}'::jsonb, now() - interval '1 hour', $7,
		    CASE WHEN $7::text IS NULL THEN NULL
		         WHEN $8 THEN now()
		         ELSE now() - interval '1 second' END,
		    CASE WHEN $9 THEN now() ELSE NULL END,
		    CASE WHEN $10 THEN now() ELSE NULL END,
		    now(), now())
		 RETURNING id`,
		userID, fmt.Sprintf("Auto-resume %d", time.Now().UnixNano()), opts.exchange,
		opts.mode, opts.status, opts.desiredStatus,
		opts.pauseReason, opts.fresh, opts.archived, opts.killSwitch,
	).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}

	return botID
}

func pauseCausePtr(cause string) *string { return &cause }

func autoResumeTestRuntime(pool *pgxpool.Pool, logs *bytes.Buffer) *Runtime {
	var logger *slog.Logger
	if logs != nil {
		logger = slog.New(slog.NewTextHandler(logs, nil))
	}
	return New(pool, nil, logger)
}

func assertAutoResumeState(t *testing.T, ctx context.Context, pool *pgxpool.Pool, botID int64, status string, wantPauseReason *string) {
	t.Helper()

	var gotStatus string
	var reason *string
	if err := pool.QueryRow(ctx,
		`SELECT status, pause_reason FROM bots WHERE id = $1`, botID,
	).Scan(&gotStatus, &reason); err != nil {
		t.Fatalf("read bot %d: %v", botID, err)
	}
	if gotStatus != status {
		t.Fatalf("bot %d status = %q, want %q", botID, gotStatus, status)
	}
	if wantPauseReason == nil {
		if reason != nil {
			t.Fatalf("bot %d pause_reason = %q, want NULL", botID, *reason)
		}
		return
	}
	if reason == nil || *reason != *wantPauseReason {
		t.Fatalf("bot %d pause_reason = %v, want %q", botID, reason, *wantPauseReason)
	}
}

func TestAutoResumeStalePausedPaperBot(t *testing.T) {
	pool := autoResumeTestPool(t)
	ctx := context.Background()
	userID := autoResumeTestUser(t, ctx, pool)
	botID := insertAutoResumeBot(t, ctx, pool, userID, autoResumeBotOptions{
		pauseReason: pauseCausePtr(pauseCauseStaleTick.String()),
		fresh:       true,
	})

	var before time.Time
	if err := pool.QueryRow(ctx, `SELECT last_successful_tick_at FROM bots WHERE id = $1`, botID).Scan(&before); err != nil {
		t.Fatalf("read last success: %v", err)
	}

	var logs bytes.Buffer
	trading := autoResumeTestRuntime(pool, &logs)
	if err := trading.autoResumeStalePausedBots(ctx); err != nil {
		t.Fatalf("auto-resume: %v", err)
	}

	assertAutoResumeState(t, ctx, pool, botID, "running", nil)
	if trading.autoResumePending {
		t.Fatal("autoResumePending = true after a successful pass")
	}

	var after time.Time
	if err := pool.QueryRow(ctx, `SELECT last_successful_tick_at FROM bots WHERE id = $1`, botID).Scan(&after); err != nil {
		t.Fatalf("read last success after: %v", err)
	}
	if !after.Equal(before) {
		t.Fatalf("last_successful_tick_at changed from %v to %v", before, after)
	}

	assertCountQuery(t, ctx, pool, `SELECT COUNT(*) FROM activity_logs WHERE bot_id=$1 AND type='bot.auto_resumed'`, 1, botID)
	if !strings.Contains(logs.String(), "bot auto-resumed") || !strings.Contains(logs.String(), "cause=stale_tick") {
		t.Fatalf("auto-resume log missing cause: %s", logs.String())
	}
}

func TestAutoResumeSafetyMatrix(t *testing.T) {
	pool := autoResumeTestPool(t)
	ctx := context.Background()
	userID := autoResumeTestUser(t, ctx, pool)

	eligible := insertAutoResumeBot(t, ctx, pool, userID, autoResumeBotOptions{
		pauseReason: pauseCausePtr(pauseCauseStaleTick.String()),
		fresh:       true,
	})

	type ineligible struct {
		name   string
		botID  int64
		reason *string
	}
	var cases []ineligible
	add := func(name string, opts autoResumeBotOptions) {
		botID := insertAutoResumeBot(t, ctx, pool, userID, opts)
		cases = append(cases, ineligible{name: name, botID: botID, reason: opts.pauseReason})
	}

	add("live stale pause", autoResumeBotOptions{mode: "live", pauseReason: pauseCausePtr(pauseCauseStaleTick.String()), fresh: true})
	add("unsupported paper exchange", autoResumeBotOptions{exchange: "kraken", pauseReason: pauseCausePtr(pauseCauseStaleTick.String()), fresh: true})
	add("risk pause", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseRiskLimit.String()), fresh: true})
	add("protective exit pause", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseProtectiveExit.String()), fresh: true})
	add("daily loss pause", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseDailyLoss.String()), fresh: true})
	add("kill-switch pause", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseKillSwitch.String()), fresh: true})
	add("tick-failure pause", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseTickFailure.String()), fresh: true})
	add("reconciliation pause", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseReconciliation.String()), fresh: true})
	add("live-gate pause", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseLiveGate.String()), fresh: true})
	add("strategy-stop pause", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseStrategyStop.String()), fresh: true})
	add("user pause", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseUser.String()), fresh: true})
	add("legacy null cause", autoResumeBotOptions{})
	add("stale provenance", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseStaleTick.String()), fresh: false})
	add("archived stale pause", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseStaleTick.String()), fresh: true, archived: true})
	add("pending kill switch", autoResumeBotOptions{pauseReason: pauseCausePtr(pauseCauseStaleTick.String()), fresh: true, killSwitch: true})
	add("user desired pause", autoResumeBotOptions{desiredStatus: "paused", pauseReason: pauseCausePtr(pauseCauseStaleTick.String()), fresh: true})
	add("already running", autoResumeBotOptions{status: "running", pauseReason: pauseCausePtr(pauseCauseStaleTick.String()), fresh: true})

	trading := autoResumeTestRuntime(pool, nil)
	if err := trading.autoResumeStalePausedBots(ctx); err != nil {
		t.Fatalf("auto-resume: %v", err)
	}

	assertAutoResumeState(t, ctx, pool, eligible, "running", nil)
	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			status := "paused"
			if testCase.name == "already running" {
				status = "running"
			}
			assertAutoResumeState(t, ctx, pool, testCase.botID, status, testCase.reason)
		})
	}
}

func TestAutoResumeRunsOncePerProcess(t *testing.T) {
	pool := autoResumeTestPool(t)
	ctx := context.Background()
	userID := autoResumeTestUser(t, ctx, pool)

	first := insertAutoResumeBot(t, ctx, pool, userID, autoResumeBotOptions{
		pauseReason: pauseCausePtr(pauseCauseStaleTick.String()),
		fresh:       true,
	})
	second := insertAutoResumeBot(t, ctx, pool, userID, autoResumeBotOptions{
		pauseReason: pauseCausePtr(pauseCauseStaleTick.String()),
		fresh:       true,
	})

	trading := autoResumeTestRuntime(pool, nil)
	if err := trading.reconcile(ctx); err != nil {
		t.Fatalf("first reconcile: %v", err)
	}
	assertAutoResumeState(t, ctx, pool, first, "running", nil)
	assertAutoResumeState(t, ctx, pool, second, "running", nil)

	third := insertAutoResumeBot(t, ctx, pool, userID, autoResumeBotOptions{
		pauseReason: pauseCausePtr(pauseCauseStaleTick.String()),
		fresh:       true,
	})
	if err := trading.reconcile(ctx); err != nil {
		t.Fatalf("second reconcile: %v", err)
	}
	assertAutoResumeState(t, ctx, pool, third, "paused", pauseCausePtr(pauseCauseStaleTick.String()))
	// First-tick grace must keep the earlier resumed bot running even though
	// its last successful tick predates this process.
	assertAutoResumeState(t, ctx, pool, first, "running", nil)

	if _, err := pool.Exec(ctx,
		`UPDATE bots SET status='paused', pause_reason='stale_tick', pause_reason_recorded_at=now(), updated_at=now()
		  WHERE id=$1`, first,
	); err != nil {
		t.Fatalf("re-pause first bot: %v", err)
	}
	if err := trading.reconcile(ctx); err != nil {
		t.Fatalf("third reconcile: %v", err)
	}
	assertAutoResumeState(t, ctx, pool, first, "paused", pauseCausePtr(pauseCauseStaleTick.String()))
}

func TestPauseWritesCauseAndRecordedTime(t *testing.T) {
	pool := autoResumeTestPool(t)
	ctx := context.Background()
	userID := autoResumeTestUser(t, ctx, pool)
	botID := insertAutoResumeBot(t, ctx, pool, userID, autoResumeBotOptions{
		status:        "running",
		desiredStatus: "running",
	})

	trading := autoResumeTestRuntime(pool, nil)
	bot := &Bot{ID: botID, UserID: userID}
	causes := []pauseCause{
		pauseCauseStaleTick,
		pauseCauseRiskLimit,
		pauseCauseProtectiveExit,
		pauseCauseDailyLoss,
		pauseCauseKillSwitch,
		pauseCauseReconciliation,
		pauseCauseTickFailure,
		pauseCauseLiveGate,
		pauseCauseStrategyStop,
		pauseCauseUnsupportedExchange,
		pauseCauseUser,
	}
	for _, cause := range causes {
		if err := trading.pause(ctx, bot, cause, "test pause", "", "", "warning"); err != nil {
			t.Fatalf("pause %s: %v", cause, err)
		}

		var reason string
		var matches bool
		if err := pool.QueryRow(ctx,
			`SELECT pause_reason, pause_reason_recorded_at = updated_at FROM bots WHERE id=$1`, botID,
		).Scan(&reason, &matches); err != nil {
			t.Fatalf("read pause provenance for %s: %v", cause, err)
		}
		if reason != cause.String() || !matches {
			t.Fatalf("pause %s stored reason=%q recorded_matches=%v", cause, reason, matches)
		}
	}

	func() {
		defer func() {
			if recover() == nil {
				t.Fatal("pause with an invalid cause did not panic")
			}
		}()
		_ = trading.pause(ctx, bot, pauseCause("bogus"), "test", "", "", "warning")
	}()

	canceled, cancel := context.WithCancel(ctx)
	cancel()
	if err := trading.pause(canceled, bot, pauseCauseStaleTick, "test", "", "", "warning"); err == nil {
		t.Fatal("pause with a canceled context returned nil, want visible write failure")
	}
}

func TestWaitForPauseProvenanceFailsClosed(t *testing.T) {
	pool := autoResumeTestPool(t)
	ctx := context.Background()

	if err := WaitForPauseProvenance(ctx, pool, nil); err != nil {
		t.Fatalf("migrated schema probe: %v", err)
	}

	tx, err := pool.Begin(ctx)
	if err != nil {
		t.Fatalf("begin: %v", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	schema := fmt.Sprintf("pause_probe_%d", time.Now().UnixNano())
	if _, err := tx.Exec(ctx, fmt.Sprintf(`CREATE SCHEMA %s`, schema)); err != nil {
		t.Fatalf("create schema: %v", err)
	}
	if _, err := tx.Exec(ctx, fmt.Sprintf(`SET LOCAL search_path TO %s`, schema)); err != nil {
		t.Fatalf("set search path: %v", err)
	}
	if _, err := tx.Exec(ctx, `CREATE TABLE bots (id bigint primary key)`); err != nil {
		t.Fatalf("create un-migrated bots table: %v", err)
	}

	probeCtx, cancel := context.WithTimeout(ctx, 700*time.Millisecond)
	defer cancel()
	if err := WaitForPauseProvenance(probeCtx, tx, nil); err == nil {
		t.Fatal("probe on an un-migrated schema returned nil")
	} else if !strings.Contains(err.Error(), "pause provenance columns are not ready") {
		t.Fatalf("probe error = %v, want actionable pause provenance message", err)
	}
}

func TestUserStatusCommandsClearPauseProvenance(t *testing.T) {
	pool := autoResumeTestPool(t)
	ctx := context.Background()
	userID := autoResumeTestUser(t, ctx, pool)
	botID := insertAutoResumeBot(t, ctx, pool, userID, autoResumeBotOptions{
		pauseReason: pauseCausePtr(pauseCauseStaleTick.String()),
		fresh:       true,
	})

	trading := autoResumeTestRuntime(pool, nil)
	envelope := func(typ string) protocol.Envelope {
		return protocol.Envelope{
			V:      protocol.Version,
			ID:     fmt.Sprintf("auto-resume-test-%d", time.Now().UnixNano()),
			Type:   typ,
			TS:     time.Now().UTC().Format(time.RFC3339),
			UserID: userID,
			BotID:  botID,
		}
	}

	if err := trading.Process(ctx, envelope(protocol.CommandBotStart)); err != nil {
		t.Fatalf("start: %v", err)
	}
	assertAutoResumeState(t, ctx, pool, botID, "running", nil)
	assertNoPauseRecordedAt(t, ctx, pool, botID)

	if _, err := pool.Exec(ctx,
		`UPDATE bots SET status='paused', pause_reason='stale_tick',
		        pause_reason_recorded_at=now(), updated_at=now()
		  WHERE id=$1`, botID,
	); err != nil {
		t.Fatalf("re-pause bot: %v", err)
	}
	if err := trading.Process(ctx, envelope(protocol.CommandBotStop)); err != nil {
		t.Fatalf("stop: %v", err)
	}
	assertAutoResumeState(t, ctx, pool, botID, "stopped", nil)
	assertNoPauseRecordedAt(t, ctx, pool, botID)
}

func TestAutoResumeSkipsProvenanceInvalidatedByOlderEngine(t *testing.T) {
	pool := autoResumeTestPool(t)
	ctx := context.Background()
	userID := autoResumeTestUser(t, ctx, pool)
	botID := insertAutoResumeBot(t, ctx, pool, userID, autoResumeBotOptions{
		pauseReason: pauseCausePtr(pauseCauseStaleTick.String()),
		fresh:       true,
	})

	// An older engine image changes status/updated_at without knowing about
	// pause provenance. The recorded time no longer matches updated_at.
	if _, err := pool.Exec(ctx, `UPDATE bots SET updated_at = updated_at + interval '1 second' WHERE id=$1`, botID); err != nil {
		t.Fatalf("invalidate provenance: %v", err)
	}

	trading := autoResumeTestRuntime(pool, nil)
	if err := trading.autoResumeStalePausedBots(ctx); err != nil {
		t.Fatalf("auto-resume: %v", err)
	}
	assertAutoResumeState(t, ctx, pool, botID, "paused", pauseCausePtr(pauseCauseStaleTick.String()))
}

func TestAutoResumeRetriesAfterTransientUpdateFailure(t *testing.T) {
	pool := autoResumeTestPool(t)
	ctx := context.Background()
	userID := autoResumeTestUser(t, ctx, pool)
	botID := insertAutoResumeBot(t, ctx, pool, userID, autoResumeBotOptions{
		pauseReason: pauseCausePtr(pauseCauseStaleTick.String()),
		fresh:       true,
	})

	trading := autoResumeTestRuntime(pool, nil)
	if _, err := pool.Exec(ctx, `
		CREATE OR REPLACE FUNCTION test_block_auto_resume() RETURNS trigger AS $$
		BEGIN
			IF OLD.pause_reason = 'stale_tick' AND NEW.status = 'running' AND NEW.pause_reason IS NULL THEN
				RAISE EXCEPTION 'injected auto-resume update failure';
			END IF;
			RETURN NEW;
		END;
		$$ LANGUAGE plpgsql;
		CREATE TRIGGER test_block_auto_resume_trigger
			BEFORE UPDATE ON bots
			FOR EACH ROW EXECUTE FUNCTION test_block_auto_resume();
	`); err != nil {
		t.Fatalf("install failure trigger: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DROP TRIGGER IF EXISTS test_block_auto_resume_trigger ON bots`)
		_, _ = pool.Exec(context.Background(), `DROP FUNCTION IF EXISTS test_block_auto_resume()`)
	})

	if err := trading.autoResumeStalePausedBots(ctx); err == nil {
		t.Fatal("auto-resume with injected update failure returned nil")
	}
	if !trading.autoResumePending {
		t.Fatal("autoResumePending = false after a transient failure; recovery will not retry")
	}
	assertAutoResumeState(t, ctx, pool, botID, "paused", pauseCausePtr(pauseCauseStaleTick.String()))

	if _, err := pool.Exec(ctx, `DROP TRIGGER IF EXISTS test_block_auto_resume_trigger ON bots`); err != nil {
		t.Fatalf("drop failure trigger: %v", err)
	}
	if _, err := pool.Exec(ctx, `DROP FUNCTION IF EXISTS test_block_auto_resume()`); err != nil {
		t.Fatalf("drop failure function: %v", err)
	}

	if err := trading.autoResumeStalePausedBots(ctx); err != nil {
		t.Fatalf("retry auto-resume: %v", err)
	}
	assertAutoResumeState(t, ctx, pool, botID, "running", nil)
	if trading.autoResumePending {
		t.Fatal("autoResumePending = true after a successful retry")
	}
}

func TestAutoResumeActivityFailureDoesNotRollBackResume(t *testing.T) {
	pool := autoResumeTestPool(t)
	ctx := context.Background()
	userID := autoResumeTestUser(t, ctx, pool)
	botID := insertAutoResumeBot(t, ctx, pool, userID, autoResumeBotOptions{
		pauseReason: pauseCausePtr(pauseCauseStaleTick.String()),
		fresh:       true,
	})

	if _, err := pool.Exec(ctx, `
		CREATE OR REPLACE FUNCTION test_block_auto_resume_activity() RETURNS trigger AS $$
		BEGIN
			IF NEW.type = 'bot.auto_resumed' THEN
				RAISE EXCEPTION 'injected activity insert failure';
			END IF;
			RETURN NEW;
		END;
		$$ LANGUAGE plpgsql;
		CREATE TRIGGER test_block_auto_resume_activity_trigger
			BEFORE INSERT ON activity_logs
			FOR EACH ROW EXECUTE FUNCTION test_block_auto_resume_activity();
	`); err != nil {
		t.Fatalf("install activity failure trigger: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DROP TRIGGER IF EXISTS test_block_auto_resume_activity_trigger ON activity_logs`)
		_, _ = pool.Exec(context.Background(), `DROP FUNCTION IF EXISTS test_block_auto_resume_activity()`)
	})

	var logs bytes.Buffer
	trading := autoResumeTestRuntime(pool, &logs)
	if err := trading.autoResumeStalePausedBots(ctx); err != nil {
		t.Fatalf("auto-resume with activity failure: %v", err)
	}
	assertAutoResumeState(t, ctx, pool, botID, "running", nil)
	if !strings.Contains(logs.String(), "record activity failed") {
		t.Fatalf("activity failure was not logged: %s", logs.String())
	}
}

func assertNoPauseRecordedAt(t *testing.T, ctx context.Context, pool *pgxpool.Pool, botID int64) {
	t.Helper()

	var recordedAt *time.Time
	if err := pool.QueryRow(ctx, `SELECT pause_reason_recorded_at FROM bots WHERE id=$1`, botID).Scan(&recordedAt); err != nil {
		t.Fatalf("read pause_reason_recorded_at: %v", err)
	}
	if recordedAt != nil {
		t.Fatalf("pause_reason_recorded_at = %v, want NULL", *recordedAt)
	}
}

func assertCountQuery(t *testing.T, ctx context.Context, pool *pgxpool.Pool, query string, want int, args ...any) {
	t.Helper()

	var got int
	if err := pool.QueryRow(ctx, query, args...).Scan(&got); err != nil {
		t.Fatalf("count query: %v", err)
	}
	if got != want {
		t.Fatalf("count = %d, want %d for %s", got, want, query)
	}
}
