package runtime

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/exchange"
	"github.com/johnson407/trading-bot/engine/internal/protocol"
)

// recordingExchange is a deterministic in-memory exchange fake that records
// every order submission, including any market or liquidation order. The
// runtime must never submit one during a safety pause.
type recordingExchange struct {
	mu                  sync.Mutex
	orders              map[string]exchange.Order
	placed              []exchange.PlaceRequest
	attempted           []exchange.PlaceRequest
	cancelled           []string
	marketOrLiquidation []string
	placeErr            error
	openErr             error
}

func newRecordingExchange() *recordingExchange {
	return &recordingExchange{orders: map[string]exchange.Order{}}
}

func (f *recordingExchange) Exchange() string { return "binance" }

func (f *recordingExchange) seed(clientID, side, price, quantity, filled string, status exchange.Status) {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.orders[clientID] = exchange.Order{
		ExchangeOrderID: "ex-" + clientID,
		ClientOrderID:   clientID,
		Status:          status,
		Side:            exchange.Side(side),
		Price:           decimal.MustParse(price),
		Quantity:        decimal.MustParse(quantity),
		FilledQuantity:  decimal.MustParse(filled),
		AveragePrice:    decimal.MustParse(price),
	}
}

func (f *recordingExchange) PlaceLimit(_ context.Context, request exchange.PlaceRequest) (exchange.Order, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.attempted = append(f.attempted, request)
	if f.placeErr != nil {
		return exchange.Order{}, f.placeErr
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
	}
	f.orders[request.ClientOrderID] = order
	f.placed = append(f.placed, request)

	return order, nil
}

// PlaceMarket and Liquidate are not part of exchange.Client. They exist so a
// future production path that adds either call must record it here; the
// regression assertion then fails loudly.
func (f *recordingExchange) PlaceMarket(clientID string) {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.marketOrLiquidation = append(f.marketOrLiquidation, "market:"+clientID)
}

func (f *recordingExchange) Liquidate(clientID string) {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.marketOrLiquidation = append(f.marketOrLiquidation, "liquidation:"+clientID)
}

func (f *recordingExchange) Cancel(_ context.Context, _, exchangeOrderID, clientOrderID string) (exchange.Order, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	key := clientOrderID
	if key == "" {
		key = exchangeOrderID
	}
	order, ok := f.orders[key]
	if !ok {
		return exchange.Order{}, &exchange.Error{
			Type: exchange.ErrorNotFound, Exchange: "binance", Operation: "cancelOrder", Message: "not found",
		}
	}

	order.Status = exchange.StatusCanceled
	f.orders[key] = order
	f.cancelled = append(f.cancelled, key)

	return order, nil
}

func (f *recordingExchange) Order(_ context.Context, _, exchangeOrderID string) (exchange.Order, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	for _, order := range f.orders {
		if order.ExchangeOrderID == exchangeOrderID {
			return order, nil
		}
	}

	return exchange.Order{}, &exchange.Error{
		Type: exchange.ErrorNotFound, Exchange: "binance", Operation: "fetchOrder", Message: "not found",
	}
}

func (f *recordingExchange) OrderByClientID(_ context.Context, _, clientOrderID string) (exchange.Order, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	order, ok := f.orders[clientOrderID]
	if !ok {
		return exchange.Order{}, &exchange.Error{
			Type: exchange.ErrorNotFound, Exchange: "binance", Operation: "fetchOrder", Message: "not found",
		}
	}

	return order, nil
}

func (f *recordingExchange) OpenOrders(context.Context, string) ([]exchange.Order, error) {
	f.mu.Lock()
	defer f.mu.Unlock()

	if f.openErr != nil {
		return nil, f.openErr
	}

	result := make([]exchange.Order, 0, len(f.orders))
	for _, order := range f.orders {
		if order.Status == exchange.StatusOpen || order.Status == exchange.StatusPartiallyFilled {
			result = append(result, order)
		}
	}

	return result, nil
}

func (f *recordingExchange) Rules(context.Context, string) (exchange.Rules, error) {
	return exchange.Rules{
		Symbol:          "BTC/USDT",
		MinAmount:       decimal.MustParse("0.0001"),
		MinNotional:     decimal.MustParse("1"),
		PricePrecision:  2,
		AmountPrecision: 4,
	}, nil
}

