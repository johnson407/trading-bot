package runtime_test

import (
	"context"
	"errors"
	"fmt"
	"os"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/protocol"
	"github.com/johnson407/trading-bot/engine/internal/runtime"
)

func reconcileTestPool(t *testing.T) (context.Context, *pgxpool.Pool) {
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

	return ctx, pool
}

func insertReconcileFixture(t *testing.T, ctx context.Context, pool *pgxpool.Pool, status, desired, mode string) (int64, int64) {
	t.Helper()

	suffix := time.Now().UnixNano()
	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Reconcile user', $1, 'x', now(), now()) RETURNING id`,
		fmt.Sprintf("reconcile-%d@example.test", suffix),
	).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}

	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID)
	})

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 reconcile_pending, capital_allocation, quote_inventory, tick_interval_seconds,
		 last_successful_tick_at, settings, created_at, updated_at)
		 VALUES ($1, 'Reconcile bot', 'binance', 'BTC/USDT', 'simple-grid', $2, $3, $4,
		 true, '1000', '1000', 1, now(), '{}'::jsonb, now(), now())
		 RETURNING id`,
		userID, mode, status, desired,
	).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}

	if _, err := pool.Exec(ctx,
		`INSERT INTO strategy_configs (bot_id, strategy_key, configuration, state, state_version, created_at, updated_at)
		 VALUES ($1, 'simple-grid', '{}'::jsonb, '{"version":1,"data":{"center_price":"100"}}'::jsonb, 1, now(), now())`,
		botID,
	); err != nil {
		t.Fatalf("insert strategy config: %v", err)
	}

	var gridID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO grids (bot_id, center_price, spacing_type, level_count, spacing_value, status, created_at, updated_at)
		 VALUES ($1, '100', 'arithmetic', 1, '10', 'active', now(), now()) RETURNING id`,
		botID,
	).Scan(&gridID); err != nil {
		t.Fatalf("insert grid: %v", err)
	}

	var levelID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO grid_levels (grid_id, sequence, price, side, state, created_at, updated_at)
		 VALUES ($1, 1, '90', 'buy', 'open', now(), now()) RETURNING id`,
		gridID,
	).Scan(&levelID); err != nil {
		t.Fatalf("insert grid level: %v", err)
	}

	if _, err := pool.Exec(ctx,
		`INSERT INTO orders (bot_id, grid_id, grid_level_id, client_order_id, mode, side, type,
		 price, quantity, filled_quantity, status, requested_at, opened_at, created_at, updated_at)
		 VALUES ($1, $2, $3, 'reconcile-order', $4, 'buy', 'limit',
		 '90', '0.1', 0, 'open', now(), now(), now(), now())`,
		botID, gridID, levelID, mode,
	); err != nil {
		t.Fatalf("insert order: %v", err)
	}

	return userID, botID
}

func TestReconcileRunningPaperCancelsOrdersResetsStateAndClearsFlags(t *testing.T) {
	ctx, pool := reconcileTestPool(t)
	_, botID := insertReconcileFixture(t, ctx, pool, "running", "running", "paper")

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("reconcile: %v", err)
	}

	assertBotState(t, pool, botID, "running", "running")
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 0, botID)

	var stateNull, reconcile, cancelRetry, strategyReset bool
	if err := pool.QueryRow(ctx,
		`SELECT sc.state IS NULL, b.reconcile_pending, b.cancel_retry_requested, b.strategy_reset_pending
		 FROM bots b JOIN strategy_configs sc ON sc.bot_id = b.id WHERE b.id = $1`,
		botID,
	).Scan(&stateNull, &reconcile, &cancelRetry, &strategyReset); err != nil {
		t.Fatalf("read reconcile state: %v", err)
	}
	if !stateNull || reconcile || cancelRetry || strategyReset {
		t.Fatalf("state_null=%v reconcile=%v cancel_retry=%v strategy_reset=%v, want true/false/false/false",
			stateNull, reconcile, cancelRetry, strategyReset)
	}

	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("second reconcile: %v", err)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 0, botID)
}

func TestReconcileCommandAppliesRunningPaperReconcile(t *testing.T) {
	ctx, pool := reconcileTestPool(t)
	userID, botID := insertReconcileFixture(t, ctx, pool, "running", "running", "paper")

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Process(ctx, command(protocol.CommandBotConfig, userID, botID, []byte(`{"strategy_reset":true,"reconcile":true}`))); err != nil {
		t.Fatalf("bot.config: %v", err)
	}

	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 0, botID)

	var stateNull, reconcile bool
	if err := pool.QueryRow(ctx,
		`SELECT sc.state IS NULL, b.reconcile_pending
		 FROM bots b JOIN strategy_configs sc ON sc.bot_id = b.id WHERE b.id = $1`,
		botID,
	).Scan(&stateNull, &reconcile); err != nil {
		t.Fatalf("read reconcile state: %v", err)
	}
	if !stateNull || reconcile {
		t.Fatalf("state_null=%v reconcile=%v, want true/false", stateNull, reconcile)
	}
}

func TestReconcileFailureKeepsFlagsAndBlocksTickPlacement(t *testing.T) {
	ctx, pool := reconcileTestPool(t)
	_, botID := insertReconcileFixture(t, ctx, pool, "running", "running", "paper")

	var before time.Time
	if err := pool.QueryRow(ctx, `SELECT last_successful_tick_at FROM bots WHERE id = $1`, botID).Scan(&before); err != nil {
		t.Fatalf("read last successful tick: %v", err)
	}

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil, runtime.WithOrderCanceler(
		func(context.Context, int64, int64, string) error {
			return errors.New("cancel failed")
		},
	))

	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("reconcile: %v", err)
	}

	var stateNull, reconcile, cancelRetry bool
	if err := pool.QueryRow(ctx,
		`SELECT sc.state IS NULL, b.reconcile_pending, b.cancel_retry_requested
		 FROM bots b JOIN strategy_configs sc ON sc.bot_id = b.id WHERE b.id = $1`,
		botID,
	).Scan(&stateNull, &reconcile, &cancelRetry); err != nil {
		t.Fatalf("read failed reconcile state: %v", err)
	}
	if stateNull || !reconcile || !cancelRetry {
		t.Fatalf("state_null=%v reconcile=%v cancel_retry=%v, want false/true/true", stateNull, reconcile, cancelRetry)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 1, botID)

	if err := trading.TickBot(ctx, botID); err == nil {
		t.Fatal("TickBot error = nil, want reconcile failure")
	}

	var after time.Time
	if err := pool.QueryRow(ctx, `SELECT last_successful_tick_at FROM bots WHERE id = $1`, botID).Scan(&after); err != nil {
		t.Fatalf("read last successful tick after tick: %v", err)
	}
	if !after.Equal(before) {
		t.Fatalf("last_successful_tick_at changed from %s to %s, want unchanged", before, after)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 1, botID)

	recovered := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := recovered.Reconcile(ctx); err != nil {
		t.Fatalf("recovery reconcile: %v", err)
	}

	if err := pool.QueryRow(ctx,
		`SELECT sc.state IS NULL, b.reconcile_pending, b.cancel_retry_requested
		 FROM bots b JOIN strategy_configs sc ON sc.bot_id = b.id WHERE b.id = $1`,
		botID,
	).Scan(&stateNull, &reconcile, &cancelRetry); err != nil {
		t.Fatalf("read recovered state: %v", err)
	}
	if !stateNull || reconcile || cancelRetry {
		t.Fatalf("state_null=%v reconcile=%v cancel_retry=%v, want true/false/false", stateNull, reconcile, cancelRetry)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 0, botID)
}

func TestStartCompletesPendingPaperReconcile(t *testing.T) {
	ctx, pool := reconcileTestPool(t)
	userID, botID := insertReconcileFixture(t, ctx, pool, "stopped", "running", "paper")

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Process(ctx, command(protocol.CommandBotStart, userID, botID, nil)); err != nil {
		t.Fatalf("start: %v", err)
	}

	assertBotState(t, pool, botID, "running", "running")
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 0, botID)

	var stateNull, reconcile bool
	if err := pool.QueryRow(ctx,
		`SELECT sc.state IS NULL, b.reconcile_pending
		 FROM bots b JOIN strategy_configs sc ON sc.bot_id = b.id WHERE b.id = $1`,
		botID,
	).Scan(&stateNull, &reconcile); err != nil {
		t.Fatalf("read started reconcile state: %v", err)
	}
	if !stateNull || reconcile {
		t.Fatalf("state_null=%v reconcile=%v, want true/false", stateNull, reconcile)
	}
}

func TestReconcileCommandDoesNotCancelWhileStopped(t *testing.T) {
	ctx, pool := reconcileTestPool(t)
	userID, botID := insertReconcileFixture(t, ctx, pool, "stopped", "running", "paper")

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Process(ctx, command(protocol.CommandBotConfig, userID, botID, []byte(`{"strategy_reset":true,"reconcile":true}`))); err != nil {
		t.Fatalf("bot.config while stopped: %v", err)
	}

	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 1, botID)

	var stateNull, reconcile bool
	if err := pool.QueryRow(ctx,
		`SELECT sc.state IS NULL, b.reconcile_pending
		 FROM bots b JOIN strategy_configs sc ON sc.bot_id = b.id WHERE b.id = $1`,
		botID,
	).Scan(&stateNull, &reconcile); err != nil {
		t.Fatalf("read stopped reconcile state: %v", err)
	}
	if stateNull || !reconcile {
		t.Fatalf("state_null=%v reconcile=%v, want false/true", stateNull, reconcile)
	}

	if err := trading.Process(ctx, command(protocol.CommandBotStart, userID, botID, nil)); err != nil {
		t.Fatalf("start after stopped command: %v", err)
	}

	assertBotState(t, pool, botID, "running", "running")
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 0, botID)

	if err := pool.QueryRow(ctx,
		`SELECT sc.state IS NULL, b.reconcile_pending
		 FROM bots b JOIN strategy_configs sc ON sc.bot_id = b.id WHERE b.id = $1`,
		botID,
	).Scan(&stateNull, &reconcile); err != nil {
		t.Fatalf("read started state: %v", err)
	}
	if !stateNull || reconcile {
		t.Fatalf("state_null=%v reconcile=%v, want true/false", stateNull, reconcile)
	}
}

func TestLateReconcileCommandAfterStartDoesNotCancelNewLadder(t *testing.T) {
	ctx, pool := reconcileTestPool(t)
	userID, botID := insertReconcileFixture(t, ctx, pool, "stopped", "running", "paper")

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Process(ctx, command(protocol.CommandBotStart, userID, botID, nil)); err != nil {
		t.Fatalf("start: %v", err)
	}

	if _, err := pool.Exec(ctx,
		`INSERT INTO orders (bot_id, client_order_id, mode, side, type,
		 price, quantity, filled_quantity, status, requested_at, opened_at, created_at, updated_at)
		 VALUES ($1, 'new-ladder', 'paper', 'buy', 'limit',
		 '100', '0.1', 0, 'open', now(), now(), now(), now())`,
		botID,
	); err != nil {
		t.Fatalf("insert new ladder order: %v", err)
	}

	if err := trading.Process(ctx, command(protocol.CommandBotConfig, userID, botID, []byte(`{"strategy_reset":true,"reconcile":true}`))); err != nil {
		t.Fatalf("late bot.config: %v", err)
	}

	assertBotState(t, pool, botID, "running", "running")
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 1, botID)

	var reconcile, cancelRetry bool
	if err := pool.QueryRow(ctx, `SELECT reconcile_pending, cancel_retry_requested FROM bots WHERE id = $1`, botID).Scan(&reconcile, &cancelRetry); err != nil {
		t.Fatalf("read late command flags: %v", err)
	}
	if reconcile || cancelRetry {
		t.Fatalf("reconcile=%v cancel_retry=%v, want false/false", reconcile, cancelRetry)
	}
}

func TestReconcileIgnoresLiveBot(t *testing.T) {
	ctx, pool := reconcileTestPool(t)
	userID, botID := insertReconcileFixture(t, ctx, pool, "running", "running", "live")

	called := false
	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil, runtime.WithOrderCanceler(
		func(context.Context, int64, int64, string) error {
			called = true
			return nil
		},
	))

	if err := trading.Process(ctx, command(protocol.CommandBotConfig, userID, botID, []byte(`{"strategy_reset":true,"reconcile":true}`))); err != nil {
		t.Fatalf("bot.config live: %v", err)
	}
	if called {
		t.Fatal("live reconcile called the order canceler")
	}

	var stateNull, reconcile bool
	if err := pool.QueryRow(ctx,
		`SELECT sc.state IS NULL, b.reconcile_pending
		 FROM bots b JOIN strategy_configs sc ON sc.bot_id = b.id WHERE b.id = $1`,
		botID,
	).Scan(&stateNull, &reconcile); err != nil {
		t.Fatalf("read live reconcile state: %v", err)
	}
	if stateNull || reconcile {
		t.Fatalf("state_null=%v reconcile=%v, want false/false", stateNull, reconcile)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 1, botID)
}

func TestNonReconcileConfigResetsStateWithoutCancellingOrders(t *testing.T) {
	ctx, pool := reconcileTestPool(t)
	userID, botID := insertReconcileFixture(t, ctx, pool, "running", "running", "paper")

	if _, err := pool.Exec(ctx, `UPDATE bots SET reconcile_pending = false WHERE id = $1`, botID); err != nil {
		t.Fatalf("clear reconcile flag: %v", err)
	}

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Process(ctx, command(protocol.CommandBotConfig, userID, botID, nil)); err != nil {
		t.Fatalf("bot.config: %v", err)
	}

	var stateNull, reconcile bool
	if err := pool.QueryRow(ctx,
		`SELECT sc.state IS NULL, b.reconcile_pending
		 FROM bots b JOIN strategy_configs sc ON sc.bot_id = b.id WHERE b.id = $1`,
		botID,
	).Scan(&stateNull, &reconcile); err != nil {
		t.Fatalf("read non-reconcile state: %v", err)
	}
	if !stateNull || reconcile {
		t.Fatalf("state_null=%v reconcile=%v, want true/false", stateNull, reconcile)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`, 1, botID)
}

func TestReconcileAppliesPendingStrategyReset(t *testing.T) {
	ctx, pool := reconcileTestPool(t)
	_, botID := insertReconcileFixture(t, ctx, pool, "idle", "idle", "paper")

	if _, err := pool.Exec(ctx, `UPDATE bots SET reconcile_pending = false, strategy_reset_pending = true WHERE id = $1`, botID); err != nil {
		t.Fatalf("set strategy reset flag: %v", err)
	}

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("reconcile: %v", err)
	}

	var stateNull, strategyReset bool
	if err := pool.QueryRow(ctx,
		`SELECT sc.state IS NULL, b.strategy_reset_pending
		 FROM bots b JOIN strategy_configs sc ON sc.bot_id = b.id WHERE b.id = $1`,
		botID,
	).Scan(&stateNull, &strategyReset); err != nil {
		t.Fatalf("read strategy reset state: %v", err)
	}
	if !stateNull || strategyReset {
		t.Fatalf("state_null=%v strategy_reset=%v, want true/false", stateNull, strategyReset)
	}
}
