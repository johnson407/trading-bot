package runtime_test

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"sync"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/exchange"
	"github.com/johnson407/trading-bot/engine/internal/protocol"
	"github.com/johnson407/trading-bot/engine/internal/runtime"
)

// fakeLive is a deterministic in-memory exchange used only by these
// integration tests. It is not a production adapter and never contacts a venue.
type fakeLive struct {
	mu          sync.Mutex
	orders      map[string]exchange.Order
	open        []string
	placed      []exchange.PlaceRequest
	cancelled   []string
	reject      bool
	partialFill string
	fillFully   bool
	cancelFill  bool
	openErr     error
	openCalls   int
	duplicate   bool
	lookupMiss  bool
	fee         *decimal.Decimal
	feeCurrency string
}

func newFakeLive() *fakeLive {
	return &fakeLive{orders: map[string]exchange.Order{}}
}

func (f *fakeLive) Exchange() string { return "binance" }

func (f *fakeLive) PlaceLimit(_ context.Context, request exchange.PlaceRequest) (exchange.Order, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	if f.reject {
		return exchange.Order{}, &exchange.Error{Type: exchange.ErrorRejected, Exchange: "binance", Operation: "placeOrder", Message: "rejected by fake"}
	}
	if f.duplicate {
		return exchange.Order{}, &exchange.Error{Type: exchange.ErrorRejected, Exchange: "binance", Operation: "placeOrder", Message: "Duplicate order sent."}
	}

	order := exchange.Order{
		ExchangeOrderID: "ex-" + request.ClientOrderID,
		ClientOrderID:   request.ClientOrderID,
		Status:          exchange.StatusOpen,
		Side:            request.Side,
		Price:           request.Price,
		Quantity:        request.Quantity,
		FilledQuantity:  decimal.Zero(),
		AveragePrice:    request.Price,
		Fee:             f.fee,
		FeeCurrency:     f.feeCurrency,
	}
	f.orders[request.ClientOrderID] = order
	f.open = append(f.open, request.ClientOrderID)
	f.placed = append(f.placed, request)

	return order, nil
}

func (f *fakeLive) Cancel(_ context.Context, _, exchangeOrderID, clientOrderID string) (exchange.Order, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	key := clientOrderID
	if key == "" {
		key = exchangeOrderID
	}
	order, ok := f.orders[key]
	if !ok {
		return exchange.Order{}, &exchange.Error{Type: exchange.ErrorNotFound, Exchange: "binance", Operation: "cancelOrder", Message: "not found"}
	}
	if f.cancelFill {
		order.Status = exchange.StatusFilled
		order.FilledQuantity = order.Quantity
	} else {
		order.Status = exchange.StatusCanceled
	}
	f.orders[key] = order
	f.cancelled = append(f.cancelled, key)

	return order, nil
}

func (f *fakeLive) Order(_ context.Context, _, exchangeOrderID string) (exchange.Order, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	for _, order := range f.orders {
		if order.ExchangeOrderID == exchangeOrderID {
			return order, nil
		}
	}

	return exchange.Order{}, &exchange.Error{Type: exchange.ErrorNotFound, Exchange: "binance", Operation: "fetchOrder", Message: "not found"}
}

func (f *fakeLive) OrderByClientID(_ context.Context, _, clientOrderID string) (exchange.Order, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	if f.lookupMiss {
		f.lookupMiss = false

		return exchange.Order{}, &exchange.Error{Type: exchange.ErrorNotFound, Exchange: "binance", Operation: "fetchOrder", Message: "not found"}
	}

	order, ok := f.orders[clientOrderID]
	if !ok {
		return exchange.Order{}, &exchange.Error{Type: exchange.ErrorNotFound, Exchange: "binance", Operation: "fetchOrder", Message: "not found"}
	}
	if f.partialFill == clientOrderID && order.Status == exchange.StatusOpen {
		order.Status = exchange.StatusPartiallyFilled
		if half, err := order.Quantity.Div(decimal.FromInt(2)); err == nil {
			order.FilledQuantity = half
		}
		f.orders[clientOrderID] = order
	}
	if f.fillFully && order.Status == exchange.StatusOpen {
		order.Status = exchange.StatusFilled
		order.FilledQuantity = order.Quantity
		f.orders[clientOrderID] = order
	}

	return order, nil
}

