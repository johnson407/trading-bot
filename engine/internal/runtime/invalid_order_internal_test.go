package runtime

import (
	"context"
	"fmt"
	"os"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/exchange"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

func invalidOrderTestPool(t *testing.T) *pgxpool.Pool {
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

func insertInvalidOrderBot(t *testing.T, ctx context.Context, pool *pgxpool.Pool, mode string) (int64, int64) {
	t.Helper()

	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Invalid order', $1, 'x', now(), now()) RETURNING id`,
		fmt.Sprintf("invalid-order-%d@example.test", time.Now().UnixNano()),
	).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID)
	})

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, base_inventory, quote_inventory, fee_rate, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Invalid order bot', 'binance', 'BTC/USDT', 'simple-grid', $2, 'running', 'running',
		 '1000', '10', '1000', '0', 1, '{}'::jsonb, now(), now())
		 RETURNING id`, userID, mode).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}

	return userID, botID
}

func TestOrderSkipsAggregatesReasons(t *testing.T) {
	skips := &orderSkips{}
	skips.add("non_positive")
	skips.add("non_positive")
	skips.add("price_precision")

	if skips.total != 3 {
		t.Fatalf("total = %d, want 3", skips.total)
	}
	if skips.reasons["non_positive"] != 2 {
		t.Fatalf("non_positive = %d, want 2", skips.reasons["non_positive"])
	}
	if skips.reasons["price_precision"] != 1 {
		t.Fatalf("price_precision = %d, want 1", skips.reasons["price_precision"])
	}
}

func TestPaperInvalidOrdersAggregateAndThrottle(t *testing.T) {
	pool := invalidOrderTestPool(t)
	ctx := context.Background()
	userID, botID := insertInvalidOrderBot(t, ctx, pool, "paper")

	now := time.Date(2026, 11, 28, 12, 0, 0, 0, time.UTC)
	trading := New(pool, nil, nil, WithNow(func() time.Time { return now }))
	bot := &Bot{
		ID: botID, UserID: userID, Mode: "paper",
		Base: decimal.MustParse("10"), Quote: decimal.MustParse("1000"), FeeRate: decimal.Zero(),
	}
	intents := []strategy.OrderIntent{
		{Side: strategy.SideBuy, Price: decimal.Zero(), Quantity: decimal.MustParse("1")},
		{Side: strategy.SideBuy, Price: decimal.MustParse("100"), Quantity: decimal.Zero()},
		{Side: strategy.SideBuy, Price: decimal.MustParse("-1"), Quantity: decimal.MustParse("1")},
	}

	if _, err := trading.placeOrders(ctx, bot, &gridState{}, intents); err != nil {
		t.Fatalf("place orders: %v", err)
	}

	assertCountQuery(t, ctx, pool, `SELECT COUNT(*) FROM risk_events WHERE bot_id=$1 AND type='risk.invalid_order'`, 1, botID)
	var skipped int
	if err := pool.QueryRow(ctx,
		`SELECT (context->>'orders_skipped')::int FROM risk_events WHERE bot_id=$1 AND type='risk.invalid_order'`,
		botID,
	).Scan(&skipped); err != nil {
		t.Fatalf("read orders_skipped: %v", err)
	}
	if skipped != 3 {
		t.Fatalf("orders_skipped = %d, want 3", skipped)
	}
	var nonPositive int
	if err := pool.QueryRow(ctx,
		`SELECT (context->'reasons'->>'non_positive')::int FROM risk_events WHERE bot_id=$1 AND type='risk.invalid_order'`,
		botID,
	).Scan(&nonPositive); err != nil {
		t.Fatalf("read non_positive reason count: %v", err)
	}
	if nonPositive != 3 {
		t.Fatalf("non_positive = %d, want 3", nonPositive)
	}

	if _, err := trading.placeOrders(ctx, bot, &gridState{}, intents); err != nil {
		t.Fatalf("place orders inside cooldown: %v", err)
	}
	assertCountQuery(t, ctx, pool, `SELECT COUNT(*) FROM risk_events WHERE bot_id=$1 AND type='risk.invalid_order'`, 1, botID)

	now = now.Add(5*time.Minute + time.Second)
	if _, err := trading.placeOrders(ctx, bot, &gridState{}, intents); err != nil {
		t.Fatalf("place orders after cooldown: %v", err)
	}
	assertCountQuery(t, ctx, pool, `SELECT COUNT(*) FROM risk_events WHERE bot_id=$1 AND type='risk.invalid_order'`, 2, botID)
}

func TestLiveInvalidOrdersAggregate(t *testing.T) {
	pool := invalidOrderTestPool(t)
	ctx := context.Background()
	userID, botID := insertInvalidOrderBot(t, ctx, pool, "live")

	trading := New(pool, nil, nil,
		WithLiveTrading(true),
		WithLiveClients(func(context.Context, *Bot) (exchange.Client, error) {
			return &deadlockFake{orders: map[string]exchange.Order{}}, nil
		}),
	)
	bot := &Bot{
		ID: botID, UserID: userID, Mode: "live", Exchange: "binance", Symbol: "BTC/USDT",
		Base: decimal.MustParse("10"), Quote: decimal.MustParse("1000"), FeeRate: decimal.Zero(),
	}
	intents := []strategy.OrderIntent{
		{Side: strategy.SideBuy, Price: decimal.Zero(), Quantity: decimal.MustParse("1")},
		{Side: strategy.SideBuy, Price: decimal.MustParse("100"), Quantity: decimal.Zero()},
	}

	if _, err := trading.placeLiveOrders(ctx, bot, &gridState{}, intents); err != nil {
		t.Fatalf("place live orders: %v", err)
	}

	assertCountQuery(t, ctx, pool, `SELECT COUNT(*) FROM risk_events WHERE bot_id=$1 AND type='risk.invalid_order'`, 1, botID)
	var skipped int
	if err := pool.QueryRow(ctx,
		`SELECT (context->>'orders_skipped')::int FROM risk_events WHERE bot_id=$1 AND type='risk.invalid_order'`,
		botID,
	).Scan(&skipped); err != nil {
		t.Fatalf("read orders_skipped: %v", err)
	}
	if skipped != 2 {
		t.Fatalf("orders_skipped = %d, want 2", skipped)
	}
}

func TestInvalidOrderEventWriteFailureReleasesCooldown(t *testing.T) {
	pool := invalidOrderTestPool(t)
	ctx := context.Background()
	userID, botID := insertInvalidOrderBot(t, ctx, pool, "paper")

	if _, err := pool.Exec(ctx, `
		CREATE OR REPLACE FUNCTION test_block_invalid_order_event() RETURNS trigger AS $$
		BEGIN
			IF NEW.type = 'risk.invalid_order' THEN
				RAISE EXCEPTION 'injected invalid-order event failure';
			END IF;
			RETURN NEW;
		END;
		$$ LANGUAGE plpgsql;
		CREATE TRIGGER test_block_invalid_order_event_trigger
			BEFORE INSERT ON risk_events
			FOR EACH ROW EXECUTE FUNCTION test_block_invalid_order_event();
	`); err != nil {
		t.Fatalf("install risk event failure trigger: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DROP TRIGGER IF EXISTS test_block_invalid_order_event_trigger ON risk_events`)
		_, _ = pool.Exec(context.Background(), `DROP FUNCTION IF EXISTS test_block_invalid_order_event()`)
	})

	now := time.Date(2026, 11, 28, 12, 0, 0, 0, time.UTC)
	trading := New(pool, nil, nil, WithNow(func() time.Time { return now }))
	bot := &Bot{
		ID: botID, UserID: userID, Mode: "paper",
		Base: decimal.MustParse("10"), Quote: decimal.MustParse("1000"), FeeRate: decimal.Zero(),
	}
	intents := []strategy.OrderIntent{
		{Side: strategy.SideBuy, Price: decimal.Zero(), Quantity: decimal.MustParse("1")},
	}

	if _, err := trading.placeOrders(ctx, bot, &gridState{}, intents); err != nil {
		t.Fatalf("place orders: %v", err)
	}
	assertCountQuery(t, ctx, pool, `SELECT COUNT(*) FROM risk_events WHERE bot_id=$1 AND type='risk.invalid_order'`, 0, botID)

	if _, err := pool.Exec(ctx, `DROP TRIGGER IF EXISTS test_block_invalid_order_event_trigger ON risk_events`); err != nil {
		t.Fatalf("drop risk event failure trigger: %v", err)
	}
	if _, err := trading.placeOrders(ctx, bot, &gridState{}, intents); err != nil {
		t.Fatalf("retry place orders: %v", err)
	}
	assertCountQuery(t, ctx, pool, `SELECT COUNT(*) FROM risk_events WHERE bot_id=$1 AND type='risk.invalid_order'`, 1, botID)
}
