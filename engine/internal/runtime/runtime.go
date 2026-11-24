// Package runtime owns the Go trading runtime: control commands, the due-check
// tick scheduler, grid persistence, paper fills, and runtime risk guards.
//
// Live execution is intentionally not implemented in this build. A live bot is
// paused with a clear error instead of placing an order; Laravel keeps the
// emergency local cancellation path for live orders.
package runtime

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"sync"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/protocol"
	"github.com/johnson407/trading-bot/engine/internal/tick"
)

const (
	liveDisabledMessage = "Live trading is not available in the Go engine yet. The bot was paused."
	defaultMaxOpen      = 20
	defaultMaxNotional  = "1000"
	defaultDailyLoss    = "100"
)

// Market returns the public last price for a symbol.
type Market interface {
	Last(ctx context.Context, exchange, symbol string) (decimal.Decimal, error)
}

// Runtime is a PostgreSQL-backed trading runtime.
type Runtime struct {
	pool    *pgxpool.Pool
	market  Market
	logger  *slog.Logger
	now     func() time.Time
	started sync.Map
}

// New builds a runtime. A nil logger discards logs.
func New(pool *pgxpool.Pool, market Market, logger *slog.Logger) *Runtime {
	if logger == nil {
		logger = slog.New(slog.NewTextHandler(discardWriter{}, nil))
	}

	return &Runtime{pool: pool, market: market, logger: logger, now: func() time.Time { return time.Now().UTC() }}
}

type discardWriter struct{}

func (discardWriter) Write(p []byte) (int, error) { return len(p), nil }

// Process handles a v1 control command. Backtest commands stay in the
// dedicated backtest consumer.
func (r *Runtime) Process(ctx context.Context, command protocol.Envelope) error {
	switch command.Type {
	case protocol.CommandBotStart:
		return r.setStatus(ctx, command, "running", false)
	case protocol.CommandBotPause:
		return r.setStatus(ctx, command, "paused", false)
	case protocol.CommandBotStop:
		return r.setStatus(ctx, command, "stopped", true)
	case protocol.CommandBotConfig:
		return r.resetStrategyState(ctx, command)
	case protocol.CommandOrderCancel:
		return r.cancelOrder(ctx, command)
	case protocol.CommandKillSwitch:
		return r.killSwitch(ctx, command)
	default:
		return nil
	}
}

func (r *Runtime) setStatus(ctx context.Context, command protocol.Envelope, status string, cancel bool) error {
	if command.BotID <= 0 {
		return fmt.Errorf("bot command is missing bot_id")
	}

	if status == "running" {
		return withBotLock(ctx, command.BotID, func(ctx context.Context) error {
			return r.applyStatus(ctx, command, status, cancel)
		})
	}

	return runBotCommand(ctx, func(ctx context.Context) error {
		return r.applyStatus(ctx, command, status, cancel)
	})
}

func (r *Runtime) applyStatus(ctx context.Context, command protocol.Envelope, status string, cancel bool) error {
	bot, err := r.loadBot(ctx, command.BotID)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		return err
	}
	if bot.UserID != command.UserID {
		return nil
	}

	if status == "running" {
		if bot.DesiredStatus != "running" {
			return nil
		}
		message := ""
		switch {
		case bot.live():
			message = liveDisabledMessage
		case bot.Exchange != "binance":
			message = fmt.Sprintf("Exchange %s is not supported by the Go runtime yet. The bot was paused.", bot.Exchange)
		}
		if message != "" {
			_, err := r.pool.Exec(ctx,
				`UPDATE bots SET status = 'paused', last_error = $2, updated_at = now() WHERE id = $1`,
				bot.ID, message,
			)
			return err
		}
		if bot.StrategyReset {
			if _, err := r.pool.Exec(ctx, `UPDATE strategy_configs SET state = NULL, updated_at = now() WHERE bot_id = $1`, bot.ID); err != nil {
				return err
			}
			if _, err := r.pool.Exec(ctx, `UPDATE bots SET strategy_reset_pending = false, updated_at = now() WHERE id = $1`, bot.ID); err != nil {
				return err
			}
		}
	}

	// A stopped bot may be started again: desired_status is the intent and
	// the engine acknowledges it here. Pause/stop remain idempotent so a
	// retried command still performs the cancellation work. Starting also
	// refreshes stale timestamps so the reconciler does not pause the bot
	// before its first due tick.
	if status == "running" {
		_, err = r.pool.Exec(ctx,
			`UPDATE bots
			    SET status = 'running', last_tick_at = NULL, last_successful_tick_at = now(),
			        consecutive_failures = 0, last_error = NULL, updated_at = now()
			  WHERE id = $1 AND user_id = $2`,
			command.BotID, command.UserID,
		)
	} else {
		_, err = r.pool.Exec(ctx,
			`UPDATE bots SET status = $2, updated_at = now() WHERE id = $1 AND user_id = $3`,
			command.BotID, status, command.UserID,
		)
	}
	if err != nil {
		return fmt.Errorf("update bot status: %w", err)
	}

	if cancel {
		if _, err := r.cancelOpenOrders(ctx, command.UserID, command.BotID, "bot.stop"); err != nil {
			return err
		}
	}

	return nil
}