func (f *recordingExchange) Balances(context.Context) ([]exchange.Balance, error) {
	return []exchange.Balance{{
		Asset: "USDT",
		Free:  decimal.MustParse("1000"),
		Total: decimal.MustParse("1000"),
	}}, nil
}

type manualLiquidationMarket struct {
	price string
	err   error
}

func (m manualLiquidationMarket) Last(context.Context, string, string) (decimal.Decimal, error) {
	if m.err != nil {
		return decimal.Decimal{}, m.err
	}

	return decimal.MustParse(m.price), nil
}

type manualLiquidationBotOptions struct {
	mode            string
	exchange        string
	status          string
	desired         string
	base            string
	quote           string
	averageEntry    string
	realized        string
	settings        string
	stopLossPercent string
	lastSuccessful  string
}

func manualLiquidationPool(t *testing.T) *pgxpool.Pool {
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

func insertManualLiquidationUser(t *testing.T, ctx context.Context, pool *pgxpool.Pool) int64 {
	t.Helper()

	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Manual liquidation', $1, 'x', now(), now()) RETURNING id`,
		fmt.Sprintf("manual-liquidation-%d@example.test", time.Now().UnixNano()),
	).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	t.Cleanup(func() {
		_, _ = pool.Exec(context.Background(), `DELETE FROM users WHERE id = $1`, userID)
	})

	return userID
}

func insertManualLiquidationBot(t *testing.T, ctx context.Context, pool *pgxpool.Pool, userID int64, opts manualLiquidationBotOptions) int64 {
	t.Helper()

	if opts.mode == "" {
		opts.mode = "live"
	}
	if opts.exchange == "" {
		opts.exchange = "binance"
	}
	if opts.status == "" {
		opts.status = "running"
	}
	if opts.desired == "" {
		opts.desired = "running"
	}
	if opts.base == "" {
		opts.base = "0.5"
	}
	if opts.quote == "" {
		opts.quote = "950"
	}
	if opts.averageEntry == "" {
		opts.averageEntry = "100"
	}
	if opts.realized == "" {
		opts.realized = "0"
	}
	if opts.settings == "" {
		opts.settings = "{}"
	}

	stopLoss := "NULL"
	if opts.stopLossPercent != "" {
		stopLoss = opts.stopLossPercent
	}
	lastSuccessful := "now()"
	if opts.lastSuccessful != "" {
		lastSuccessful = opts.lastSuccessful
	}

	query := fmt.Sprintf(
		`INSERT INTO bots (user_id, name, exchange, symbol, strategy_key, mode, status, desired_status,
		 capital_allocation, base_inventory, quote_inventory, average_entry_price, realized_pnl,
		 stop_loss_percent, tick_interval_seconds, settings, last_successful_tick_at, created_at, updated_at)
		 VALUES ($1, 'Manual liquidation bot', $2, 'BTC/USDT', 'simple-grid', $3, $4, $5,
		 '1000', $6, $7, $8, $9, %s, 1, $10::jsonb, %s, now(), now())
		 RETURNING id`, stopLoss, lastSuccessful)

	var botID int64
	if err := pool.QueryRow(ctx, query,
		userID, opts.exchange, opts.mode, opts.status, opts.desired,
		opts.base, opts.quote, opts.averageEntry, opts.realized, opts.settings,
	).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}

	if _, err := pool.Exec(ctx,
		`INSERT INTO strategy_configs (bot_id, strategy_key, configuration, state_version, created_at, updated_at)
		 VALUES ($1, 'simple-grid', $2::jsonb, 1, now(), now())`,
		botID,
		`{"center_price":"100","levels_per_side":1,"spacing_type":"arithmetic","spacing_value":"10","order_quantity":"0.1"}`,
	); err != nil {
		t.Fatalf("insert strategy config: %v", err)
	}

	return botID
}

