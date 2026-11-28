package runtime

import (
	"context"
	"fmt"
	"os"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

func TestRunDueReadsTheStoredTickIntervalWithoutARestart(t *testing.T) {
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

	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Due interval test', $1, 'x', now(), now()) RETURNING id`,
		fmt.Sprintf("due-interval-%d@example.test", time.Now().UnixNano()),
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
		 last_tick_at, settings, created_at, updated_at)
		 VALUES ($1, 'Due interval bot', 'binance', 'BTC/USDT', 'simple-grid', 'paper', 'running', 'running',
		 '1000', '1000', '0', 60, now() - interval '15 seconds', '{}', now(), now())
		 RETURNING id`, userID,
	).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}

	trading := New(pool, nil, nil)

	due, err := trading.dueBotIDs(ctx)
	if err != nil {
		t.Fatalf("due bots: %v", err)
	}
	if len(due) != 0 {
		t.Fatalf("due bots at 60s after 15s = %v, want none", due)
	}

	if _, err := pool.Exec(ctx, `UPDATE bots SET tick_interval_seconds = 10 WHERE id = $1`, botID); err != nil {
		t.Fatalf("update interval: %v", err)
	}

	due, err = trading.dueBotIDs(ctx)
	if err != nil {
		t.Fatalf("due bots after edit: %v", err)
	}
	if len(due) != 1 || due[0] != botID {
		t.Fatalf("due bots after edit = %v, want [%d]", due, botID)
	}
}