func (f *fakeLive) OpenOrders(context.Context, string) ([]exchange.Order, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.openCalls++
	if f.openErr != nil {
		return nil, f.openErr
	}

	result := make([]exchange.Order, 0, len(f.open))
	for _, clientID := range f.open {
		order, ok := f.orders[clientID]
		if !ok {
			continue
		}
		if f.partialFill == clientID && order.Status == exchange.StatusOpen {
			order.Status = exchange.StatusPartiallyFilled
			if half, err := order.Quantity.Div(decimal.FromInt(2)); err == nil {
				order.FilledQuantity = half
			}
			f.orders[clientID] = order
		}
		if f.fillFully && order.Status == exchange.StatusOpen {
			order.Status = exchange.StatusFilled
			order.FilledQuantity = order.Quantity
			f.orders[clientID] = order
		}
		if order.Status == exchange.StatusOpen || order.Status == exchange.StatusPartiallyFilled {
			result = append(result, order)
		}
	}

	return result, nil
}

func (f *fakeLive) Rules(context.Context, string) (exchange.Rules, error) {
	return exchange.Rules{
		Symbol:          "BTC/USDT",
		MinAmount:       decimal.MustParse("0.0001"),
		MinNotional:     decimal.MustParse("1"),
		PricePrecision:  2,
		AmountPrecision: 4,
	}, nil
}

func (f *fakeLive) Balances(context.Context) ([]exchange.Balance, error) {
	return []exchange.Balance{{
		Asset: "USDT",
		Free:  decimal.MustParse("1000"),
		Total: decimal.MustParse("1000"),
	}}, nil
}

