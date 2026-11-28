package runtime_test

import (
	"context"
	"fmt"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/runtime"
)

func dailyLossTestPool(t *testing.T) *pgxpool.Pool {
	t.Helper()
	dsn := os.Getenv("RUNTIME_TEST_DSN")
	if dsn == "" {
		t.Skip("set RUNTIME_TEST_DSN to run the PostgreSQL runtime test")
	}
	pool, err := pgxpool.New(context.Background(), dsn)
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	t.Cleanup(pool.Close)
	return pool
}

// dailyLossTestBot inserts a running paper bot with a today baseline of zero,
// so a negative realized P&L counts as a loss on the first tick.
func dailyLossTestBot(t *testing.T, pool *pgxpool.Pool, realized string) (int64, int64) {
	t.Helper()
	ctx := context.Background()
	email := fmt.Sprintf("daily-loss-%d@example.test", time.Now().UnixNano())
	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Daily loss', $1, 'x', now(), now())
		 RETURNING id`, email).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID)
	})

	settings := fmt.Sprintf(`{"daily_loss_baseline":{"day":%q,"realized":"0"}}`, time.Now().UTC().Format("2006-01-02"))
	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, realized_pnl, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Daily loss bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'running', 'running',
		 '1000', '1000', $2, 1, $3::jsonb, now(), now())
		 RETURNING id`, userID, realized, settings).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}
	if _, err := pool.Exec(ctx,
		`INSERT INTO strategy_configs (bot_id, strategy_key, configuration, state_version, created_at, updated_at)
		 VALUES ($1, 'simple-grid', $2::jsonb, 1, now(), now())`,
		botID,
		`{"center_price":"100","levels_per_side":2,"spacing_type":"arithmetic","spacing_value":"10","order_quantity":"0.1"}`,
	); err != nil {
		t.Fatalf("insert strategy config: %v", err)
	}
	return userID, botID
}

func assertLastError(t *testing.T, pool *pgxpool.Pool, botID int64, contains string) {
	t.Helper()
	var lastError *string
	if err := pool.QueryRow(context.Background(), `SELECT last_error FROM bots WHERE id = $1`, botID).Scan(&lastError); err != nil {
		t.Fatalf("read last_error: %v", err)
	}
	if lastError == nil {
		t.Fatalf("last_error is nil, want an error containing %q", contains)
	}
	if !strings.Contains(*lastError, contains) {
		t.Fatalf("last_error = %q, want it to contain %q", *lastError, contains)
	}
}

func assertNoLastError(t *testing.T, pool *pgxpool.Pool, botID int64) {
	t.Helper()
	var lastError *string
	if err := pool.QueryRow(context.Background(), `SELECT last_error FROM bots WHERE id = $1`, botID).Scan(&lastError); err != nil {
		t.Fatalf("read last_error: %v", err)
	}
	if lastError != nil {
		t.Fatalf("last_error = %q, want nil", *lastError)
	}
}

func assertConsecutiveFailures(t *testing.T, pool *pgxpool.Pool, botID int64, want int) {
	t.Helper()
	var got int
	if err := pool.QueryRow(context.Background(), `SELECT consecutive_failures FROM bots WHERE id = $1`, botID).Scan(&got); err != nil {
		t.Fatalf("read consecutive_failures: %v", err)
	}
	if got != want {
		t.Fatalf("consecutive_failures = %d, want %d", got, want)
	}
}

// restoreRenamedRiskLimitsTable recovers a previous run that was killed between
// renaming the table and its cleanup, so the next run does not wedge the shared
// test database.
func restoreRenamedRiskLimitsTable(t *testing.T, pool *pgxpool.Pool) {
	t.Helper()
	var leftover bool
	if err := pool.QueryRow(context.Background(),
		`SELECT to_regclass('public.risk_limits_unavailable_test') IS NOT NULL`).Scan(&leftover); err != nil {
		t.Fatalf("check for leftover renamed risk_limits table: %v", err)
	}
	if !leftover {
		return
	}
	if _, err := pool.Exec(context.Background(),
		`ALTER TABLE risk_limits_unavailable_test RENAME TO risk_limits`); err != nil {
		t.Fatalf("restore leftover risk_limits table: %v", err)
	}
}

