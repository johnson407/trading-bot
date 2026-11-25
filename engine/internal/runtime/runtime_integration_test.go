package runtime_test

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/protocol"
	"github.com/johnson407/trading-bot/engine/internal/runtime"
)

type fixedMarket struct {
	price string
}

func (m *fixedMarket) Last(context.Context, string, string) (decimal.Decimal, error) {
	return decimal.MustParse(m.price), nil
}

// TestRuntimeControlAndTickIntegration exercises the PostgreSQL-backed control
// handlers, due scheduler tick, grid persistence, paper fills, cancel, and
// kill switch. It is opt-in because the default Go suite has no database:
//
//	RUNTIME_TEST_DSN='postgres://user:pass@host:5432/trading_bot_test?sslmode=disable' \
//	  go test ./internal/runtime -run TestRuntimeControlAndTickIntegration
func TestRuntimeControlAndTickIntegration(t *testing.T) {
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

	email := fmt.Sprintf("runtime-integration-%d@example.test", time.Now().UnixNano())
	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Runtime integration', $1, 'x', now(), now())
		 RETURNING id`, email).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, fee_rate, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Runtime bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'idle', 'running',
		 '1000', '1000', '0', 1, '{}', now(), now())
		 RETURNING id`, userID).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID)
	})

	if _, err := pool.Exec(ctx,
		`INSERT INTO strategy_configs (bot_id, strategy_key, configuration, state_version, created_at, updated_at)
		 VALUES ($1, 'simple-grid', $2::jsonb, 1, now(), now())`,
		botID,
		`{"center_price":"100","levels_per_side":2,"spacing_type":"arithmetic","spacing_value":"10","order_quantity":"0.1"}`,
	); err != nil {
		t.Fatalf("insert strategy config: %v", err)
	}

	market := &fixedMarket{price: "100"}
	trading := runtime.New(pool, market, nil)

	if err := trading.Process(ctx, command(protocol.CommandBotStart, userID, botID, nil)); err != nil {
		t.Fatalf("start: %v", err)
	}
	assertBotState(t, pool, botID, "running", "running")

	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("first tick: %v", err)
	}
	var tickError *string
	if err := pool.QueryRow(ctx, `SELECT last_error FROM bots WHERE id=$1`, botID).Scan(&tickError); err != nil {
		t.Fatalf("read tick error: %v", err)
	}
	if tickError != nil {
		t.Fatalf("tick error: %s", *tickError)
	}
	assertCount(t, pool, `SELECT COUNT(*) FROM grids WHERE bot_id=$1 AND status='active'`, 1, botID)
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id=$1 AND status IN ('pending','open','partially_filled')`, 2, botID)
	assertCount(t, pool, `SELECT COUNT(*) FROM activity_logs WHERE bot_id=$1`, 0, botID)

	market.price = "80"
	// PostgreSQL timestamps have second precision; wait so the first tick's
	// orders are strictly before the next tick, matching the paper-fill rule.
	time.Sleep(1100 * time.Millisecond)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("fill tick: %v", err)
	}
	var fillCount int
	if err := pool.QueryRow(ctx, `SELECT COUNT(*) FROM fills WHERE bot_id=$1`, botID).Scan(&fillCount); err != nil {
		t.Fatalf("count fills: %v", err)
	}
	if fillCount != 2 {
		rows, _ := pool.Query(ctx, `SELECT side, price::text, status, opened_at FROM orders WHERE bot_id=$1 ORDER BY id`, botID)
		var detail []string
		for rows.Next() {
			var side, price, status string
			var opened time.Time
			_ = rows.Scan(&side, &price, &status, &opened)
			detail = append(detail, fmt.Sprintf("%s %s %s %s", side, price, status, opened.Format(time.RFC3339Nano)))
		}
		rows.Close()
		var settings []byte
		_ = pool.QueryRow(ctx, `SELECT settings FROM bots WHERE id=$1`, botID).Scan(&settings)
		t.Fatalf("fills = %d; orders=%v settings=%s", fillCount, detail, settings)
	}

	var orderID int64
	if err := pool.QueryRow(ctx,
		`SELECT id FROM orders WHERE bot_id=$1 AND status='open' ORDER BY id LIMIT 1`, botID).Scan(&orderID); err != nil {
		t.Fatalf("select open order: %v", err)
	}

	payload := []byte(fmt.Sprintf(`{"order_id":%d}`, orderID))
	if err := trading.Process(ctx, command(protocol.CommandOrderCancel, userID, botID, payload)); err != nil {
		t.Fatalf("cancel order: %v", err)
	}
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE id=$1 AND status='canceled'`, 1, orderID)

	if _, err := pool.Exec(ctx, `UPDATE bots SET desired_status='stopped' WHERE id=$1`, botID); err != nil {
		t.Fatalf("set desired stopped: %v", err)
	}
	if err := trading.Process(ctx, command(protocol.CommandBotStop, userID, botID, nil)); err != nil {
		t.Fatalf("stop: %v", err)
	}
	assertBotState(t, pool, botID, "stopped", "stopped")
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id=$1 AND status IN ('pending','open','partially_filled')`, 0, botID)

	if _, err := pool.Exec(ctx, `UPDATE bots SET desired_status='running' WHERE id=$1`, botID); err != nil {
		t.Fatalf("set desired running: %v", err)
	}
	if err := trading.Process(ctx, command(protocol.CommandBotStart, userID, botID, nil)); err != nil {
		t.Fatalf("restart: %v", err)
	}
	assertBotState(t, pool, botID, "running", "running")

	var secondBot int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Killed bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'running', 'running',
		 '1000', '1000', 1, '{}', now(), now())
		 RETURNING id`, userID).Scan(&secondBot); err != nil {
		t.Fatalf("insert second bot: %v", err)
	}

	if _, err := pool.Exec(ctx, `UPDATE bots SET desired_status='paused' WHERE user_id=$1 AND status <> 'stopped'`, userID); err != nil {
		t.Fatalf("set desired paused: %v", err)
	}
	if err := trading.Process(ctx, command(protocol.CommandKillSwitch, userID, 0, nil)); err != nil {
		t.Fatalf("kill switch: %v", err)
	}
	assertBotState(t, pool, secondBot, "paused", "paused")
	assertBotState(t, pool, botID, "paused", "paused")

	var liveBot int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Live bot', 'binance', 'BTC/USDT', 'simple-grid', 'live', 'idle', 'running',
		 '1000', '1000', 1, '{}', now(), now())
		 RETURNING id`, userID).Scan(&liveBot); err != nil {
		t.Fatalf("insert live bot: %v", err)
	}
	if err := trading.Process(ctx, command(protocol.CommandBotStart, userID, liveBot, nil)); err != nil {
		t.Fatalf("start live bot: %v", err)
	}
	assertBotState(t, pool, liveBot, "paused", "running")

	var lossBot int64
	settings := fmt.Sprintf(`{"daily_loss_baseline":{"day":%q,"realized":"0"}}`, time.Now().UTC().Format("2006-01-02"))
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, realized_pnl, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Loss bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'running', 'running',
		 '1000', '1000', '-200', 1, $2::jsonb, now(), now())
		 RETURNING id`, userID, settings).Scan(&lossBot); err != nil {
		t.Fatalf("insert loss bot: %v", err)
	}
	if err := trading.TickBot(ctx, lossBot); err != nil {
		t.Fatalf("tick loss bot: %v", err)
	}
	assertBotState(t, pool, lossBot, "paused", "running")
}

