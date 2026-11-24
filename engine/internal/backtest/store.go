package backtest

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"math"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

// Status values persisted in backtest_runs.status.
const (
	StatusPending   = "pending"
	StatusRunning   = "running"
	StatusCompleted = "completed"
	StatusFailed    = "failed"
)

// FailedMessageFallback mirrors RunBacktest::safeMessage.
const FailedMessageFallback = "The backtest failed."

// MaxFailedMessageLength mirrors the 500 character cap in RunBacktest.
const MaxFailedMessageLength = 500

// DefaultJobTimeout mirrors config('backtest.job_timeout').
const DefaultJobTimeout = 600 * time.Second

// Store executes queued backtest runs against PostgreSQL. Laravel creates the
// rows; the engine claims them, replays candles, and writes the results.
type Store struct {
	pool    *pgxpool.Pool
	engine  *Engine
	timeout time.Duration
	logger  *slog.Logger
	fetcher CandleFetcher
}

// NewStore creates a PostgreSQL-backed backtest runner. timeout is the hard
// per-run replay deadline mirroring config('backtest.job_timeout').
func NewStore(pool *pgxpool.Pool, engine *Engine, timeout time.Duration, logger *slog.Logger) *Store {
	if logger == nil {
		logger = slog.New(slog.NewTextHandler(io.Discard, nil))
	}
	if timeout <= 0 {
		timeout = DefaultJobTimeout
	}

	return &Store{
		pool:    pool,
		engine:  engine,
		timeout: timeout,
		logger:  logger,
	}
}

// WithCandleFetcher adds public candle backfill for cache misses.
func (s *Store) WithCandleFetcher(fetcher CandleFetcher) *Store {
	s.fetcher = fetcher
	return s
}

// ExecuteRun claims a pending run and replays it. A duplicate command (the run
// is no longer pending) is ignored so Redis redelivery is safe.
func (s *Store) ExecuteRun(ctx context.Context, runID int64) (err error) {
	snapshot, claimed, claimErr := s.claimRun(ctx, runID)
	if claimErr != nil {
		return claimErr
	}
	if !claimed {
		return nil
	}

	defer func() {
		if recovered := recover(); recovered != nil {
			panicErr := fmt.Errorf("backtest panicked: %v", recovered)
			s.logger.Error("backtest panicked", "run_id", runID)
			err = s.failRun(ctx, runID, panicErr)
		}
	}()

	runCtx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()

	configuration, _, err := DecodeSnapshot(snapshot)
	if err != nil {
		return s.failRun(ctx, runID, err)
	}

	candles, err := s.LoadCandles(runCtx, configuration)
	if (err != nil || len(candles) == 0) && s.fetcher != nil {
		fetched, fetchErr := s.fetcher.Fetch(runCtx, configuration)
		if fetchErr == nil && len(fetched) > 0 {
			if persistErr := s.persistCandles(runCtx, configuration, fetched); persistErr != nil {
				return s.failRun(ctx, runID, persistErr)
			}
			candles, err = s.LoadCandles(runCtx, configuration)
		} else if fetchErr != nil {
			err = fetchErr
		}
	}
	if err != nil {
		if errors.Is(err, context.DeadlineExceeded) {
			err = errors.New(StaleRunMessage)
		}
		return s.failRun(ctx, runID, err)
	}

	var progressErr error
	total := len(candles)
	result, runErr := s.engine.RunContext(runCtx, configuration, candles, func(processed int) {
		progress := 0
		if total > 0 {
			progress = int(math.Min(99, math.Floor(float64(processed)/float64(total)*100)))
		}
		_, updateErr := s.pool.Exec(
			runCtx,
			`UPDATE backtest_runs
			 SET candles_processed = $2, progress = $3, updated_at = now()
			 WHERE id = $1 AND status = $4`,
			runID, processed, progress, StatusRunning,
		)
		if updateErr != nil && progressErr == nil {
			progressErr = updateErr
		}
	})
	if runErr == nil {
		runErr = progressErr
	}
	if runErr != nil {
		if errors.Is(runErr, context.DeadlineExceeded) {
			runErr = errors.New(StaleRunMessage)
		}
		return s.failRun(ctx, runID, runErr)
	}

	if err := s.completeRun(ctx, runID, result); err != nil {
		return s.failRun(ctx, runID, err)
	}

	return nil
}