// restoreRenamedRiskLimitBaselineColumn recovers a previous run that was killed
// while the account baseline column was renamed.
func restoreRenamedRiskLimitBaselineColumn(t *testing.T, pool *pgxpool.Pool) {
	t.Helper()
	var leftover bool
	if err := pool.QueryRow(context.Background(),
		`SELECT EXISTS (
			SELECT 1 FROM information_schema.columns
			WHERE table_schema = 'public' AND table_name = 'risk_limits'
			  AND column_name = 'day_start_realized_pnl_unavailable_test'
		)`).Scan(&leftover); err != nil {
		t.Fatalf("check for leftover renamed day_start_realized_pnl column: %v", err)
	}
	if !leftover {
		return
	}
	if _, err := pool.Exec(context.Background(),
		`ALTER TABLE risk_limits RENAME COLUMN day_start_realized_pnl_unavailable_test TO day_start_realized_pnl`); err != nil {
		t.Fatalf("restore leftover day_start_realized_pnl column: %v", err)
	}
}

func TestDailyLossNullBotOverrideFallsBackToAccountLimit(t *testing.T) {
	pool := dailyLossTestPool(t)
	ctx := context.Background()
	userID, botID := dailyLossTestBot(t, pool, "-200")

	if _, err := pool.Exec(ctx,
		`INSERT INTO risk_limits (scope, user_id, bot_id, daily_loss_limit, created_at, updated_at)
		 VALUES ('user', $1, NULL, '1000', now(), now())`, userID); err != nil {
		t.Fatalf("insert account limit: %v", err)
	}
	if _, err := pool.Exec(ctx,
		`INSERT INTO risk_limits (scope, user_id, bot_id, max_position_notional, max_open_orders, daily_loss_limit, created_at, updated_at)
		 VALUES ('bot', $1, $2, '1000', 20, NULL, now(), now())`, userID, botID); err != nil {
		t.Fatalf("insert bot override: %v", err)
	}

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}

	assertBotState(t, pool, botID, "running", "running")
	assertNoLastError(t, pool, botID)
}

func TestDailyLossBotOverrideTakesPrecedenceOverAccountLimit(t *testing.T) {
	pool := dailyLossTestPool(t)
	ctx := context.Background()
	userID, botID := dailyLossTestBot(t, pool, "-200")

	if _, err := pool.Exec(ctx,
		`INSERT INTO risk_limits (scope, user_id, bot_id, daily_loss_limit, created_at, updated_at)
		 VALUES ('user', $1, NULL, '1000', now(), now())`, userID); err != nil {
		t.Fatalf("insert account limit: %v", err)
	}
	if _, err := pool.Exec(ctx,
		`INSERT INTO risk_limits (scope, user_id, bot_id, daily_loss_limit, created_at, updated_at)
		 VALUES ('bot', $1, $2, '100', now(), now())`, userID, botID); err != nil {
		t.Fatalf("insert bot override: %v", err)
	}

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}

	assertBotState(t, pool, botID, "paused", "running")
	assertLastError(t, pool, botID, "Daily loss limit reached")
}

// This covers the per-bot default. Without an account-scope row there is no
// place to persist the account baseline, so the account-wide default is not
// exercised here; normal bot creation inserts that row.
func TestDailyLossUsesDefaultWhenNoRiskLimitRows(t *testing.T) {
	pool := dailyLossTestPool(t)
	ctx := context.Background()
	_, botID := dailyLossTestBot(t, pool, "-150")

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}

	assertBotState(t, pool, botID, "paused", "running")
}