func (r *Runtime) resetStrategyState(ctx context.Context, command protocol.Envelope) error {
	if command.BotID <= 0 {
		return fmt.Errorf("bot.config is missing bot_id")
	}

	return withBotLock(ctx, command.BotID, func(ctx context.Context) error {
		_, err := r.pool.Exec(ctx,
			`UPDATE strategy_configs SET state = NULL, updated_at = now()
			 WHERE bot_id = $1
			   AND EXISTS (SELECT 1 FROM bots WHERE bots.id = $1 AND bots.user_id = $2)`,
			command.BotID, command.UserID,
		)
		if err != nil {
			return fmt.Errorf("reset strategy state: %w", err)
		}
		_, err = r.pool.Exec(ctx, `UPDATE bots SET strategy_reset_pending = false, updated_at = now() WHERE id = $1 AND user_id = $2`, command.BotID, command.UserID)

		return err
	})
}

func (r *Runtime) cancelOrder(ctx context.Context, command protocol.Envelope) error {
	orderID, ok := command.PayloadInt("order_id")
	if !ok || orderID <= 0 {
		return fmt.Errorf("order.cancel is missing order_id")
	}
	if command.BotID <= 0 {
		return fmt.Errorf("order.cancel is missing bot_id")
	}

	return runBotCommand(ctx, func(ctx context.Context) error {
		var botID int64
		var gridLevelID *int64
		var mode string
		err := r.pool.QueryRow(ctx,
			`SELECT orders.bot_id, orders.grid_level_id, orders.mode
			 FROM orders JOIN bots ON bots.id = orders.bot_id
			 WHERE orders.id = $1 AND orders.bot_id = $2 AND bots.user_id = $3
			   AND orders.status IN ('pending','open','partially_filled')`,
			orderID, command.BotID, command.UserID,
		).Scan(&botID, &gridLevelID, &mode)
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		if err != nil {
			return fmt.Errorf("load order: %w", err)
		}
		if mode == "live" {
			return fmt.Errorf("live order cancellation is not available in the Go engine yet; use stop or the kill switch")
		}

		if _, err := r.pool.Exec(ctx,
			`UPDATE orders SET status = 'canceled', closed_at = now(), updated_at = now()
			 WHERE id = $1 AND status IN ('pending','open','partially_filled')`,
			orderID,
		); err != nil {
			return fmt.Errorf("cancel order: %w", err)
		}

		if gridLevelID != nil {
			_, _ = r.pool.Exec(ctx, `UPDATE grid_levels SET state = 'canceled', updated_at = now() WHERE id = $1`, *gridLevelID)
		}

		r.activity(ctx, command.UserID, botID, "order.canceled", "info", "Order canceled by operator.", nil)

		return nil
	})
}