// ExecuteSweep executes every run in a sweep in id order. Runs stay pending
// until claimed, so a queued sweep can wait on the command stream without being
// failed by the stale sweeper. Each run fails independently.
func (s *Store) ExecuteSweep(ctx context.Context, sweepID int64) error {
	rows, err := s.pool.Query(ctx, `SELECT id FROM backtest_runs WHERE sweep_id = $1 ORDER BY id`, sweepID)
	if err != nil {
		return fmt.Errorf("load sweep runs: %w", err)
	}

	var runIDs []int64
	for rows.Next() {
		var runID int64
		if err := rows.Scan(&runID); err != nil {
			rows.Close()
			return fmt.Errorf("scan sweep run: %w", err)
		}
		runIDs = append(runIDs, runID)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return fmt.Errorf("load sweep runs: %w", err)
	}
	if len(runIDs) == 0 {
		return fmt.Errorf("backtest sweep %d has no runs", sweepID)
	}

	for _, runID := range runIDs {
		if err := s.ExecuteRun(ctx, runID); err != nil {
			return err
		}
	}

	return nil
}

// FailStaleRuns fails running runs abandoned by a lost worker and, as an
// orphan guard, pending runs whose command never arrived. Pending runs are
// left alone for the normal backlog window: their commands stay in the Redis
// pending entry list until claimed, so queue delay is not lost-worker
// evidence. After 24 hours a pending run is treated as an orphaned insert or
// dead-lettered command and failed.
func (s *Store) FailStaleRuns(ctx context.Context, cutoff time.Time, message string) (int64, error) {
	tag, err := s.pool.Exec(
		ctx,
		`UPDATE backtest_runs
		 SET status = $1, error = $2, finished_at = now(), updated_at = now()
		 WHERE (status = $3 AND updated_at < $4)
		    OR (status = $5 AND created_at < now() - interval '24 hours')`,
		StatusFailed, message, StatusRunning, cutoff, StatusPending,
	)
	if err != nil {
		return 0, fmt.Errorf("fail stale backtests: %w", err)
	}

	return tag.RowsAffected(), nil
}

// persistCandles upserts public candles into the shared cache.
func (s *Store) persistCandles(ctx context.Context, configuration Configuration, candles []Candle) error {
	for _, candle := range candles {
		if _, err := s.pool.Exec(ctx,
			`INSERT INTO market_candles
			 (exchange, symbol, timeframe, open_time, open, high, low, close, volume, created_at, updated_at)
			 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, now(), now())
			 ON CONFLICT (exchange, symbol, timeframe, open_time) DO UPDATE
			 SET open = EXCLUDED.open, high = EXCLUDED.high, low = EXCLUDED.low,
			     close = EXCLUDED.close, volume = EXCLUDED.volume, updated_at = now()`,
			configuration.Exchange, configuration.Symbol, string(configuration.Timeframe),
			candle.OpenTime.UTC(), candle.Open.String(), candle.High.String(), candle.Low.String(),
			candle.Close.String(), candle.Volume.String(),
		); err != nil {
			return fmt.Errorf("persist candle: %w", err)
		}
	}

	return nil
}

// LoadCandles reads the contiguous candle range for a configuration from
// market_candles. It returns no candles when the requested window is empty and
// a gap error when the cached range is incomplete.
func (s *Store) LoadCandles(ctx context.Context, configuration Configuration) ([]Candle, error) {
	first, last, expected, err := candleWindow(configuration, time.Now().UTC())
	if err != nil {
		return nil, err
	}
	if expected == 0 {
		return nil, nil
	}

	secondsValue, err := configuration.Timeframe.Seconds()
	if err != nil {
		return nil, err
	}
	seconds := int64(secondsValue)

	rows, err := s.pool.Query(
		ctx,
		`SELECT open_time, open::text, high::text, low::text, close::text, volume::text
		 FROM market_candles
		 WHERE exchange = $1 AND symbol = $2 AND timeframe = $3
		   AND open_time >= $4 AND open_time <= $5
		   AND MOD(EXTRACT(EPOCH FROM open_time)::bigint, $6) = 0
		 ORDER BY open_time ASC`,
		configuration.Exchange,
		configuration.Symbol,
		string(configuration.Timeframe),
		first,
		last,
		seconds,
	)
	if err != nil {
		return nil, fmt.Errorf("load candles: %w", err)
	}
	defer rows.Close()

	candles := make([]Candle, 0, expected)
	for rows.Next() {
		var (
			openTime                       time.Time
			open, high, low, close, volume string
		)
		if err := rows.Scan(&openTime, &open, &high, &low, &close, &volume); err != nil {
			return nil, fmt.Errorf("scan candle: %w", err)
		}

		candle, err := DecodeCandle(CandleJSON{
			OpenTime: formatAtom(openTime.UTC()),
			Open:     open,
			High:     high,
			Low:      low,
			Close:    close,
			Volume:   volume,
		})
		if err != nil {
			return nil, fmt.Errorf("decode candle at %s: %w", formatAtom(openTime.UTC()), err)
		}
		candles = append(candles, candle)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("load candles: %w", err)
	}

	if len(candles) == 0 {
		return nil, nil
	}

	if gapAt, missing, ok := candleGap(candles, first, expected, seconds); ok {
		return nil, fmt.Errorf("%s", gapMessage(gapAt, missing))
	}

	return candles, nil
}