func seedManualLiquidationOrders(t *testing.T, ctx context.Context, pool *pgxpool.Pool, fake *recordingExchange, botID int64, mode string) {
	t.Helper()

	insert := func(clientID, exchangeID, side, price, quantity, filled, status string) {
		t.Helper()
		if _, err := pool.Exec(ctx,
			`INSERT INTO orders (bot_id, client_order_id, exchange_order_id, mode, side, type,
			 price, quantity, filled_quantity, status, requested_at, opened_at, created_at, updated_at)
			 VALUES ($1, $2, NULLIF($3, ''), $4, $5, 'limit', $6, $7, $8, $9, now(), now(), now(), now())`,
			botID, clientID, exchangeID, mode, side, price, quantity, filled, status,
		); err != nil {
			t.Fatalf("insert %s order: %v", status, err)
		}
	}

	pendingID := fmt.Sprintf("manual-%d-pending", botID)
	openID := fmt.Sprintf("manual-%d-open", botID)
	partialID := fmt.Sprintf("manual-%d-partial", botID)

	// A pending intent has no confirmed exchange order yet.
	insert(pendingID, "", "buy", "70", "0.1", "0", "pending")
	insert(openID, "ex-"+openID, "buy", "80", "0.1", "0", "open")
	insert(partialID, "ex-"+partialID, "buy", "60", "0.1", "0.05", "partially_filled")

	if mode == "live" {
		fake.seed(openID, "buy", "80", "0.1", "0", exchange.StatusOpen)
		fake.seed(partialID, "buy", "60", "0.1", "0.05", exchange.StatusPartiallyFilled)
	}
}

func manualLiquidationInventories(t *testing.T, ctx context.Context, pool *pgxpool.Pool, botID int64) (string, string) {
	t.Helper()

	var base, quote string
	if err := pool.QueryRow(ctx,
		`SELECT base_inventory::text, quote_inventory::text FROM bots WHERE id = $1`, botID,
	).Scan(&base, &quote); err != nil {
		t.Fatalf("read inventory: %v", err)
	}

	return base, quote
}

func assertManualLiquidationPolicy(t *testing.T, ctx context.Context, pool *pgxpool.Pool, fake *recordingExchange, botID int64, beforeBase, beforeQuote, wantCause string, wantError string) {
	t.Helper()

	var openCount int
	if err := pool.QueryRow(ctx,
		`SELECT count(*) FROM orders WHERE bot_id = $1 AND status IN ('pending','open','partially_filled')`,
		botID,
	).Scan(&openCount); err != nil {
		t.Fatalf("count working orders: %v", err)
	}
	if openCount != 0 {
		t.Fatalf("working orders after pause = %d, want 0", openCount)
	}

	var status, reason string
	var lastError *string
	if err := pool.QueryRow(ctx,
		`SELECT status, COALESCE(pause_reason, ''), last_error FROM bots WHERE id = $1`, botID,
	).Scan(&status, &reason, &lastError); err != nil {
		t.Fatalf("read paused bot: %v", err)
	}
	if status != "paused" {
		t.Fatalf("status = %s, want paused", status)
	}
	if reason != wantCause {
		t.Fatalf("pause reason = %s, want %s", reason, wantCause)
	}
	if wantError != "" {
		if lastError == nil || !strings.Contains(*lastError, wantError) {
			t.Fatalf("last_error = %v, want it to contain %q", lastError, wantError)
		}
	}

	if afterBase, afterQuote := manualLiquidationInventories(t, ctx, pool, botID); afterBase != beforeBase || afterQuote != beforeQuote {
		t.Fatalf("inventory = %s/%s, want unchanged %s/%s", afterBase, afterQuote, beforeBase, beforeQuote)
	}

	fake.mu.Lock()
	placed := len(fake.placed)
	marketOrLiquidation := len(fake.marketOrLiquidation)
	fake.mu.Unlock()
	if placed != 0 {
		t.Fatalf("limit orders submitted after pause = %d, want 0", placed)
	}
	if marketOrLiquidation != 0 {
		t.Fatalf("market or liquidation orders submitted = %d, want 0", marketOrLiquidation)
	}
}