func liveTestPool(t *testing.T) *pgxpool.Pool {
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

func seedLiveBot(t *testing.T, pool *pgxpool.Pool, quantity string) (int64, int64) {
	t.Helper()

	ctx := context.Background()
	var userID, botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Live integration', $1, 'x', now(), now()) RETURNING id`,
		fmt.Sprintf("live-%d@example.test", time.Now().UnixNano()),
	).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	t.Cleanup(func() { _, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID) })

	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, quote_inventory, base_inventory, fee_rate, tick_interval_seconds, settings, created_at, updated_at)
		 VALUES ($1, 'Live bot', 'binance', 'BTC/USDT', 'simple-grid', 'live', 'idle', 'running',
		 '1000', '1000', '0', '0', 1, '{}'::jsonb, now(), now())
		 RETURNING id`, userID).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}
	if _, err := pool.Exec(ctx,
		`INSERT INTO strategy_configs (bot_id, strategy_key, configuration, state_version, created_at, updated_at)
		 VALUES ($1, 'simple-grid', $2::jsonb, 1, now(), now())`,
		botID,
		fmt.Sprintf(`{"center_price":"100","levels_per_side":1,"spacing_type":"arithmetic","spacing_value":"10","order_quantity":%q}`, quantity),
	); err != nil {
		t.Fatalf("insert strategy config: %v", err)
	}

	return userID, botID
}

func liveRuntime(pool *pgxpool.Pool, fake *fakeLive, enabled bool) *runtime.Runtime {
	return runtime.New(pool, &fixedMarket{price: "100"}, nil,
		runtime.WithLiveTrading(enabled),
		runtime.WithLiveClients(func(context.Context, *runtime.Bot) (exchange.Client, error) {
			return fake, nil
		}),
	)
}

func startBot(t *testing.T, ctx context.Context, trading *runtime.Runtime, userID, botID int64) {
	t.Helper()
	if err := trading.Process(ctx, command(protocol.CommandBotStart, userID, botID, nil)); err != nil {
		t.Fatalf("start: %v", err)
	}
}

func latestOrder(t *testing.T, pool *pgxpool.Pool, botID int64) (int64, string, string, string) {
	t.Helper()
	var orderID int64
	var clientID, exchangeID, status string
	if err := pool.QueryRow(context.Background(),
		`SELECT id, client_order_id, COALESCE(exchange_order_id, ''), status FROM orders WHERE bot_id=$1 ORDER BY id DESC LIMIT 1`, botID,
	).Scan(&orderID, &clientID, &exchangeID, &status); err != nil {
		t.Fatalf("read live order: %v", err)
	}

	return orderID, clientID, exchangeID, status
}

func TestLivePlacementRoundsAndCancelAppliesPartialFill(t *testing.T) {
	pool := liveTestPool(t)
	ctx := context.Background()
	userID, botID := seedLiveBot(t, pool, "0.123456")
	fake := newFakeLive()
	trading := liveRuntime(pool, fake, true)

	startBot(t, ctx, trading, userID, botID)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}
	if len(fake.placed) != 1 {
		t.Fatalf("placed = %d, want 1 (the sell side is unfunded)", len(fake.placed))
	}
	if got := fake.placed[0].Quantity.String(); got != "0.1234" {
		t.Errorf("placed quantity = %s, want venue-rounded 0.1234", got)
	}

	orderID, clientID, exchangeID, status := latestOrder(t, pool, botID)
	if exchangeID == "" || status != "open" {
		t.Fatalf("order = %s/%s, want an open order with an exchange id", exchangeID, status)
	}
	var openedAt *time.Time
	if err := pool.QueryRow(ctx, `SELECT opened_at FROM orders WHERE id=$1`, orderID).Scan(&openedAt); err != nil {
		t.Fatalf("read opened_at: %v", err)
	}
	if openedAt == nil {
		t.Error("opened_at is null for a live placement")
	}

	// A partial fill is discovered by reconcile and applied to inventory. The
	// commission is paid in BNB, so it must not reduce the quote inventory.
	fee := decimal.MustParse("0.01")
	fake.mu.Lock()
	fake.partialFill = clientID
	fake.fee = &fee
	fake.feeCurrency = "BNB"
	fake.mu.Unlock()

	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("reconcile: %v", err)
	}

	var filled, base string
	if err := pool.QueryRow(ctx,
		`SELECT filled_quantity::text, (SELECT base_inventory::text FROM bots WHERE id=$1) FROM orders WHERE bot_id=$1 ORDER BY id DESC LIMIT 1`, botID,
	).Scan(&filled, &base); err != nil {
		t.Fatalf("read partial fill: %v", err)
	}
	if filled != "0.0617000000000000" || base != "0.0617000000000000" {
		t.Fatalf("filled/base = %s/%s, want 0.0617/0.0617", filled, base)
	}
	var quote string
	if err := pool.QueryRow(ctx, `SELECT quote_inventory::text FROM bots WHERE id=$1`, botID).Scan(&quote); err != nil {
		t.Fatalf("read quote inventory: %v", err)
	}
	if quote != "994.4470000000000000" {
		t.Errorf("quote inventory = %s, want the buy notional only (a BNB fee must not reduce quote)", quote)
	}

	// The cancel response reports the rest of the order filled. That delta must
	// reach inventory, not be discarded with the local cancellation.
	fake.mu.Lock()
	fake.cancelFill = true
	fake.mu.Unlock()

	payload, _ := json.Marshal(map[string]any{"order_id": orderID})
	if err := trading.Process(ctx, command(protocol.CommandOrderCancel, userID, botID, payload)); err != nil {
		t.Fatalf("cancel: %v", err)
	}
	if len(fake.cancelled) != 1 {
		t.Errorf("cancelled = %v, want one exchange cancel", fake.cancelled)
	}
	if err := pool.QueryRow(ctx, `SELECT status FROM orders WHERE id=$1`, orderID).Scan(&status); err != nil {
		t.Fatalf("read canceled order: %v", err)
	}
	if status != "filled" {
		t.Errorf("status = %s, want filled to match the cancel response", status)
	}
	if err := pool.QueryRow(ctx, `SELECT base_inventory::text FROM bots WHERE id=$1`, botID).Scan(&base); err != nil {
		t.Fatalf("read base inventory: %v", err)
	}
	if base != "0.1234000000000000" {
		t.Errorf("base inventory = %s, want the fill that raced the cancel (0.1234)", base)
	}
}

func TestLiveFillDisarmsLevelAndIsMarkedApplied(t *testing.T) {
	pool := liveTestPool(t)
	ctx := context.Background()
	userID, botID := seedLiveBot(t, pool, "0.2")
	fake := newFakeLive()
	trading := liveRuntime(pool, fake, true)

	startBot(t, ctx, trading, userID, botID)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}

	fake.mu.Lock()
	fake.fillFully = true
	fake.mu.Unlock()
	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("reconcile: %v", err)
	}

	// The next tick must feed the reconciled fill through the strategy's
	// OnFill, disarm the filled level, and mark the fill as applied.
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("fill tick: %v", err)
	}

	var applied *time.Time
	if err := pool.QueryRow(ctx,
		`SELECT strategy_applied_at FROM fills WHERE bot_id=$1 AND exchange <> 'paper' ORDER BY id DESC LIMIT 1`, botID,
	).Scan(&applied); err != nil {
		t.Fatalf("read applied fill: %v", err)
	}
	if applied == nil {
		t.Error("strategy_applied_at is null after the tick")
	}

	var side, status string
	if err := pool.QueryRow(ctx,
		`SELECT o.side, o.status FROM orders o WHERE o.bot_id=$1 ORDER BY o.id DESC LIMIT 1`, botID,
	).Scan(&side, &status); err != nil {
		t.Fatalf("read adjacent order: %v", err)
	}
	if side != "sell" || status != "open" {
		t.Errorf("adjacent order = %s/%s, want an open sell", side, status)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id=$1`, 2, botID)

	// A second tick must not re-buy the disarmed level.
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("second tick: %v", err)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id=$1`, 2, botID)
}

func TestLiveRejectionAndNewGeneration(t *testing.T) {
	pool := liveTestPool(t)
	ctx := context.Background()
	userID, botID := seedLiveBot(t, pool, "0.1")
	fake := newFakeLive()
	trading := liveRuntime(pool, fake, true)

	startBot(t, ctx, trading, userID, botID)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}
	firstID, firstClientID, _, _ := latestOrder(t, pool, botID)
	if firstID == 0 {
		t.Fatal("expected a first live order")
	}

	payload, _ := json.Marshal(map[string]any{"order_id": firstID})
	if err := trading.Process(ctx, command(protocol.CommandOrderCancel, userID, botID, payload)); err != nil {
		t.Fatalf("cancel: %v", err)
	}

	// A terminal order must not have its client order ID reused.
	fake.mu.Lock()
	fake.reject = true
	fake.mu.Unlock()

	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("reject tick: %v", err)
	}
	secondID, secondClientID, _, secondStatus := latestOrder(t, pool, botID)
	if secondID == firstID {
		t.Fatal("rejecting a placement must create a new order row")
	}
	if secondClientID == firstClientID {
		t.Errorf("client ID %q was reused after a completed intent", secondClientID)
	}
	if secondStatus != "rejected" {
		t.Errorf("rejected status = %s, want rejected", secondStatus)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id=$1 AND status IN ('pending','open','partially_filled')`, 0, botID)

	// Clearing the rejection re-arms with yet another generation.
	fake.mu.Lock()
	fake.reject = false
	fake.mu.Unlock()

	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("re-arm tick: %v", err)
	}
	_, thirdClientID, thirdExchangeID, thirdStatus := latestOrder(t, pool, botID)
	if thirdClientID == firstClientID || thirdClientID == secondClientID {
		t.Errorf("client ID %q was reused after a terminal order", thirdClientID)
	}
	if thirdExchangeID == "" || thirdStatus != "open" {
		t.Errorf("re-armed order = %s/%s, want an open order with an exchange id", thirdExchangeID, thirdStatus)
	}
}

