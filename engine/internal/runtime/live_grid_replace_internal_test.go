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

// deadlockFake is the minimal private exchange surface needed to cancel the
// seeded open orders. It never contacts a venue.
type deadlockFake struct {
	orders    map[string]exchange.Order
	cancelled []string
}

func (f *deadlockFake) Exchange() string { return "binance" }

func (f *deadlockFake) PlaceLimit(context.Context, exchange.PlaceRequest) (exchange.Order, error) {
	return exchange.Order{}, nil
}

func (f *deadlockFake) Cancel(_ context.Context, _, _, clientOrderID string) (exchange.Order, error) {
	order := f.orders[clientOrderID]
	order.Status = exchange.StatusCanceled
	f.cancelled = append(f.cancelled, clientOrderID)

	return order, nil
}

func (f *deadlockFake) Order(context.Context, string, string) (exchange.Order, error) {
	return exchange.Order{}, nil
}

func (f *deadlockFake) OrderByClientID(_ context.Context, _, clientOrderID string) (exchange.Order, error) {
	return f.orders[clientOrderID], nil
}

func (f *deadlockFake) OpenOrders(context.Context, string) ([]exchange.Order, error) {
	return nil, nil
}

func (f *deadlockFake) Rules(context.Context, string) (exchange.Rules, error) {
	return exchange.Rules{}, nil
}

func (f *deadlockFake) Balances(context.Context) ([]exchange.Balance, error) {
	return nil, nil
}

func TestSyncGridReplacesLiveGridWithoutDeadlocking(t *testing.T) {
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

	var userID, botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Live grid replacement', $1, 'x', now(), now()) RETURNING id`,
		"live-grid-replace-"+time.Now().Format("150405.000000000"),
	).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	t.Cleanup(func() { _, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID) })

	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, base_inventory, fee_rate, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Live replacement bot', 'binance', 'BTC/USDT', 'simple-grid', 'live', 'running', 'running',
		 '1000', '1000', '0', '0', 1, '{}'::jsonb, now(), now())
		 RETURNING id`, userID).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}

	var gridID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO grids (bot_id, center_price, spacing_type, level_count, spacing_value, status, created_at, updated_at)
		 VALUES ($1, '100', 'arithmetic', 2, '10', 'active', now(), now()) RETURNING id`, botID,
	).Scan(&gridID); err != nil {
		t.Fatalf("insert grid: %v", err)
	}

	orders := map[string]exchange.Order{}
	for _, level := range []struct {
		sequence int
		price    string
		side     string
	}{
		{1, "90", "buy"},
		{2, "110", "sell"},
	} {
		var levelID int64
		if err := pool.QueryRow(ctx,
			`INSERT INTO grid_levels (grid_id, sequence, price, side, state, created_at, updated_at)
			 VALUES ($1, $2, $3, $4, 'open', now(), now()) RETURNING id`,
			gridID, level.sequence, level.price, level.side,
		).Scan(&levelID); err != nil {
			t.Fatalf("insert grid level: %v", err)
		}

		clientID := "old-" + level.side
		if _, err := pool.Exec(ctx,
			`INSERT INTO orders (bot_id, grid_level_id, client_order_id, exchange_order_id, mode, side, type, price, quantity, filled_quantity, status, requested_at, opened_at, created_at, updated_at)
			 VALUES ($1, $2, $3, $4, 'live', $5, 'limit', $6, '1', '0', 'open', now(), now(), now(), now())`,
			botID, levelID, clientID, "ex-"+clientID, level.side, level.price,
		); err != nil {
			t.Fatalf("insert order: %v", err)
		}
		orders[clientID] = exchange.Order{
			ExchangeOrderID: "ex-" + clientID,
			ClientOrderID:   clientID,
			Status:          exchange.StatusOpen,
			Side:            exchange.Side(level.side),
			Price:           decimal.MustParse(level.price),
			Quantity:        decimal.MustParse("1"),
			FilledQuantity:  decimal.Zero(),
		}
	}

	fake := &deadlockFake{orders: orders}
	trading := New(pool, nil, nil,
		WithLiveTrading(true),
		WithLiveClients(func(context.Context, *Bot) (exchange.Client, error) { return fake, nil }),
	)

	bot, err := trading.loadBot(ctx, botID)
	if err != nil {
		t.Fatalf("load bot: %v", err)
	}
	active, err := trading.activeGrid(ctx, botID)
	if err != nil {
		t.Fatalf("load active grid: %v", err)
	}
	if active == nil {
		t.Fatal("expected an active grid")
	}

	stats := newTickStats(botID, time.Now())
	replacement := []strategy.GridLevelPlan{
		{Sequence: 1, Price: decimal.MustParse("85"), Side: strategy.SideBuy},
		{Sequence: 2, Price: decimal.MustParse("115"), Side: strategy.SideSell},
	}

	runCtx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()

	started := time.Now()
	replacedState, replaced, err := trading.syncGrid(runCtx, bot, replacement, active, stats)
	if err != nil {
		t.Fatalf("syncGrid: %v", err)
	}
	if elapsed := time.Since(started); elapsed > 2*time.Second {
		t.Fatalf("syncGrid took %s; the live cancel path is blocking", elapsed)
	}
	if !replaced || replacedState == nil {
		t.Fatalf("expected a replacement, got replaced=%v state=%v", replaced, replacedState)
	}
	if len(fake.cancelled) != 2 {
		t.Fatalf("expected two live cancels, got %v", fake.cancelled)
	}
	if stats.ordersCanceled != 2 {
		t.Fatalf("expected two counted cancels, got %d", stats.ordersCanceled)
	}

	var stopped int
	if err := pool.QueryRow(ctx, `SELECT count(*) FROM grids WHERE id=$1 AND status='stopped'`, gridID).Scan(&stopped); err != nil {
		t.Fatalf("read old grid: %v", err)
	}
	if stopped != 1 {
		t.Fatal("expected the previous grid to be stopped")
	}

	var activeCount, levelCount, canceledCount int
	if err := pool.QueryRow(ctx,
		`SELECT count(*) FROM grids WHERE bot_id=$1 AND status='active'`, botID,
	).Scan(&activeCount); err != nil {
		t.Fatalf("count active grids: %v", err)
	}
	if activeCount != 1 {
		t.Fatalf("expected one active grid, got %d", activeCount)
	}
	if err := pool.QueryRow(ctx,
		`SELECT count(*) FROM grid_levels WHERE grid_id=$1`, replacedState.ID,
	).Scan(&levelCount); err != nil {
		t.Fatalf("count levels: %v", err)
	}
	if levelCount != 2 {
		t.Fatalf("expected two replacement levels, got %d", levelCount)
	}
	if err := pool.QueryRow(ctx,
		`SELECT count(*) FROM orders WHERE bot_id=$1 AND status='canceled'`, botID,
	).Scan(&canceledCount); err != nil {
		t.Fatalf("count canceled orders: %v", err)
	}
	if canceledCount != 2 {
		t.Fatalf("expected two canceled orders, got %d", canceledCount)
	}
}