func command(typ string, userID, botID int64, payload []byte) protocol.Envelope {
	return protocol.Envelope{
		V:       protocol.Version,
		ID:      fmt.Sprintf("runtime-test-%d", time.Now().UnixNano()),
		Type:    typ,
		TS:      time.Now().UTC().Format(time.RFC3339),
		UserID:  userID,
		BotID:   botID,
		Payload: payload,
	}
}

func assertBotState(t *testing.T, pool *pgxpool.Pool, botID int64, status, desired string) {
	t.Helper()
	var gotStatus, gotDesired string
	if err := pool.QueryRow(context.Background(),
		`SELECT status, desired_status FROM bots WHERE id=$1`, botID).Scan(&gotStatus, &gotDesired); err != nil {
		t.Fatalf("read bot state: %v", err)
	}
	if gotStatus != status || gotDesired != desired {
		t.Fatalf("bot state = %s/%s, want %s/%s", gotStatus, gotDesired, status, desired)
	}
}

func assertCount(t *testing.T, pool *pgxpool.Pool, query string, want int, args ...any) {
	t.Helper()
	var count int
	if err := pool.QueryRow(context.Background(), query, args...).Scan(&count); err != nil {
		t.Fatalf("count: %v", err)
	}
	if count != want {
		t.Fatalf("count = %d, want %d (%s)", count, want, query)
	}
}

