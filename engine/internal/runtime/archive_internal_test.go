package runtime

import (
	"context"
	"os"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/exchange"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

// TestArchivedBotIgnoresInFlightWrites covers tick writes that do not pass
// through the control or reconciliation paths. An archive can commit while a
// tick is already running, so these writes must be scoped to active bots.
func TestArchivedBotIgnoresInFlightWrites(t *testing.T) {
	dsn := os.Getenv("RUNTIME_TEST_DSN")
	if dsn == "" {
		t.Skip("set RUNTIME_TEST_DSN to run the PostgreSQL runtime test")
	}

	ctx := context.Background()
	pool, err := pgxpool.New(ctx, dsn)
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	defer pool.Close()

	email := "runtime-archive-" + time.Now().UTC().Format("20060102150405.000000000") + "@example.test"
	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Runtime archive', $1, 'x', now(), now())
		 RETURNING id`, email).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID)
	})

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, fee_rate, tick_interval_seconds, settings, deleted_at, created_at, updated_at)
		 VALUES ($1, 'Archived runtime bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'stopped', 'stopped',
		 '1000', '1000', '0', 1, '{"mark_price":"100"}'::jsonb, now(), now(), now())
		 RETURNING id`, userID).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}

	rt := New(pool, nil, nil)
	bot := &Bot{
		ID:      botID,
		UserID:  userID,
		Quote:   decimal.MustParse("1000"),
		FeeRate: decimal.Zero(),
		Settings: map[string]any{
			"mark_price": "100",
		},
	}

	if updated := rt.rememberPrice(ctx, bot, decimal.MustParse("123")); updated != nil {
		t.Fatal("rememberPrice updated an archived bot")
	}
	var settingsUnchanged bool
	if err := pool.QueryRow(ctx,
		`SELECT settings::jsonb = '{"mark_price":"100"}'::jsonb FROM bots WHERE id=$1`, botID,
	).Scan(&settingsUnchanged); err != nil {
		t.Fatalf("read settings: %v", err)
	}
	if !settingsUnchanged {
		t.Fatal("rememberPrice changed an archived bot's settings")
	}

	if err := rt.tickFailure(ctx, bot, context.Canceled); err != nil {
		t.Fatalf("tickFailure: %v", err)
	}
	var failures int
	var lastError *string
	if err := pool.QueryRow(ctx,
		`SELECT consecutive_failures, last_error FROM bots WHERE id=$1`, botID,
	).Scan(&failures, &lastError); err != nil {
		t.Fatalf("read failure state: %v", err)
	}
	if failures != 0 || lastError != nil {
		t.Fatalf("failure state changed: failures=%d last_error=%v", failures, lastError)
	}

	if _, err := pool.Exec(ctx,
		`INSERT INTO strategy_configs (bot_id, strategy_key, configuration, state, state_version, created_at, updated_at)
		 VALUES ($1, 'simple-grid', '{}'::jsonb, '{"keep":true}'::jsonb, 1, now(), now())`,
		botID,
	); err != nil {
		t.Fatalf("insert strategy config: %v", err)
	}
	if err := rt.persistStrategy(ctx, botID, strategy.State{Version: 2, Data: map[string]any{"replace": true}}, nil); err != nil {
		t.Fatalf("persistStrategy: %v", err)
	}
	var stateUnchanged bool
	if err := pool.QueryRow(ctx,
		`SELECT state::jsonb = '{"keep":true}'::jsonb FROM strategy_configs WHERE bot_id=$1`, botID,
	).Scan(&stateUnchanged); err != nil {
		t.Fatalf("read strategy state: %v", err)
	}
	if !stateUnchanged {
		t.Fatal("persistStrategy changed an archived bot's state")
	}

	var orderID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO orders (bot_id, client_order_id, mode, side, type, price, quantity, filled_quantity, status, requested_at, opened_at, created_at, updated_at)
		 VALUES ($1, 'archive-race-buy', 'paper', 'buy', 'limit', '100', '1', '0', 'open', now() - interval '1 minute', now() - interval '1 minute', now(), now())
		 RETURNING id`, botID).Scan(&orderID); err != nil {
		t.Fatalf("insert order: %v", err)
	}

	fills, err := rt.processPaperFills(ctx, bot, &gridState{ID: 1}, time.Now().Add(time.Minute))
	if err != nil {
		t.Fatalf("processPaperFills: %v", err)
	}
	if len(fills) != 0 {
		t.Fatalf("archived bot produced %d fills", len(fills))
	}

	remote := exchange.Order{
		ExchangeOrderID: "archive-race-fill",
		ClientOrderID:   "archive-race-buy",
		Status:          exchange.StatusFilled,
		Side:            exchange.SideBuy,
		Price:           decimal.MustParse("100"),
		Quantity:        decimal.MustParse("1"),
		FilledQuantity:  decimal.MustParse("1"),
		AveragePrice:    decimal.MustParse("100"),
	}
	if err := rt.applyLiveOrder(ctx, bot, orderID, decimal.Zero(), remote); err != nil {
		t.Fatalf("applyLiveOrder: %v", err)
	}

	var status string
	var quoteUnchanged bool
	if err := pool.QueryRow(ctx,
		`SELECT o.status, b.quote_inventory = 1000
		 FROM orders o JOIN bots b ON b.id = o.bot_id
		 WHERE o.id = $1`, orderID,
	).Scan(&status, &quoteUnchanged); err != nil {
		t.Fatalf("read fill state: %v", err)
	}
	if status != "open" || !quoteUnchanged {
		t.Fatalf("archived fill changed state: status=%s quote_unchanged=%v", status, quoteUnchanged)
	}
}
