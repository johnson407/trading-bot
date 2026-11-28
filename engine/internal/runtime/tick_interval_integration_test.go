package runtime_test

import (
	"context"
	"fmt"
	"os"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/runtime"
	"github.com/johnson407/trading-bot/engine/internal/tick"
)

func tickIntervalPool(t *testing.T) (context.Context, *pgxpool.Pool) {
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

func insertTickIntervalBot(t *testing.T, ctx context.Context, pool *pgxpool.Pool, interval int, lastSuccessful time.Time) (int64, int64) {
	t.Helper()

	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Tick interval test', $1, 'x', now(), now()) RETURNING id`,
		fmt.Sprintf("tick-interval-%d@example.test", time.Now().UnixNano()),
	).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID)
	})

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, fee_rate, tick_interval_seconds,
		 last_tick_at, last_successful_tick_at, settings, created_at, updated_at)
		 VALUES ($1, 'Tick interval bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'running', 'running',
		 '1000', '1000', '0', $2, $3, $3, '{}', now(), now())
		 RETURNING id`, userID, interval, lastSuccessful).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}

	return userID, botID
}

func TestSourceUsesTheStoredTickIntervalWithoutARestart(t *testing.T) {
	ctx, pool := tickIntervalPool(t)
	now := time.Now().UTC()
	_, botID := insertTickIntervalBot(t, ctx, pool, 60, now.Add(-15*time.Second))

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)

	source, err := trading.Source(ctx)
	if err != nil {
		t.Fatalf("source: %v", err)
	}
	var selected *tick.Bot
	for i := range source {
		if source[i].ID == botID {
			selected = &source[i]

			break
		}
	}
	if selected == nil || selected.TickIntervalSeconds != 60 {
		t.Fatalf("source = %+v, want bot %d at 60s", source, botID)
	}
	if tick.IsDue(selected.LastTickAt, now, selected.TickIntervalSeconds) {
		t.Fatal("bot was due at 60s after only 15s")
	}

	if _, err := pool.Exec(ctx, `UPDATE bots SET tick_interval_seconds = 10 WHERE id = $1`, botID); err != nil {
		t.Fatalf("update interval: %v", err)
	}

	source, err = trading.Source(ctx)
	if err != nil {
		t.Fatalf("source after edit: %v", err)
	}
	selected = nil
	for i := range source {
		if source[i].ID == botID {
			selected = &source[i]

			break
		}
	}
	if selected == nil || selected.TickIntervalSeconds != 10 {
		t.Fatalf("source after edit = %+v, want bot %d at 10s", source, botID)
	}
	if !tick.IsDue(selected.LastTickAt, now, selected.TickIntervalSeconds) {
		t.Fatal("bot was not due at 10s after 15s; the stored interval was not re-read")
	}
}

func TestLoweringTheIntervalCanTripTheStaleCheck(t *testing.T) {
	ctx, pool := tickIntervalPool(t)
	clock := time.Now().UTC()
	userID, botID := insertTickIntervalBot(t, ctx, pool, 3600, clock.Add(-300*time.Second))

	var orderID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO orders (bot_id, client_order_id, mode, side, type, price, quantity,
		 filled_quantity, status, requested_at, opened_at, created_at, updated_at)
		 VALUES ($1, 'tick-interval-stale', 'paper', 'buy', 'limit', '100', '0.1',
		 '0', 'open', now(), now(), now(), now()) RETURNING id`, botID,
	).Scan(&orderID); err != nil {
		t.Fatalf("insert order: %v", err)
	}

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil, runtime.WithNow(func() time.Time { return clock }))

	// First pass records the restart grace and sees 3600s as fresh enough.
	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("first reconcile: %v", err)
	}

	var status string
	if err := pool.QueryRow(ctx, `SELECT status FROM bots WHERE id = $1`, botID).Scan(&status); err != nil {
		t.Fatalf("read status: %v", err)
	}
	if status != "running" {
		t.Fatalf("status after first reconcile = %q, want running", status)
	}

	if _, err := pool.Exec(ctx, `UPDATE bots SET tick_interval_seconds = 10 WHERE id = $1`, botID); err != nil {
		t.Fatalf("update interval: %v", err)
	}
	clock = clock.Add(200 * time.Second)

	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("second reconcile: %v", err)
	}

	var pauseReason *string
	var reconcilePending, strategyResetPending bool
	if err := pool.QueryRow(ctx,
		`SELECT status, pause_reason, reconcile_pending, strategy_reset_pending FROM bots WHERE id = $1`, botID,
	).Scan(&status, &pauseReason, &reconcilePending, &strategyResetPending); err != nil {
		t.Fatalf("read bot: %v", err)
	}
	if status != "paused" || pauseReason == nil || *pauseReason != "stale_tick" {
		t.Fatalf("status=%q pause_reason=%v, want paused/stale_tick", status, pauseReason)
	}
	if reconcilePending || strategyResetPending {
		t.Fatalf("reconcile_pending=%v strategy_reset_pending=%v, want false/false", reconcilePending, strategyResetPending)
	}

	var orderStatus string
	if err := pool.QueryRow(ctx, `SELECT status FROM orders WHERE id = $1`, orderID).Scan(&orderStatus); err != nil {
		t.Fatalf("read order: %v", err)
	}
	if orderStatus != "canceled" {
		t.Fatalf("order status = %q, want canceled", orderStatus)
	}

	var activityCount int
	if err := pool.QueryRow(ctx,
		`SELECT count(*) FROM activity_logs WHERE user_id = $1 AND bot_id = $2 AND type = 'bot.stale'`, userID, botID,
	).Scan(&activityCount); err != nil {
		t.Fatalf("count activity: %v", err)
	}
	if activityCount != 1 {
		t.Fatalf("bot.stale activity count = %d, want 1", activityCount)
	}
}