func (r *Runtime) killSwitch(ctx context.Context, command protocol.Envelope) error {
	if command.UserID <= 0 {
		return fmt.Errorf("kill_switch is missing user_id")
	}

	rows, err := r.pool.Query(ctx,
		`SELECT id FROM bots WHERE user_id = $1 AND status <> 'stopped' AND desired_status = 'paused' ORDER BY id`,
		command.UserID,
	)
	if err != nil {
		return fmt.Errorf("load kill-switch bots: %w", err)
	}
	defer rows.Close()

	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return err
		}
		ids = append(ids, id)
	}
	if err := rows.Err(); err != nil {
		return err
	}

	var failures []error
	var completed []int64
	for _, id := range ids {
		botID := id
		if err := runBotCommand(ctx, func(ctx context.Context) error {
			message := "Kill-switch activated; bot paused and open orders canceled."
			if _, err := r.pool.Exec(ctx,
				`UPDATE bots SET status = 'paused', last_error = $2, updated_at = now() WHERE id = $1`,
				botID, message,
			); err != nil {
				return fmt.Errorf("pause bot %d: %w", botID, err)
			}
			if _, err := r.cancelOpenOrders(ctx, command.UserID, botID, "kill_switch"); err != nil {
				return fmt.Errorf("cancel bot %d orders: %w", botID, err)
			}
			if _, err := r.pool.Exec(ctx, `UPDATE bots SET kill_switch_requested_at = NULL, updated_at = now() WHERE id = $1`, botID); err != nil {
				return err
			}

			return nil
		}); err != nil {
			failures = append(failures, err)
		} else {
			completed = append(completed, botID)
		}
	}

	for _, id := range completed {
		r.riskEvent(ctx, command.UserID, id, "risk.kill_switch_activated", "critical", "Kill-switch activated; bot paused and open orders canceled.", nil)
	}

	return errors.Join(failures...)
}

// RunningCount reports bots currently claimed as running for the heartbeat.
func (r *Runtime) RunningCount(ctx context.Context) int {
	var count int
	if err := r.pool.QueryRow(ctx, `SELECT count(*) FROM bots WHERE status = 'running'`).Scan(&count); err != nil {
		return 0
	}

	return count
}

// RunScheduler performs the due-check every second until ctx is canceled.
func (r *Runtime) RunScheduler(ctx context.Context) {
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			if err := r.reconcile(ctx); err != nil {
				r.logger.Error("runtime reconciliation failed", "error_type", fmt.Sprintf("%T", err))
			}
			if err := r.runDue(ctx); err != nil {
				r.logger.Error("tick scheduler failed", "error_type", fmt.Sprintf("%T", err))
			}
		}
	}
}

// reconcile applies desired_status that a lost command or restart left behind,
// cancels orders for stopped bots, and fails bots that stopped ticking.
// Reconcile applies desired-status drift, cancellation retries, and stale-tick
// pauses once. The scheduler calls it every second; tests use it directly.
func (r *Runtime) Reconcile(ctx context.Context) error {
	return r.reconcile(ctx)
}