func gapMessage(at time.Time, missing int) string {
	noun := "candles"
	if missing == 1 {
		noun = "candle"
	}

	return fmt.Sprintf(
		"Historical data gap at %s (%d %s missing).",
		formatAtom(at),
		missing,
		noun,
	)
}

func candleGap(candles []Candle, first time.Time, expected int, seconds int64) (time.Time, int, bool) {
	step := time.Duration(seconds) * time.Second
	missing := expected - len(candles)
	if missing < 1 {
		missing = 1
	}

	for offset, candle := range candles {
		want := first.Add(time.Duration(offset) * step)
		if candle.OpenTime.Unix() != want.Unix() {
			return want, missing, true
		}
	}

	if len(candles) != expected {
		return first.Add(time.Duration(len(candles)) * step), missing, true
	}

	return time.Time{}, 0, false
}

func (s *Store) claimRun(ctx context.Context, runID int64) ([]byte, bool, error) {
	var snapshot []byte
	err := s.pool.QueryRow(
		ctx,
		`UPDATE backtest_runs
		 SET status = $1, started_at = now(), finished_at = NULL, error = NULL,
		     candles_processed = 0, progress = 0, updated_at = now()
		 WHERE id = $2 AND status = $3
		 RETURNING config_snapshot`,
		StatusRunning, runID, StatusPending,
	).Scan(&snapshot)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, false, nil
	}
	if err != nil {
		return nil, false, fmt.Errorf("claim backtest run: %w", err)
	}

	return snapshot, true, nil
}