func TestDailyLossFailsClosedWhenRiskLimitsCannotBeRead(t *testing.T) {
	pool := dailyLossTestPool(t)
	ctx := context.Background()
	_, botID := dailyLossTestBot(t, pool, "0")

	restoreRenamedRiskLimitsTable(t, pool)
	if _, err := pool.Exec(ctx, `ALTER TABLE risk_limits RENAME TO risk_limits_unavailable_test`); err != nil {
		t.Fatalf("rename risk_limits: %v", err)
	}
	t.Cleanup(func() {
		if _, err := pool.Exec(context.Background(), `ALTER TABLE IF EXISTS risk_limits_unavailable_test RENAME TO risk_limits`); err != nil {
			t.Errorf("restore risk_limits: %v", err)
		}
	})

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}

	assertBotState(t, pool, botID, "running", "running")
	assertLastError(t, pool, botID, "daily loss limit")
	assertConsecutiveFailures(t, pool, botID, 1)
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id = $1`, 0, botID)
}

func TestRolloverFailureDoesNotPersistZeroBaseline(t *testing.T) {
	pool := dailyLossTestPool(t)
	ctx := context.Background()
	userID, botID := dailyLossTestBot(t, pool, "0")

	if _, err := pool.Exec(ctx,
		`INSERT INTO risk_limits (scope, user_id, daily_loss_limit, day_start, day_start_realized_pnl, created_at, updated_at)
		 VALUES ('user', $1, '1000', now() - interval '1 day', '42', now(), now())`, userID); err != nil {
		t.Fatalf("insert account risk limit: %v", err)
	}
	if _, err := pool.Exec(ctx, `
		CREATE OR REPLACE FUNCTION test_block_risk_limit_rollover() RETURNS trigger AS $$
		BEGIN
			IF NEW.day_start IS DISTINCT FROM OLD.day_start
			   OR NEW.day_start_realized_pnl IS DISTINCT FROM OLD.day_start_realized_pnl THEN
				RAISE EXCEPTION 'injected account baseline persist failure';
			END IF;
			RETURN NEW;
		END;
		$$ LANGUAGE plpgsql;
		CREATE TRIGGER test_block_risk_limit_rollover_trigger
			BEFORE UPDATE ON risk_limits
			FOR EACH ROW EXECUTE FUNCTION test_block_risk_limit_rollover();
	`); err != nil {
		t.Fatalf("install rollover failure trigger: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DROP TRIGGER IF EXISTS test_block_risk_limit_rollover_trigger ON risk_limits`)
		_, _ = pool.Exec(context.Background(), `DROP FUNCTION IF EXISTS test_block_risk_limit_rollover()`)
	})

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}

	assertBotState(t, pool, botID, "running", "running")
	assertLastError(t, pool, botID, "account daily loss baseline")
	assertConsecutiveFailures(t, pool, botID, 1)
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id = $1`, 0, botID)

	var baseline string
	var dayStart time.Time
	if err := pool.QueryRow(ctx,
		`SELECT day_start_realized_pnl::text, day_start FROM risk_limits WHERE user_id=$1 AND scope='user' AND bot_id IS NULL`,
		userID,
	).Scan(&baseline, &dayStart); err != nil {
		t.Fatalf("read account baseline: %v", err)
	}
	if baseline != "42.0000000000000000" {
		t.Fatalf("account baseline = %q, want the previous value 42", baseline)
	}
	if dayStart.After(time.Now().UTC().Add(-time.Hour)) {
		t.Fatalf("day_start = %v, want the previous (pre-rollover) day", dayStart)
	}
}

func TestAccountBaselineRolloverIncludesArchivedRealizedPNL(t *testing.T) {
	pool := dailyLossTestPool(t)
	ctx := context.Background()
	userID, botID := dailyLossTestBot(t, pool, "0")

	if _, err := pool.Exec(ctx,
		`INSERT INTO risk_limits (scope, user_id, daily_loss_limit, day_start, day_start_realized_pnl, created_at, updated_at)
		 VALUES ('user', $1, '1000', now() - interval '1 day', '0', now(), now())`, userID); err != nil {
		t.Fatalf("insert account risk limit: %v", err)
	}
	if _, err := pool.Exec(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, realized_pnl, tick_interval_seconds, settings, deleted_at, created_at, updated_at)
		 VALUES ($1, 'Archived rollover bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'stopped', 'stopped',
		 '1000', '1000', '-25', 1, '{}'::jsonb, now(), now(), now())`, userID); err != nil {
		t.Fatalf("insert archived bot: %v", err)
	}

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}

	var baseline string
	if err := pool.QueryRow(ctx,
		`SELECT day_start_realized_pnl::text FROM risk_limits WHERE user_id=$1 AND scope='user' AND bot_id IS NULL`,
		userID,
	).Scan(&baseline); err != nil {
		t.Fatalf("read account baseline: %v", err)
	}
	if baseline != "-25.0000000000000000" {
		t.Fatalf("account baseline = %q, want the archived bot P&L -25", baseline)
	}
}

