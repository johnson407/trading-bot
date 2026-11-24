package backtest_test

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/backtest"
	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/goldenfixture"
)

// TestStoreExecuteRunIntegration exercises the PostgreSQL read/write path. It
// is opt-in because the default Go suite has no database:
//
//	BACKTEST_TEST_DSN='postgres://user:pass@host:5432/trading_bot_test?sslmode=disable' \
//	  go test ./internal/backtest -run TestStoreExecuteRunIntegration
func TestStoreExecuteRunIntegration(t *testing.T) {
	dsn := os.Getenv("BACKTEST_TEST_DSN")
	if dsn == "" {
		t.Skip("set BACKTEST_TEST_DSN to run the PostgreSQL store test")
	}

	ctx := context.Background()
	pool, err := pgxpool.New(ctx, dsn)
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	defer pool.Close()

	var fixture replayFixture
	if err := goldenfixture.Load("backtest_replay", &fixture); err != nil {
		t.Fatalf("load fixture: %v", err)
	}

	var testCase *replayCase
	for index := range fixture.Cases {
		if fixture.Cases[index].Label == "same_candle_sequential_fills" {
			testCase = &fixture.Cases[index]
			break
		}
	}
	if testCase == nil {
		t.Fatal("fixture case same_candle_sequential_fills not found")
	}

	configuration, snapshot, err := backtest.DecodeSnapshot(testCase.Snapshot)
	if err != nil {
		t.Fatalf("decode snapshot: %v", err)
	}

	const exchange = "integration-test"
	const symbol = "IT/USDT"
	first := time.Date(2020, 1, 1, 0, 0, 0, 0, time.UTC)
	shift := first.Sub(testCase.Candles[0].OpenTime)
	candles := make([]backtest.Candle, 0, len(testCase.Candles))
	for _, candle := range testCase.Candles {
		candle.OpenTime = candle.OpenTime.Add(shift).UTC()
		candles = append(candles, candle)
	}

	snapshot.Bot.Exchange = exchange
	snapshot.Bot.Symbol = symbol
	snapshot.Period.StartAt = first.Format(time.RFC3339)
	snapshot.Period.EndAt = first.Add(time.Duration(len(candles)) * 15 * time.Minute).Format(time.RFC3339)
	rawSnapshot, err := json.Marshal(snapshot)
	if err != nil {
		t.Fatalf("marshal snapshot: %v", err)
	}

	var userID int64
	email := fmt.Sprintf("backtest-integration-%d@example.test", time.Now().UnixNano())
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Backtest integration', $1, 'x', now(), now())
		 RETURNING id`,
		email,
	).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	defer func() {
		_, _ = pool.Exec(ctx, `DELETE FROM users WHERE id = $1`, userID)
	}()

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, symbol, strategy_key, created_at, updated_at)
		 VALUES ($1, 'Backtest integration bot', $2, 'simple-grid', now(), now())
		 RETURNING id`,
		userID, symbol,
	).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}
	defer func() {
		_, _ = pool.Exec(ctx, `DELETE FROM bots WHERE id = $1`, botID)
	}()

	for _, candle := range candles {
		if _, err := pool.Exec(ctx,
			`INSERT INTO market_candles
			 (exchange, symbol, timeframe, open_time, open, high, low, close, volume, created_at, updated_at)
			 VALUES ($1, $2, '15m', $3, $4::numeric, $5::numeric, $6::numeric, $7::numeric, $8::numeric, now(), now())
			 ON CONFLICT (exchange, symbol, timeframe, open_time) DO NOTHING`,
			exchange,
			symbol,
			candle.OpenTime,
			candle.Open.String(),
			candle.High.String(),
			candle.Low.String(),
			candle.Close.String(),
			candle.Volume.String(),
		); err != nil {
			t.Fatalf("insert candle: %v", err)
		}
	}
	defer func() {
		_, _ = pool.Exec(ctx, `DELETE FROM market_candles WHERE exchange = $1 AND symbol = $2`, exchange, symbol)
	}()

	var runID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO backtest_runs
		 (user_id, bot_id, name, exchange, symbol, strategy_key, timeframe, start_at, end_at,
		  capital_allocation, fee_rate, config_snapshot, status, progress, created_at, updated_at)
		 VALUES ($1, $2, 'Integration replay', $3, $4, $5, '15m', $6, $7,
		         $8::numeric, $9::numeric, $10::jsonb, 'pending', 0, now(), now())
		 RETURNING id`,
		userID,
		botID,
		exchange,
		symbol,
		configuration.StrategyKey,
		candles[0].OpenTime,
		candles[len(candles)-1].OpenTime,
		configuration.CapitalAllocation.String(),
		configuration.FeeRate.String(),
		string(rawSnapshot),
	).Scan(&runID); err != nil {
		t.Fatalf("insert run: %v", err)
	}
	defer func() {
		_, _ = pool.Exec(ctx, `DELETE FROM backtest_runs WHERE id = $1`, runID)
	}()

	store := backtest.NewStore(pool, backtest.NewEngine(), 0, nil)
	if err := store.ExecuteRun(ctx, runID); err != nil {
		t.Fatalf("ExecuteRun: %v", err)
	}

	var expected struct {
		Metrics map[string]any `json:"metrics"`
		Fills   []any          `json:"fills"`
	}
	if err := json.Unmarshal(testCase.Expected, &expected); err != nil {
		t.Fatalf("decode expected: %v", err)
	}

	var (
		status      string
		endReason   *string
		progress    int
		candlesDone int
		metrics     []byte
		equity      []byte
	)
	if err := pool.QueryRow(ctx,
		`SELECT status, end_reason, progress, candles_processed, metrics, equity_curve
		 FROM backtest_runs WHERE id = $1`,
		runID,
	).Scan(&status, &endReason, &progress, &candlesDone, &metrics, &equity); err != nil {
		t.Fatalf("read run: %v", err)
	}

	if status != backtest.StatusCompleted {
		t.Fatalf("status = %q, want completed", status)
	}
	if progress != 100 {
		t.Fatalf("progress = %d, want 100", progress)
	}
	if endReason == nil || *endReason != expected.Metrics["end_reason"] {
		t.Fatalf("end_reason = %v, want %v", endReason, expected.Metrics["end_reason"])
	}
	if candlesDone != len(candles) {
		t.Fatalf("candles_processed = %d, want %d", candlesDone, len(candles))
	}

	var storedMetrics map[string]any
	if err := json.Unmarshal(metrics, &storedMetrics); err != nil {
		t.Fatalf("decode metrics: %v", err)
	}
	for _, key := range []string{"fill_count", "final_equity", "fees", "return_percent"} {
		if storedMetrics[key] != expected.Metrics[key] {
			t.Fatalf("metrics[%s] = %v, want %v", key, storedMetrics[key], expected.Metrics[key])
		}
	}

	var storedEquity []map[string]any
	if err := json.Unmarshal(equity, &storedEquity); err != nil {
		t.Fatalf("decode equity: %v", err)
	}
	if len(storedEquity) == 0 {
		t.Fatal("equity_curve is empty")
	}

	var fills int
	if err := pool.QueryRow(ctx,
		`SELECT count(*) FROM backtest_fills WHERE backtest_run_id = $1`,
		runID,
	).Scan(&fills); err != nil {
		t.Fatalf("count fills: %v", err)
	}
	if fills != len(expected.Fills) {
		t.Fatalf("fills = %d, want %d", fills, len(expected.Fills))
	}

	var filledSequence int
	var price, quantity, fee string
	if err := pool.QueryRow(ctx,
		`SELECT sequence, price::text, quantity::text, fee::text
		 FROM backtest_fills WHERE backtest_run_id = $1 ORDER BY sequence LIMIT 1`,
		runID,
	).Scan(&filledSequence, &price, &quantity, &fee); err != nil {
		t.Fatalf("read fill: %v", err)
	}
	if filledSequence != 1 {
		t.Fatalf("first fill sequence = %d, want 1", filledSequence)
	}
	if price == "" || quantity == "" || fee == "" {
		t.Fatal("fill decimals are empty")
	}

	var badRunID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO backtest_runs
		 (user_id, bot_id, name, exchange, symbol, strategy_key, timeframe, start_at, end_at,
		  capital_allocation, fee_rate, config_snapshot, status, progress, created_at, updated_at)
		 VALUES ($1, $2, 'Integration failure', $3, $4, 'missing-strategy', '15m', $5, $6,
		         1000, 0.001, '{"strategy":{"key":"missing-strategy"}}'::jsonb, 'pending', 0, now(), now())
		 RETURNING id`,
		userID,
		botID,
		exchange,
		symbol,
		candles[0].OpenTime,
		candles[len(candles)-1].OpenTime,
	).Scan(&badRunID); err != nil {
		t.Fatalf("insert failing run: %v", err)
	}
	defer func() {
		_, _ = pool.Exec(ctx, `DELETE FROM backtest_runs WHERE id = $1`, badRunID)
	}()

	if err := store.ExecuteRun(ctx, badRunID); err != nil {
		t.Fatalf("ExecuteRun(failure): %v", err)
	}

	var failedStatus, failedMessage string
	if err := pool.QueryRow(ctx,
		`SELECT status, error FROM backtest_runs WHERE id = $1`,
		badRunID,
	).Scan(&failedStatus, &failedMessage); err != nil {
		t.Fatalf("read failed run: %v", err)
	}
	if failedStatus != backtest.StatusFailed {
		t.Fatalf("failing run status = %q, want failed", failedStatus)
	}
	if !strings.Contains(failedMessage, "Unknown strategy") {
		t.Fatalf("failed message = %q, want the strategy error", failedMessage)
	}

	if err := store.ExecuteRun(ctx, badRunID); err != nil {
		t.Fatalf("ExecuteRun(duplicate): %v", err)
	}

	var duplicateStatus string
	if err := pool.QueryRow(ctx,
		`SELECT status FROM backtest_runs WHERE id = $1`,
		badRunID,
	).Scan(&duplicateStatus); err != nil {
		t.Fatalf("read duplicate run: %v", err)
	}
	if duplicateStatus != backtest.StatusFailed {
		t.Fatalf("duplicate command changed status to %q", duplicateStatus)
	}
}