func TestReconcilerCancelsPaperOrdersForLostKillSwitch(t *testing.T) {
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
	suffix := time.Now().UnixNano()
	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Lost kill switch', $1, 'x', now(), now()) RETURNING id`, fmt.Sprintf("lost-kill-%d@example.test", suffix)).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status, kill_switch_requested_at,
		 capital_allocation, quote_inventory, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Lost kill bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'running', 'paused', now(),
		 '1000', '1000', 1, '{}'::jsonb, now(), now())
		 RETURNING id`, userID).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}
	t.Cleanup(func() { _, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID) })
	if _, err := pool.Exec(ctx,
		`INSERT INTO orders (bot_id, client_order_id, mode, side, type, price, quantity, filled_quantity, status, requested_at, opened_at, created_at, updated_at)
		 VALUES ($1, 'lost-order', 'paper', 'buy', 'limit', '90', '0.1', 0, 'open', now(), now(), now(), now())`, botID); err != nil {
		t.Fatalf("insert order: %v", err)
	}

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	assertBotState(t, pool, botID, "paused", "paused")
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id=$1 AND status='open'`, 0, botID)
	var marker bool
	if err := pool.QueryRow(ctx, `SELECT kill_switch_requested_at IS NOT NULL FROM bots WHERE id=$1`, botID).Scan(&marker); err != nil {
		t.Fatalf("read marker: %v", err)
	}
	if marker {
		t.Fatal("kill-switch marker was not cleared")
	}
}