func (s *Store) completeRun(ctx context.Context, runID int64, result Result) error {
	metrics, err := json.Marshal(result.Metrics.ToArray())
	if err != nil {
		return fmt.Errorf("encode backtest metrics: %w", err)
	}

	equity := make([]any, 0, len(result.EquityCurve))
	for _, point := range result.EquityCurve {
		equity = append(equity, point.ToArray())
	}
	equityJSON, err := json.Marshal(equity)
	if err != nil {
		return fmt.Errorf("encode backtest equity: %w", err)
	}

	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return fmt.Errorf("begin backtest completion: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	var status string
	if err := tx.QueryRow(
		ctx,
		`SELECT status FROM backtest_runs WHERE id = $1 FOR UPDATE`,
		runID,
	).Scan(&status); err != nil {
		return fmt.Errorf("lock backtest run: %w", err)
	}
	if status != StatusRunning {
		return tx.Commit(ctx)
	}

	if _, err := tx.Exec(ctx, `DELETE FROM backtest_fills WHERE backtest_run_id = $1`, runID); err != nil {
		return fmt.Errorf("clear backtest fills: %w", err)
	}

	for start := 0; start < len(result.Fills); start += 500 {
		end := start + 500
		if end > len(result.Fills) {
			end = len(result.Fills)
		}

		batch := &pgx.Batch{}
		for _, fill := range result.Fills[start:end] {
			var gridLevelSequence any
			if fill.GridLevelSequence != nil {
				gridLevelSequence = *fill.GridLevelSequence
			}
			batch.Queue(
				`INSERT INTO backtest_fills
				 (backtest_run_id, sequence, side, price, quantity, fee, fee_currency,
				  filled_at, grid_level_sequence, created_at, updated_at)
				 VALUES ($1, $2, $3, $4::numeric, $5::numeric, $6::numeric, $7, $8, $9, now(), now())`,
				runID,
				fill.Sequence,
				string(fill.Side),
				fill.Price.String(),
				fill.Quantity.String(),
				fill.Fee.String(),
				fill.FeeCurrency,
				fill.FilledAt.UTC(),
				gridLevelSequence,
			)
		}

		results := tx.SendBatch(ctx, batch)
		for fillIndex := start; fillIndex < end; fillIndex++ {
			if _, err := results.Exec(); err != nil {
				_ = results.Close()
				return fmt.Errorf("insert backtest fill %d: %w", result.Fills[fillIndex].Sequence, err)
			}
		}
		if err := results.Close(); err != nil {
			return fmt.Errorf("insert backtest fills: %w", err)
		}
	}

	if _, err := tx.Exec(
		ctx,
		`UPDATE backtest_runs
		 SET status = $2, end_reason = $3, candles_processed = $4, progress = 100,
		     metrics = $5::jsonb, equity_curve = $6::jsonb, error = NULL,
		     finished_at = now(), updated_at = now()
		 WHERE id = $1 AND status = $7`,
		runID,
		StatusCompleted,
		result.Metrics.EndReason,
		result.Metrics.CandlesProcessed,
		string(metrics),
		string(equityJSON),
		StatusRunning,
	); err != nil {
		return fmt.Errorf("complete backtest run: %w", err)
	}

	if err := tx.Commit(ctx); err != nil {
		return fmt.Errorf("commit backtest completion: %w", err)
	}

	s.logger.Info("backtest completed",
		"run_id", runID,
		"fills", len(result.Fills),
		"candles", result.Metrics.CandlesProcessed,
		"end_reason", result.Metrics.EndReason,
	)

	return nil
}

func (s *Store) failRun(ctx context.Context, runID int64, cause error) error {
	message := SafeMessage(cause)
	writeCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
	defer cancel()

	var lastErr error
retry:
	for attempt := 0; attempt < 3; attempt++ {
		if attempt > 0 {
			select {
			case <-time.After(time.Duration(attempt) * 100 * time.Millisecond):
			case <-writeCtx.Done():
				lastErr = writeCtx.Err()
				break retry
			}
		}

		_, err := s.pool.Exec(
			writeCtx,
			`UPDATE backtest_runs
			 SET status = $2, error = $3, finished_at = now(), updated_at = now()
			 WHERE id = $1 AND status = $4`,
			runID, StatusFailed, message, StatusRunning,
		)
		if err == nil {
			s.logger.Warn("backtest failed",
				"run_id", runID,
				"error_type", fmt.Sprintf("%T", cause),
			)
			return nil
		}
		lastErr = err
	}

	s.logger.Error("failed to mark backtest run failed",
		"run_id", runID,
		"error_type", fmt.Sprintf("%T", lastErr),
	)

	return fmt.Errorf("mark backtest run failed: %w", lastErr)
}

// SafeMessage mirrors RunBacktest::safeMessage: trimmed, fallback, 500 chars.
func SafeMessage(err error) string {
	if err == nil {
		return FailedMessageFallback
	}

	message := strings.TrimSpace(err.Error())
	if message == "" {
		message = FailedMessageFallback
	}

	return truncateRunes(message, MaxFailedMessageLength)
}

func truncateRunes(value string, limit int) string {
	if len([]rune(value)) <= limit {
		return value
	}
	if limit <= 3 {
		return string([]rune(value)[:limit])
	}

	return string([]rune(value)[:limit-3]) + "..."
}

// candleWindow mirrors the coverage window computed by CandleStore::ensure.
// It returns the first and last expected open times and the expected count.
func candleWindow(configuration Configuration, now time.Time) (time.Time, time.Time, int, error) {
	secondsValue, err := configuration.Timeframe.Seconds()
	if err != nil {
		return time.Time{}, time.Time{}, 0, err
	}
	seconds := int64(secondsValue)

	start := configuration.StartAt.UTC()
	firstUnix := ceilToBoundary(start.Unix(), seconds)

	end := configuration.EndAt.UTC()
	if now.Before(end) {
		end = now.UTC()
	}
	lastUnix := floorToBoundary(end.Unix()-seconds, seconds)

	if lastUnix < firstUnix {
		return time.Time{}, time.Time{}, 0, nil
	}

	count := int((lastUnix-firstUnix)/seconds) + 1

	return time.Unix(firstUnix, 0).UTC(), time.Unix(lastUnix, 0).UTC(), count, nil
}

func ceilToBoundary(value, step int64) int64 {
	return ((value + step - 1) / step) * step
}

func floorToBoundary(value, step int64) int64 {
	return (value / step) * step
}

var _ StaleStore = (*Store)(nil)