func TestLivePendingIntentRetryReusesClientID(t *testing.T) {
	pool := liveTestPool(t)
	ctx := context.Background()
	userID, botID := seedLiveBot(t, pool, "0.1")
	fake := newFakeLive()
	trading := liveRuntime(pool, fake, true)

	startBot(t, ctx, trading, userID, botID)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}
	orderID, clientID, _, _ := latestOrder(t, pool, botID)

	// Simulate a crash after persisting the intent but before recording the
	// exchange response.
	if _, err := pool.Exec(ctx, `UPDATE orders SET exchange_order_id = NULL, status = 'pending' WHERE id=$1`, orderID); err != nil {
		t.Fatalf("simulate persist-before-place: %v", err)
	}
	fake.mu.Lock()
	delete(fake.orders, clientID)
	fake.open = nil
	fake.mu.Unlock()

	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id=$1`, 1, botID)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("retry tick: %v", err)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id=$1`, 1, botID)

	_, retriedClientID, retriedExchangeID, retriedStatus := latestOrder(t, pool, botID)
	if retriedClientID != clientID || retriedExchangeID == "" || retriedStatus != "open" {
		t.Errorf("retry = %s/%s/%s, want the same client ID with an exchange id and open status", retriedClientID, retriedExchangeID, retriedStatus)
	}
}