func (r *Runtime) reconcile(ctx context.Context) error {
	rows, err := r.pool.Query(ctx,
		`SELECT id FROM bots
		 WHERE (status = 'running' AND desired_status IN ('paused','stopped'))
		    OR (status = 'paused' AND desired_status = 'stopped')
		    OR (status = 'stopped' AND desired_status = 'running')
		    OR (status = 'idle' AND desired_status = 'running')
		    OR kill_switch_requested_at IS NOT NULL
		    OR cancel_retry_requested = true`,
	)
	if err != nil {
		return fmt.Errorf("load desired-status drift: %w", err)
	}
	defer rows.Close()

	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return err
		}
		ids = append(ids, id)
	}
	if err := rows.Err(); err != nil {
		return err
	}

	for _, id := range ids {
		botID := id
		if err := withBotLock(ctx, botID, func(ctx context.Context) error {
			return r.applyDesired(ctx, botID)
		}); err != nil {
			r.logger.Error("desired-status reconciliation failed", "bot_id", botID, "error_type", fmt.Sprintf("%T", err))
		}
	}

	// Retry cancellation for stopped bots whose previous stop attempt failed
	// after the status update committed.
	stopped, err := r.pool.Query(ctx,
		`SELECT DISTINCT b.id, b.user_id FROM bots b JOIN orders o ON o.bot_id = b.id
		 WHERE b.status = 'stopped' AND o.status IN ('pending','open','partially_filled')`,
	)
	if err != nil {
		return fmt.Errorf("load stopped bots with open orders: %w", err)
	}
	defer stopped.Close()

	var stoppedBots []struct{ id, userID int64 }
	for stopped.Next() {
		var id, userID int64
		if err := stopped.Scan(&id, &userID); err != nil {
			return err
		}
		stoppedBots = append(stoppedBots, struct{ id, userID int64 }{id, userID})
	}
	if err := stopped.Err(); err != nil {
		return err
	}

	for _, entry := range stoppedBots {
		botID, userID := entry.id, entry.userID
		if err := withBotLock(ctx, botID, func(ctx context.Context) error {
			_, err := r.cancelOpenOrders(ctx, userID, botID, "bot.stop")
			return err
		}); err != nil {
			r.logger.Error("stopped-bot cancellation reconciliation failed", "bot_id", botID, "error_type", fmt.Sprintf("%T", err))
		}
	}

	stale, err := r.pool.Query(ctx,
		`SELECT id FROM bots
		 WHERE status = 'running'
		   AND COALESCE(last_successful_tick_at, last_tick_at, created_at)
		       < now() - make_interval(secs => GREATEST(180, tick_interval_seconds + 30))`,
	)
	if err != nil {
		return fmt.Errorf("load stale bots: %w", err)
	}
	defer stale.Close()

	ids = ids[:0]
	for stale.Next() {
		var id int64
		if err := stale.Scan(&id); err != nil {
			return err
		}
		ids = append(ids, id)
	}
	if err := stale.Err(); err != nil {
		return err
	}

	for _, id := range ids {
		botID := id
		if err := withBotLock(ctx, botID, func(ctx context.Context) error {
			bot, err := r.loadBot(ctx, botID)
			if err != nil {
				if errors.Is(err, pgx.ErrNoRows) {
					return nil
				}
				return err
			}
			if bot.Status != "running" {
				return nil
			}
			r.pause(ctx, bot, "No successful tick within the stale-tick threshold. Bot paused and open orders canceled.", "bot.stale", "risk.stale_tick", "critical")
			return nil
		}); err != nil {
			r.logger.Error("stale-bot reconciliation failed", "bot_id", botID, "error_type", fmt.Sprintf("%T", err))
		}
	}

	return nil
}

func (r *Runtime) applyDesired(ctx context.Context, botID int64) error {
	bot, err := r.loadBot(ctx, botID)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		return err
	}
	if bot.KillSwitchAt != nil {
		if _, err := r.pool.Exec(ctx, `UPDATE bots SET status = 'paused', updated_at = now() WHERE id = $1`, bot.ID); err != nil {
			return err
		}
		if _, err := r.cancelOpenOrders(ctx, bot.UserID, bot.ID, "kill_switch"); err != nil {
			return err
		}
		if _, err := r.pool.Exec(ctx, `UPDATE bots SET kill_switch_requested_at = NULL, updated_at = now() WHERE id = $1`, bot.ID); err != nil {
			return err
		}
		return nil
	}

	if bot.CancelRetry {
		if _, err := r.cancelOpenOrders(ctx, bot.UserID, bot.ID, "cancel_retry"); err != nil {
			return err
		}
		if _, err := r.pool.Exec(ctx, `UPDATE bots SET cancel_retry_requested = false, updated_at = now() WHERE id = $1`, bot.ID); err != nil {
			return err
		}
	}

	if bot.StrategyReset {
		if _, err := r.pool.Exec(ctx, `UPDATE strategy_configs SET state = NULL, updated_at = now() WHERE bot_id = $1`, bot.ID); err != nil {
			return err
		}
		if _, err := r.pool.Exec(ctx, `UPDATE bots SET strategy_reset_pending = false, updated_at = now() WHERE id = $1`, bot.ID); err != nil {
			return err
		}
	}

	if bot.Status == "paused" && bot.DesiredStatus == "running" {
		return nil
	}

	if bot.Status == bot.DesiredStatus {
		return nil
	}

	switch bot.DesiredStatus {
	case "running":
		if bot.live() {
			return r.markPaused(ctx, bot, liveDisabledMessage)
		}
		if bot.Exchange != "binance" {
			return r.markPaused(ctx, bot, fmt.Sprintf("Exchange %s is not supported by the Go runtime yet. The bot was paused.", bot.Exchange))
		}
		_, err := r.pool.Exec(ctx, `UPDATE bots SET status = 'running', last_error = NULL, updated_at = now() WHERE id = $1`, bot.ID)
		return err
	case "paused":
		_, err := r.pool.Exec(ctx, `UPDATE bots SET status = 'paused', updated_at = now() WHERE id = $1`, bot.ID)
		if err != nil {
			return err
		}
		return nil
	case "stopped":
		if _, err := r.pool.Exec(ctx, `UPDATE bots SET status = 'stopped', updated_at = now() WHERE id = $1`, bot.ID); err != nil {
			return err
		}
		_, err = r.cancelOpenOrders(ctx, bot.UserID, bot.ID, "bot.stop")
		return err
	}

	return nil
}

