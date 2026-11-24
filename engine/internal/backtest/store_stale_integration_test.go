package backtest

import (
	"context"
	"fmt"
	"os"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

// TestFailStaleRunsIntegration verifies the stale SQL and the active-sweep
// exclusion. It is opt-in for the same reason as TestStoreExecuteRunIntegration.
func TestFailStaleRunsIntegration(t *testing.T) {
	dsn := os.Getenv("BACKTEST_TEST_DSN")
	if dsn == "" {
		t.Skip("set BACKTEST_TEST_DSN to run the PostgreSQL store test")
	}

	ctx := context.Background()
	pool, err := pgxpool.New(ctx, dsn)
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	defer pool.Close()

	var userID int64
	email := fmt.Sprintf("backtest-stale-%d@example.test", time.Now().UnixNano())
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Backtest stale integration', $1, 'x', now(), now())
		 RETURNING id`,
		email,
	).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	defer func() {
		_, _ = pool.Exec(ctx, `DELETE FROM users WHERE id = $1`, userID)
	}()

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, symbol, strategy_key, created_at, updated_at)
		 VALUES ($1, 'Backtest stale bot', 'IT/USDT', 'simple-grid', now(), now())
		 RETURNING id`,
		userID,
	).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}
	defer func() {
		_, _ = pool.Exec(ctx, `DELETE FROM bots WHERE id = $1`, botID)
	}()

	var sweepID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO backtest_sweeps
		 (user_id, bot_id, name, symbol, timeframe, start_at, end_at, parameters, total_runs, created_at, updated_at)
		 VALUES ($1, $2, 'Stale sweep', 'IT/USDT', '15m', $3, $4, '{}'::jsonb, 2, now(), now())
		 RETURNING id`,
		userID, botID, time.Now().Add(-2*time.Hour), time.Now(),
	).Scan(&sweepID); err != nil {
		t.Fatalf("insert sweep: %v", err)
	}
	defer func() {
		_, _ = pool.Exec(ctx, `DELETE FROM backtest_sweeps WHERE id = $1`, sweepID)
	}()

	insertRun := func(sweep *int64, status string, age time.Duration) int64 {
		t.Helper()
		var runID int64
		if err := pool.QueryRow(ctx,
			`INSERT INTO backtest_runs
			 (user_id, bot_id, sweep_id, name, exchange, symbol, strategy_key, timeframe,
			  start_at, end_at, capital_allocation, fee_rate, config_snapshot, status,
			  progress, created_at, updated_at)
			 VALUES ($1, $2, $3, 'Stale run', 'itest', 'IT/USDT', 'simple-grid', '15m',
			         $4, $5, 1000, 0.001, '{}'::jsonb, $6, 0, now() - ($7 * interval '1 second'), now() - ($7 * interval '1 second'))
			 RETURNING id`,
			userID, botID, sweep, time.Now().Add(-2*time.Hour), time.Now(), status, age.Seconds(),
		).Scan(&runID); err != nil {
			t.Fatalf("insert run: %v", err)
		}
		t.Cleanup(func() {
			_, _ = pool.Exec(ctx, `DELETE FROM backtest_runs WHERE id = $1`, runID)
		})
		return runID
	}

	sweepRunOne := insertRun(&sweepID, StatusPending, time.Hour)
	sweepRunTwo := insertRun(&sweepID, StatusPending, time.Hour)
	outsideRun := insertRun(nil, StatusRunning, time.Hour)
	orphanRun := insertRun(nil, StatusPending, 25*time.Hour)

	store := NewStore(pool, NewEngine(), 0, nil)

	count, err := store.FailStaleRuns(ctx, time.Now().Add(-5*time.Minute), StaleRunMessage)
	if err != nil {
		t.Fatalf("FailStaleRuns: %v", err)
	}
	if count != 2 {
		t.Fatalf("failed %d runs, want 2", count)
	}

	assertStatus := func(runID int64, want string) {
		t.Helper()
		var status string
		if err := pool.QueryRow(ctx, `SELECT status FROM backtest_runs WHERE id = $1`, runID).Scan(&status); err != nil {
			t.Fatalf("read run %d: %v", runID, err)
		}
		if status != want {
			t.Fatalf("run %d status = %q, want %q", runID, status, want)
		}
	}

	assertStatus(outsideRun, StatusFailed)
	assertStatus(orphanRun, StatusFailed)
	assertStatus(sweepRunOne, StatusPending)
	assertStatus(sweepRunTwo, StatusPending)

	count, err = store.FailStaleRuns(ctx, time.Now().Add(-5*time.Minute), StaleRunMessage)
	if err != nil {
		t.Fatalf("FailStaleRuns second pass: %v", err)
	}
	if count != 0 {
		t.Fatalf("failed %d additional runs, want 0", count)
	}
	assertStatus(sweepRunOne, StatusPending)
	assertStatus(sweepRunTwo, StatusPending)
}