func TestLiveDuplicateAcknowledgementAdoptsExistingOrder(t *testing.T) {
	pool := liveTestPool(t)
	ctx := context.Background()
	userID, botID := seedLiveBot(t, pool, "0.1")
	fake := newFakeLive()
	trading := liveRuntime(pool, fake, true)

	startBot(t, ctx, trading, userID, botID)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}
	orderID, clientID, exchangeID, _ := latestOrder(t, pool, botID)
	if exchangeID == "" {
		t.Fatal("expected a live exchange order")
	}

	// Simulate a lost place response: the exchange has the order, but the local
	// row is pending and the first lookup misses.
	if _, err := pool.Exec(ctx, `UPDATE orders SET exchange_order_id = NULL, status = 'pending' WHERE id=$1`, orderID); err != nil {
		t.Fatalf("simulate unknown outcome: %v", err)
	}
	fake.mu.Lock()
	fake.duplicate = true
	fake.lookupMiss = true
	fake.mu.Unlock()

	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("duplicate tick: %v", err)
	}

	_, gotClientID, gotExchangeID, gotStatus := latestOrder(t, pool, botID)
	if gotClientID != clientID || gotExchangeID != exchangeID || gotStatus != "open" {
		t.Errorf("adopted order = %s/%s/%s, want %s/%s/open", gotClientID, gotExchangeID, gotStatus, clientID, exchangeID)
	}
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id=$1`, 1, botID)
	assertCount(t, pool, `SELECT count(*) FROM orders WHERE bot_id=$1 AND status='rejected'`, 0, botID)
}

func TestLiveRateLimitBlocksReconcileUntilRetryAfter(t *testing.T) {
	pool := liveTestPool(t)
	ctx := context.Background()
	userID, botID := seedLiveBot(t, pool, "0.1")
	fake := newFakeLive()
	trading := liveRuntime(pool, fake, true)

	startBot(t, ctx, trading, userID, botID)
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}

	fake.mu.Lock()
	fake.openErr = &exchange.Error{
		Type:       exchange.ErrorRateLimit,
		Exchange:   "binance",
		Operation:  "openOrders",
		Message:    "too many requests",
		RetryAfter: time.Hour,
	}
	fake.mu.Unlock()

	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("rate-limited reconcile: %v", err)
	}
	fake.mu.Lock()
	firstCalls := fake.openCalls
	fake.mu.Unlock()
	if firstCalls == 0 {
		t.Fatal("expected the first reconcile to contact the exchange")
	}

	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("blocked reconcile: %v", err)
	}
	fake.mu.Lock()
	secondCalls := fake.openCalls
	fake.mu.Unlock()
	if secondCalls != firstCalls {
		t.Errorf("open-order calls = %d after Retry-After, want %d", secondCalls, firstCalls)
	}
}

func TestLiveStartRefusedWhenGateClosed(t *testing.T) {
	pool := liveTestPool(t)
	ctx := context.Background()
	userID, botID := seedLiveBot(t, pool, "0.1")
	fake := newFakeLive()
	trading := liveRuntime(pool, fake, false)

	startBot(t, ctx, trading, userID, botID)
	assertBotState(t, pool, botID, "paused", "running")
	if len(fake.placed) != 0 {
		t.Errorf("placed = %d, want no placement while the gate is closed", len(fake.placed))
	}
}
