package runtime

import (
	"context"
	"fmt"
	"os"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func dailyLossInternalTestPool(t *testing.T) *pgxpool.Pool {
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

func dailyLossInternalTestUserBot(t *testing.T, ctx context.Context, pool *pgxpool.Pool) (int64, int64) {
	t.Helper()

	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Daily loss internal', $1, 'x', now(), now()) RETURNING id`,
		fmt.Sprintf("daily-loss-internal-%d@example.test", time.Now().UnixNano()),
	).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID)
	})

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Daily loss internal bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'running', 'running',
		 '1000', '1000', 1, '{}'::jsonb, now(), now())
		 RETURNING id`, userID).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}

	return userID, botID
}

// restoreRenamedBotsRealizedColumn recovers a previous run that was killed while
// the bots.realized_pnl column was renamed.
func restoreRenamedBotsRealizedColumn(t *testing.T, pool *pgxpool.Pool) {
	t.Helper()

	var leftover bool
	if err := pool.QueryRow(context.Background(),
		`SELECT EXISTS (
			SELECT 1 FROM information_schema.columns
			WHERE table_schema = 'public' AND table_name = 'bots'
			  AND column_name = 'realized_pnl_unavailable_test'
		)`).Scan(&leftover); err != nil {
		t.Fatalf("check for leftover renamed realized_pnl column: %v", err)
	}
	if !leftover {
		return
	}
	if _, err := pool.Exec(context.Background(),
		`ALTER TABLE bots RENAME COLUMN realized_pnl_unavailable_test TO realized_pnl`); err != nil {
		t.Fatalf("restore leftover realized_pnl column: %v", err)
	}
}

func TestDailyLossFailsClosedWhenAccountRealizedPNLCannotBeRead(t *testing.T) {
	pool := dailyLossInternalTestPool(t)
	ctx := context.Background()
	userID, botID := dailyLossInternalTestUserBot(t, ctx, pool)

	if _, err := pool.Exec(ctx,
		`INSERT INTO risk_limits (scope, user_id, daily_loss_limit, day_start, day_start_realized_pnl, created_at, updated_at)
		 VALUES ('user', $1, '100', now() - interval '1 day', '42', now(), now())`, userID); err != nil {
		t.Fatalf("insert account risk limit: %v", err)
	}

	restoreRenamedBotsRealizedColumn(t, pool)
	if _, err := pool.Exec(ctx, `ALTER TABLE bots RENAME COLUMN realized_pnl TO realized_pnl_unavailable_test`); err != nil {
		t.Fatalf("rename realized_pnl: %v", err)
	}
	t.Cleanup(func() {
		if _, err := pool.Exec(context.Background(),
			`ALTER TABLE IF EXISTS bots RENAME COLUMN realized_pnl_unavailable_test TO realized_pnl`); err != nil {
			t.Errorf("restore realized_pnl: %v", err)
		}
	})

	today := time.Now().UTC().Format("2006-01-02")
	bot := &Bot{
		ID: botID, UserID: userID,
		Realized: decimal.Zero(), Base: decimal.Zero(), AverageEntry: decimal.Zero(),
		Settings: map[string]any{"daily_loss_baseline": map[string]any{"day": today, "realized": "0"}},
	}
	trading := New(pool, nil, nil)
	if _, _, _, err := trading.dailyLoss(ctx, bot, decimal.MustParse("100")); err == nil {
		t.Fatal("dailyLoss with an unreadable account P&L returned nil, want an error")
	}

	var baseline string
	if err := pool.QueryRow(ctx,
		`SELECT day_start_realized_pnl::text FROM risk_limits WHERE user_id=$1 AND scope='user' AND bot_id IS NULL`,
		userID,
	).Scan(&baseline); err != nil {
		t.Fatalf("read account baseline: %v", err)
	}
	if baseline != "42.0000000000000000" {
		t.Fatalf("account baseline = %q, want the previous value 42", baseline)
	}
}