func TestDailyLossFailsClosedWhenBaselineCannotBePersisted(t *testing.T) {
	pool := dailyLossTestPool(t)
	ctx := context.Background()
	_, botID := dailyLossTestBot(t, pool, "0")

	yesterday := time.Now().UTC().AddDate(0, 0, -1).Format("2006-01-02")
	if _, err := pool.Exec(ctx,
		`UPDATE bots SET settings = jsonb_build_object('daily_loss_baseline', jsonb_build_object('day', $2::text, 'realized', '0')) WHERE id = $1`,
		botID, yesterday); err != nil {
		t.Fatalf("seed yesterday baseline: %v", err)
	}
	if _, err := pool.Exec(ctx, `
		CREATE OR REPLACE FUNCTION test_block_daily_loss_baseline() RETURNS trigger AS $$
		BEGIN
			IF (NEW.settings->'daily_loss_baseline')::jsonb IS DISTINCT FROM (OLD.settings->'daily_loss_baseline')::jsonb THEN
				RAISE EXCEPTION 'injected per-bot baseline persist failure';
			END IF;
			RETURN NEW;
		END;
		$$ LANGUAGE plpgsql;
		CREATE TRIGGER test_block_daily_loss_baseline_trigger
			BEFORE UPDATE ON bots
			FOR EACH ROW EXECUTE FUNCTION test_block_daily_loss_baseline();
	`); err != nil {
		t.Fatalf("install baseline failure trigger: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DROP TRIGGER IF EXISTS test_block_daily_loss_baseline_trigger ON bots`)
		_, _ = pool.Exec(context.Background(), `DROP FUNCTION IF EXISTS test_block_daily_loss_baseline()`)
	})

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}

	assertBotState(t, pool, botID, "running", "running")
	assertLastError(t, pool, botID, "daily loss baseline")
	assertConsecutiveFailures(t, pool, botID, 1)
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id = $1`, 0, botID)
}

func TestDailyLossFailsClosedWhenAccountBaselineCannotBeRead(t *testing.T) {
	pool := dailyLossTestPool(t)
	ctx := context.Background()
	_, botID := dailyLossTestBot(t, pool, "0")

	restoreRenamedRiskLimitBaselineColumn(t, pool)
	if _, err := pool.Exec(ctx,
		`ALTER TABLE risk_limits RENAME COLUMN day_start_realized_pnl TO day_start_realized_pnl_unavailable_test`); err != nil {
		t.Fatalf("rename day_start_realized_pnl: %v", err)
	}
	t.Cleanup(func() {
		if _, err := pool.Exec(context.Background(),
			`ALTER TABLE IF EXISTS risk_limits RENAME COLUMN day_start_realized_pnl_unavailable_test TO day_start_realized_pnl`); err != nil {
			t.Errorf("restore day_start_realized_pnl: %v", err)
		}
	})

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}

	assertBotState(t, pool, botID, "running", "running")
	assertLastError(t, pool, botID, "account daily loss limit and baseline")
	assertConsecutiveFailures(t, pool, botID, 1)
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id = $1`, 0, botID)
}