func TestStartAppliesPendingStrategyReset(t *testing.T) {
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
	suffix := time.Now().UnixNano()
	var userID, botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Reset bot', $1, 'x', now(), now()) RETURNING id`, fmt.Sprintf("reset-%d@example.test", suffix)).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status, strategy_reset_pending,
		 capital_allocation, quote_inventory, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Reset bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'idle', 'running', true,
		 '1000', '1000', 1, '{}'::jsonb, now(), now()) RETURNING id`, userID).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}
	t.Cleanup(func() { _, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID) })
	if _, err := pool.Exec(ctx,
		`INSERT INTO strategy_configs (bot_id, strategy_key, configuration, state, state_version, created_at, updated_at)
		 VALUES ($1, 'simple-grid', '{}'::jsonb, '{"version":1,"data":{"center_price":"100"}}'::jsonb, 1, now(), now())`, botID); err != nil {
		t.Fatalf("insert strategy config: %v", err)
	}

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Process(ctx, command(protocol.CommandBotStart, userID, botID, nil)); err != nil {
		t.Fatalf("start: %v", err)
	}

	assertBotState(t, pool, botID, "running", "running")
	var stateNull, pending bool
	if err := pool.QueryRow(ctx, `SELECT state IS NULL, strategy_reset_pending FROM strategy_configs JOIN bots ON bots.id = strategy_configs.bot_id WHERE bot_id=$1`, botID).Scan(&stateNull, &pending); err != nil {
		t.Fatalf("read strategy reset: %v", err)
	}
	if !stateNull || pending {
		t.Fatalf("state_null=%v pending=%v, want true/false", stateNull, pending)
	}
}

func TestReconcilerRetriesPausedCancellation(t *testing.T) {
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
	suffix := time.Now().UnixNano()
	var userID, botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Retry bot', $1, 'x', now(), now()) RETURNING id`, fmt.Sprintf("retry-%d@example.test", suffix)).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status, cancel_retry_requested,
		 capital_allocation, quote_inventory, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Retry bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'paused', 'running', true,
		 '1000', '1000', 1, '{}'::jsonb, now(), now()) RETURNING id`, userID).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}
	t.Cleanup(func() { _, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID) })
	if _, err := pool.Exec(ctx,
		`INSERT INTO orders (bot_id, client_order_id, mode, side, type, price, quantity, filled_quantity, status, requested_at, opened_at, created_at, updated_at)
		 VALUES ($1, 'retry-order', 'paper', 'buy', 'limit', '90', '0.1', 0, 'open', now(), now(), now(), now())`, botID); err != nil {
		t.Fatalf("insert order: %v", err)
	}

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id=$1 AND status='open'`, 0, botID)
	var retry bool
	if err := pool.QueryRow(ctx, `SELECT cancel_retry_requested FROM bots WHERE id=$1`, botID).Scan(&retry); err != nil {
		t.Fatalf("read retry marker: %v", err)
	}
	if retry {
		t.Fatal("cancel retry marker was not cleared")
	}
	var status string
	if err := pool.QueryRow(ctx, `SELECT status FROM bots WHERE id=$1`, botID).Scan(&status); err != nil {
		t.Fatalf("read status: %v", err)
	}
	if status != "paused" {
		t.Fatalf("risk-paused bot auto-resumed: status=%s", status)
	}
}