func TestSafetyPausesCancelWithoutLiquidating(t *testing.T) {
	pool := manualLiquidationPool(t)
	ctx := context.Background()
	today := time.Now().UTC().Format("2006-01-02")
	dailyLossSettings := fmt.Sprintf(`{"daily_loss_baseline":{"day":%q,"realized":"0"}}`, today)
	staleClock := &manualLiquidationClock{now: time.Date(2026, 11, 28, 12, 0, 0, 0, time.UTC)}

	type policyCase struct {
		name      string
		bot       manualLiquidationBotOptions
		market    manualLiquidationMarket
		live      bool
		clock     *manualLiquidationClock
		configure func(t *testing.T, fake *recordingExchange)
		trigger   func(t *testing.T, trading *Runtime, userID, botID int64)
		wantCause string
		wantError string
	}

	cases := []policyCase{
		{
			name:      "daily loss",
			bot:       manualLiquidationBotOptions{realized: "-150", settings: dailyLossSettings},
			market:    manualLiquidationMarket{price: "100"},
			live:      true,
			trigger:   func(t *testing.T, trading *Runtime, _, botID int64) { runTick(t, ctx, trading, botID) },
			wantCause: pauseCauseDailyLoss.String(),
			wantError: "Daily loss limit reached",
		},
		{
			name:      "protective exit",
			bot:       manualLiquidationBotOptions{stopLossPercent: "10"},
			market:    manualLiquidationMarket{price: "80"},
			live:      true,
			trigger:   func(t *testing.T, trading *Runtime, _, botID int64) { runTick(t, ctx, trading, botID) },
			wantCause: pauseCauseProtectiveExit.String(),
			wantError: "Stop-loss triggered",
		},
		{
			name:   "exchange failure threshold",
			bot:    manualLiquidationBotOptions{},
			market: manualLiquidationMarket{price: "100"},
			live:   true,
			configure: func(t *testing.T, fake *recordingExchange) {
				fake.placeErr = &exchange.Error{
					Type: exchange.ErrorAuthentication, Exchange: "binance", Operation: "placeOrder", Message: "invalid api key",
				}
			},
			trigger:   func(t *testing.T, trading *Runtime, _, botID int64) { runTick(t, ctx, trading, botID) },
			wantCause: pauseCauseTickFailure.String(),
		},
		{
			name:   "repeated tick failure",
			bot:    manualLiquidationBotOptions{},
			market: manualLiquidationMarket{err: errors.New("market unavailable")},
			live:   true,
			trigger: func(t *testing.T, trading *Runtime, _, botID int64) {
				for i := 0; i < 3; i++ {
					runTick(t, ctx, trading, botID)
				}
			},
			wantCause: pauseCauseTickFailure.String(),
			wantError: "3 consecutive tick failures",
		},
		{
			name:   "stale tick",
			bot:    manualLiquidationBotOptions{lastSuccessful: "now() - interval '1 hour'"},
			market: manualLiquidationMarket{price: "100"},
			live:   true,
			clock:  staleClock,
			trigger: func(t *testing.T, trading *Runtime, _, _ int64) {
				// The first pass grants restart grace; the second pass, after the
				// grace window, sees the bot still stale and pauses it.
				runReconcile(t, ctx, trading)
				staleClock.Advance(10 * time.Minute)
				runReconcile(t, ctx, trading)
			},
			wantCause: pauseCauseStaleTick.String(),
		},
		{
			name:      "live gate",
			bot:       manualLiquidationBotOptions{},
			market:    manualLiquidationMarket{price: "100"},
			live:      false,
			trigger:   func(t *testing.T, trading *Runtime, _, botID int64) { runTick(t, ctx, trading, botID) },
			wantCause: pauseCauseLiveGate.String(),
			wantError: "Live trading is disabled",
		},
		{
			name:   "reconciliation conflict",
			bot:    manualLiquidationBotOptions{},
			market: manualLiquidationMarket{price: "100"},
			live:   true,
			trigger: func(t *testing.T, trading *Runtime, _, botID int64) {
				bot, err := trading.loadBot(ctx, botID)
				if err != nil {
					t.Fatalf("load bot: %v", err)
				}
				if err := trading.pause(ctx, bot, pauseCauseReconciliation, "Local state disagreed with the exchange.", "", "", "critical"); err != nil {
					t.Fatalf("pause: %v", err)
				}
			},
			wantCause: pauseCauseReconciliation.String(),
		},
		{
			name:   "strategy stop",
			bot:    manualLiquidationBotOptions{},
			market: manualLiquidationMarket{price: "100"},
			live:   true,
			trigger: func(t *testing.T, trading *Runtime, _, botID int64) {
				bot, err := trading.loadBot(ctx, botID)
				if err != nil {
					t.Fatalf("load bot: %v", err)
				}
				if err := trading.pause(ctx, bot, pauseCauseStrategyStop, "The strategy requested that the bot stop.", "", "", "warning"); err != nil {
					t.Fatalf("pause: %v", err)
				}
			},
			wantCause: pauseCauseStrategyStop.String(),
		},
		{
			name:   "unsupported exchange",
			bot:    manualLiquidationBotOptions{mode: "paper", exchange: "kraken"},
			market: manualLiquidationMarket{price: "100"},
			live:   true,
			trigger: func(t *testing.T, trading *Runtime, userID, botID int64) {
				if err := trading.Process(ctx, manualLiquidationCommand(protocol.CommandBotStart, userID, botID)); err != nil {
					t.Fatalf("start: %v", err)
				}
			},
			wantCause: pauseCauseUnsupportedExchange.String(),
			wantError: "not supported by the Go runtime",
		},
		{
			name:   "kill switch",
			bot:    manualLiquidationBotOptions{desired: "paused"},
			market: manualLiquidationMarket{price: "100"},
			live:   true,
			trigger: func(t *testing.T, trading *Runtime, userID, botID int64) {
				if err := trading.Process(ctx, manualLiquidationCommand(protocol.CommandKillSwitch, userID, botID)); err != nil {
					t.Fatalf("kill switch: %v", err)
				}
			},
			wantCause: pauseCauseKillSwitch.String(),
			wantError: "Kill-switch activated",
		},
	}

	for _, tc := range cases {
		tc := tc
		t.Run(tc.name, func(t *testing.T) {
			userID := insertManualLiquidationUser(t, ctx, pool)
			botID := insertManualLiquidationBot(t, ctx, pool, userID, tc.bot)
			fake := newRecordingExchange()
			seedManualLiquidationOrders(t, ctx, pool, fake, botID, tc.bot.mode)

			options := []Option{
				WithLiveTrading(tc.live),
				WithLiveClients(func(context.Context, *Bot) (exchange.Client, error) {
					return fake, nil
				}),
			}
			if tc.clock != nil {
				options = append(options, WithNow(tc.clock.Now))
			}
			trading := New(pool, tc.market, nil, options...)

			beforeBase, beforeQuote := manualLiquidationInventories(t, ctx, pool, botID)
			if tc.configure != nil {
				tc.configure(t, fake)
			}
			tc.trigger(t, trading, userID, botID)

			assertManualLiquidationPolicy(t, ctx, pool, fake, botID, beforeBase, beforeQuote, tc.wantCause, tc.wantError)
		})
	}
}

func runTick(t *testing.T, ctx context.Context, trading *Runtime, botID int64) {
	t.Helper()
	if err := trading.TickBot(ctx, botID); err != nil {
		t.Fatalf("tick: %v", err)
	}
}

func runReconcile(t *testing.T, ctx context.Context, trading *Runtime) {
	t.Helper()
	if err := trading.Reconcile(ctx); err != nil {
		t.Fatalf("reconcile: %v", err)
	}
}

type manualLiquidationClock struct {
	mu  sync.Mutex
	now time.Time
}

func (c *manualLiquidationClock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.now
}

func (c *manualLiquidationClock) Advance(duration time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()

	c.now = c.now.Add(duration)
}

func manualLiquidationCommand(commandType string, userID, botID int64) protocol.Envelope {
	payload, _ := json.Marshal(map[string]any{"bot_id": botID, "user_id": userID})

	return protocol.Envelope{
		V:       protocol.Version,
		ID:      fmt.Sprintf("manual-liquidation-%d", time.Now().UnixNano()),
		Type:    commandType,
		TS:      time.Now().UTC().Format(time.RFC3339),
		UserID:  userID,
		BotID:   botID,
		Payload: payload,
	}
}