func (r *Runtime) markPaused(ctx context.Context, bot *Bot, message string) error {
	_, err := r.pool.Exec(ctx, `UPDATE bots SET status = 'paused', last_error = $2, updated_at = now() WHERE id = $1`, bot.ID, message)
	return err
}

func (r *Runtime) runDue(ctx context.Context) error {
	rows, err := r.pool.Query(ctx,
		`SELECT id FROM bots
		 WHERE status = 'running'
		   AND (last_tick_at IS NULL OR last_tick_at <= now() - make_interval(secs => GREATEST(tick_interval_seconds, 1)))
		 ORDER BY id`,
	)
	if err != nil {
		return fmt.Errorf("load due bots: %w", err)
	}
	defer rows.Close()

	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return err
		}
		ids = append(ids, id)
	}

	for _, id := range ids {
		if _, loaded := r.started.LoadOrStore(id, struct{}{}); loaded {
			continue
		}

		go func(botID int64) {
			defer r.started.Delete(botID)
			defer func() {
				if recovered := recover(); recovered != nil {
					r.logger.Error("bot tick panicked", "bot_id", botID)
				}
			}()

			tickCtx, cancel := context.WithTimeout(ctx, 2*time.Minute)
			defer cancel()
			if err := r.TickBot(tickCtx, botID); err != nil {
				r.logger.Error("bot tick failed", "bot_id", botID, "error_type", fmt.Sprintf("%T", err))
			}
		}(id)
	}

	return nil
}

// Source adapts the runtime to tick.Source for callers that want the pure
// due-check helper.
func (r *Runtime) Source(ctx context.Context) ([]tick.Bot, error) {
	rows, err := r.pool.Query(ctx,
		`SELECT id, status, tick_interval_seconds, last_tick_at FROM bots WHERE status = 'running'`,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var bots []tick.Bot
	for rows.Next() {
		var bot tick.Bot
		if err := rows.Scan(&bot.ID, &bot.Status, &bot.TickIntervalSeconds, &bot.LastTickAt); err != nil {
			return nil, err
		}
		bots = append(bots, bot)
	}

	return bots, rows.Err()
}

func (r *Runtime) activity(ctx context.Context, userID, botID int64, typ, severity, message string, context map[string]any) {
	encoded, _ := json.Marshal(context)
	_, err := r.pool.Exec(ctx,
		`INSERT INTO activity_logs (user_id, bot_id, type, severity, message, context, created_at)
		 VALUES ($1, NULLIF($2, 0), $3, $4, $5, $6::jsonb, now())`,
		userID, botID, typ, severity, message, string(encoded),
	)
	if err != nil {
		r.logger.Error("record activity failed", "type", typ, "error_type", fmt.Sprintf("%T", err))
	}
}

func (r *Runtime) riskEvent(ctx context.Context, userID, botID int64, typ, severity, message string, context map[string]any) {
	encoded, _ := json.Marshal(context)
	_, err := r.pool.Exec(ctx,
		`INSERT INTO risk_events (user_id, bot_id, type, severity, message, context, created_at)
		 VALUES ($1, NULLIF($2, 0), $3, $4, $5, $6::jsonb, now())`,
		userID, botID, typ, severity, message, string(encoded),
	)
	if err != nil {
		r.logger.Error("record risk event failed", "type", typ, "error_type", fmt.Sprintf("%T", err))
	}
}

func fixed(value decimal.Decimal) string {
	return value.String()
}

func parseOptional(raw *string) *string {
	if raw == nil {
		return nil
	}
	trimmed := strings.TrimSpace(*raw)
	if trimmed == "" {
		return nil
	}
	return &trimmed
}

func boolPointer(value bool) *bool { return &value }