func TestStartRefreshesStaleTimestampBeforeReconcile(t *testing.T) {
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
	suffix := time.Now().UnixNano()
	var userID, botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Stale start bot', $1, 'x', now(), now()) RETURNING id`, fmt.Sprintf("stale-start-%d@example.test", suffix)).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, tick_interval_seconds, settings,
		 last_tick_at, last_successful_tick_at, created_at, updated_at)
		 VALUES ($1, 'Stale start bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'paused', 'running',
		 '1000', '1000', 1, '{}'::jsonb, now() - interval '1 hour', now() - interval '1 hour', now(), now())
		 RETURNING id`, userID).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}
	t.Cleanup(func() { _, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID) })

	trading := runtime.New(pool, &fixedMarket{price: "100"}, nil)
	if err := trading.Process(ctx, command(protocol.CommandBotStart, userID, botID, nil)); err != nil {
		t.Fatalf("start: %v", err)
	}
	assertBotState(t, pool, botID, "running", "running")
	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("reconcile: %v", err)
	}
	assertBotState(t, pool, botID, "running", "running")
}

// TestRuntimeGapFillsPlaceOppositeSells verifies the live batch path: six buy
// levels fill before post-fill placement, so all six opposite sells can be
// placed at once. It is opt-in like the other runtime integration tests:
//
//	RUNTIME_TEST_DSN='postgres://user:pass@host:5432/trading_bot_test?sslmode=disable' \
//	  go test ./internal/runtime -run TestRuntimeGapFillsPlaceOppositeSells
func TestRuntimeGapFillsPlaceOppositeSells(t *testing.T) {
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

	email := fmt.Sprintf("runtime-gap-%d@example.test", time.Now().UnixNano())
	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Runtime gap', $1, 'x', now(), now())
		 RETURNING id`, email).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID)
	})

	// Keep the next tick running after the gap. The default daily loss limit
	// would pause the bot and cancel the six sells before we can verify that
	// the re-armed state persists.
	if _, err := pool.Exec(ctx,
		`INSERT INTO risk_limits (scope, user_id, max_position_notional, max_open_orders, daily_loss_limit, created_at, updated_at)
		 VALUES ('user', $1, '1000000', 100, '1000000', now(), now())`,
		userID,
	); err != nil {
		t.Fatalf("insert risk limit: %v", err)
	}

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, fee_rate, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Runtime gap bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'idle', 'running',
		 '1000', '1000', '0', 1, '{}', now(), now())
		 RETURNING id`, userID).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}

	if _, err := pool.Exec(ctx,
		`INSERT INTO strategy_configs (bot_id, strategy_key, configuration, state_version, created_at, updated_at)
		 VALUES ($1, 'simple-grid', $2::jsonb, 1, now(), now())`,
		botID,
		`{"center_price":"100","levels_per_side":6,"spacing_type":"arithmetic","spacing_value":"10","order_quantity":"1"}`,
	); err != nil {
		t.Fatalf("insert strategy config: %v", err)
	}

	market := &fixedMarket{price: "100"}
	trading := runtime.New(pool, market, nil)

	if err := trading.Process(ctx, command(protocol.CommandBotStart, userID, botID, nil)); err != nil {
		t.Fatalf("start: %v", err)
	}
	assertBotState(t, pool, botID, "running", "running")

	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("initialize tick: %v", err)
	}
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id=$1 AND status='open' AND side='buy'`, 6, botID)
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id=$1 AND status='open' AND side='sell'`, 0, botID)

	market.price = "30"
	// PostgreSQL timestamps have second precision; wait so the first tick's
	// orders are strictly before the gap tick, matching the paper-fill rule.
	time.Sleep(1100 * time.Millisecond)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("gap tick: %v", err)
	}

	assertCount(t, pool, `SELECT COUNT(*) FROM fills WHERE bot_id=$1 AND side='buy'`, 6, botID)
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id=$1 AND status='open'`, 6, botID)
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id=$1 AND status='open' AND side='buy'`, 0, botID)
	assertCount(t, pool,
		`SELECT COUNT(*) FROM orders WHERE bot_id=$1 AND status='open' AND side='sell'
		 AND price IN (50, 60, 70, 80, 90, 110)`,
		6,
		botID,
	)

	var rawState string
	if err := pool.QueryRow(ctx,
		`SELECT COALESCE(state::text, '{}') FROM strategy_configs WHERE bot_id = $1`,
		botID,
	).Scan(&rawState); err != nil {
		t.Fatalf("read strategy state: %v", err)
	}

	var persisted struct {
		Data struct {
			DisarmedLevels map[string]struct {
				Side string `json:"side"`
				Pair string `json:"pair"`
			} `json:"disarmed_levels"`
		} `json:"data"`
	}
	if err := json.Unmarshal([]byte(rawState), &persisted); err != nil {
		t.Fatalf("decode strategy state: %v", err)
	}
	disarmedLevels := persisted.Data.DisarmedLevels

	expectedPairs := map[string]string{
		"40": "50",
		"50": "60",
		"60": "70",
		"70": "80",
		"80": "90",
		"90": "110",
	}
	if len(disarmedLevels) != len(expectedPairs) {
		t.Fatalf("disarmed levels = %v, want %v", disarmedLevels, expectedPairs)
	}
	for level, pair := range expectedPairs {
		entry, ok := disarmedLevels[level]
		if !ok {
			t.Fatalf("level %s is not disarmed: %v", level, disarmedLevels)
		}
		if entry.Side != "buy" || entry.Pair != pair {
			t.Fatalf("disarmed level %s = %+v, want side buy pair %s", level, entry, pair)
		}
	}

	// The next tick must not place buys above the market or lose the six sells.
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("next tick: %v", err)
	}
	assertBotState(t, pool, botID, "running", "running")
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id=$1 AND status='open' AND side='buy'`, 0, botID)
	assertCount(t, pool, `SELECT COUNT(*) FROM orders WHERE bot_id=$1 AND status='open'`, 6, botID)
}