type staticFetcher struct {
	candles []backtest.Candle
}

func decimalOf(value string) decimal.Decimal {
	return decimal.MustParse(value)
}

func (f staticFetcher) Fetch(context.Context, backtest.Configuration) ([]backtest.Candle, error) {
	return f.candles, nil
}

// TestStoreBackfillsEmptyCandleCache proves that a run over an empty cache
// fetches its range through the public fetcher, persists the candles, and
// completes without a PHP writer.
func TestStoreBackfillsEmptyCandleCache(t *testing.T) {
	dsn := os.Getenv("BACKTEST_TEST_DSN")
	if dsn == "" {
		t.Skip("set BACKTEST_TEST_DSN to run the PostgreSQL store test")
	}

	ctx := context.Background()
	pool, err := pgxpool.New(ctx, dsn)
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	defer pool.Close()

	const (
		exchange = "backfill-test"
		symbol   = "BF/USDT"
	)
	first := time.Date(2021, 1, 1, 0, 0, 0, 0, time.UTC)
	candles := make([]backtest.Candle, 0, 24)
	for index := 0; index < 24; index++ {
		at := first.Add(time.Duration(index) * 15 * time.Minute)
		candles = append(candles, backtest.Candle{
			OpenTime: at, Open: decimalOf("100"), High: decimalOf("100"),
			Low: decimalOf("100"), Close: decimalOf("100"), Volume: decimalOf("1"),
		})
	}

	email := fmt.Sprintf("backfill-integration-%d@example.test", time.Now().UnixNano())
	var userID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO users (name, email, password, created_at, updated_at)
		 VALUES ('Backfill integration', $1, 'x', now(), now()) RETURNING id`, email).Scan(&userID); err != nil {
		t.Fatalf("insert user: %v", err)
	}
	defer func() { _, _ = pool.Exec(ctx, `DELETE FROM users WHERE id=$1`, userID) }()

	var botID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO bots (user_id, name, symbol, strategy_key, created_at, updated_at)
		 VALUES ($1, 'Backfill bot', $2, 'simple-grid', now(), now()) RETURNING id`,
		userID, symbol).Scan(&botID); err != nil {
		t.Fatalf("insert bot: %v", err)
	}
	defer func() {
		_, _ = pool.Exec(ctx, `DELETE FROM market_candles WHERE exchange=$1 AND symbol=$2`, exchange, symbol)
	}()

	maxOpen := 20
	snapshot := backtest.Snapshot{
		Bot: backtest.SnapshotBot{
			ID: int(botID), Name: "Backfill bot", Exchange: exchange, Symbol: symbol,
			StrategyKey: "simple-grid", CapitalAllocation: "1000", FeeRate: "0.001",
		},
		Strategy: backtest.SnapshotStrategy{Key: "simple-grid", Configuration: map[string]any{
			"center_price": "100", "levels_per_side": float64(2), "spacing_type": "arithmetic",
			"spacing_value": "10", "order_quantity": "1",
		}},
		Risk:   backtest.SnapshotRisk{MaxPositionNotional: "1000", MaxOpenOrders: &maxOpen, DailyLossLimit: "100"},
		Period: backtest.SnapshotPeriod{Timeframe: "15m", StartAt: first.Format(time.RFC3339), EndAt: first.Add(6 * time.Hour).Format(time.RFC3339)},
	}
	raw, err := json.Marshal(snapshot)
	if err != nil {
		t.Fatalf("marshal snapshot: %v", err)
	}

	var runID int64
	if err := pool.QueryRow(ctx,
		`INSERT INTO backtest_runs
		 (user_id, bot_id, name, exchange, symbol, strategy_key, timeframe, start_at, end_at,
		  capital_allocation, fee_rate, config_snapshot, status, progress, created_at, updated_at)
		 VALUES ($1, $2, 'Backfill run', $3, $4, 'simple-grid', '15m', $5, $6,
		         '1000', '0.001', $7::jsonb, 'pending', 0, now(), now()) RETURNING id`,
		userID, botID, exchange, symbol, first, first.Add(6*time.Hour), string(raw)).Scan(&runID); err != nil {
		t.Fatalf("insert run: %v", err)
	}
	defer func() { _, _ = pool.Exec(ctx, `DELETE FROM backtest_runs WHERE id=$1`, runID) }()

	store := backtest.NewStore(pool, backtest.NewEngine(), 0, nil).WithCandleFetcher(staticFetcher{candles: candles})
	if err := store.ExecuteRun(ctx, runID); err != nil {
		t.Fatalf("ExecuteRun: %v", err)
	}

	var status string
	var processed int
	var runError *string
	if err := pool.QueryRow(ctx,
		`SELECT status, candles_processed, error FROM backtest_runs WHERE id=$1`, runID).Scan(&status, &processed, &runError); err != nil {
		t.Fatalf("read run: %v", err)
	}
	if status != backtest.StatusCompleted || processed != len(candles) {
		message := ""
		if runError != nil {
			message = *runError
		}
		t.Fatalf("run = %s/%d (%s), want completed/%d", status, processed, message, len(candles))
	}

	var cached int
	if err := pool.QueryRow(ctx,
		`SELECT count(*) FROM market_candles WHERE exchange=$1 AND symbol=$2`, exchange, symbol).Scan(&cached); err != nil {
		t.Fatalf("count cache: %v", err)
	}
	if cached != len(candles) {
		t.Fatalf("cached candles = %d, want %d", cached, len(candles))
	}
}
