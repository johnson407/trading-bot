// Package runtime owns the Go trading runtime: control commands, the due-check
// tick scheduler, grid persistence, paper fills, live order placement and
// reconciliation, and runtime risk guards.
//
// Live execution is gated by TRADING_LIVE_ENABLED. When the gate is closed a
// live bot is paused with a clear risk event instead of placing or falling back
// to paper. Reads and cancellations remain available so existing live orders
// can still be reconciled and removed.
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
	"github.com/johnson407/trading-bot/engine/internal/exchange"
	"github.com/johnson407/trading-bot/engine/internal/protocol"
	"github.com/johnson407/trading-bot/engine/internal/tick"
)

const (
	liveDisabledMessage = "Live trading is disabled by TRADING_LIVE_ENABLED=false. The bot was paused."
	defaultMaxOpen      = 20
	defaultMaxNotional  = "1000"
	defaultDailyLoss    = "100"

	riskEventCooldown = 5 * time.Minute
)

// Option customises the runtime.
type Option func(*Runtime)

// WithLiveTrading passes the TRADING_LIVE_ENABLED gate to the runtime. The
// default is closed; a live bot is paused with a risk event, never converted to
// paper.
func WithLiveTrading(enabled bool) Option {
	return func(r *Runtime) {
		r.liveTrading = enabled
	}
}

// Market returns the public last price for a symbol.
type Market interface {
	Last(ctx context.Context, exchange, symbol string) (decimal.Decimal, error)
}

// LiveClientFactory builds a private exchange client for a bot. Implementations
// decrypt credentials in memory and return a gate-guarded client. A nil client
// or error pauses the live bot; it never falls back to paper.
type LiveClientFactory func(ctx context.Context, bot *Bot) (exchange.Client, error)

// WithLiveClients supplies the private client factory used for live placement,
// cancellation, and reconciliation.
func WithLiveClients(factory LiveClientFactory) Option {
	return func(r *Runtime) {
		r.liveClients = factory
	}
}

// WithOrderSyncInterval sets the live reconciliation cadence. The default is
// five seconds.
func WithOrderSyncInterval(interval time.Duration) Option {
	return func(r *Runtime) {
		if interval > 0 {
			r.orderSyncInterval = interval
		}
	}
}

// WithNow overrides the runtime clock. Tests use it to drive stale-check grace
// and risk-event cooldowns without sleeping.
func WithNow(now func() time.Time) Option {
	return func(r *Runtime) {
		if now != nil {
			r.now = now
		}
	}
}

// OrderCanceler cancels a bot's open orders. It is injectable so tests can
// exercise cancellation failures without an exchange.
type OrderCanceler func(ctx context.Context, userID, botID int64, reason string) error

// WithOrderCanceler overrides open-order cancellation. Production uses the
// runtime's exchange-backed cancelOpenOrders.
func WithOrderCanceler(canceler OrderCanceler) Option {
	return func(r *Runtime) {
		if canceler != nil {
			r.cancelOrders = canceler
		}
	}
}

type riskEventKey struct {
	botID int64
	typ   string
}

// Runtime is a PostgreSQL-backed trading runtime.
type Runtime struct {
	pool               *pgxpool.Pool
	market             Market
	logger             *slog.Logger
	now                func() time.Time
	started            sync.Map
	liveTrading        bool
	liveClients        LiveClientFactory
	orderSyncInterval  time.Duration
	lastOrderSync      map[int64]time.Time
	orderSyncNotBefore map[int64]time.Time
	syncMu             sync.Mutex
	firstScheduled     map[int64]time.Time
	firstScheduledMu   sync.Mutex
	riskEventNotBefore map[riskEventKey]time.Time
	riskEventMu        sync.Mutex
	ticks              sync.WaitGroup
	autoResumePending  bool
	cancelOrders       OrderCanceler
}

// New builds a runtime. A nil logger discards logs.
func New(pool *pgxpool.Pool, market Market, logger *slog.Logger, options ...Option) *Runtime {
	if logger == nil {
		logger = slog.New(slog.NewTextHandler(discardWriter{}, nil))
	}

	runtime := &Runtime{
		pool:               pool,
		market:             market,
		logger:             logger,
		now:                func() time.Time { return time.Now().UTC() },
		orderSyncInterval:  5 * time.Second,
		lastOrderSync:      map[int64]time.Time{},
		orderSyncNotBefore: map[int64]time.Time{},
		firstScheduled:     map[int64]time.Time{},
		riskEventNotBefore: map[riskEventKey]time.Time{},
		autoResumePending:  true,
	}
	runtime.cancelOrders = func(ctx context.Context, userID, botID int64, reason string) error {
		_, err := runtime.cancelOpenOrders(ctx, userID, botID, reason)

		return err
	}
	for _, option := range options {
		option(runtime)
	}

	return runtime
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
		case bot.live() && !r.liveTrading:
			message = liveDisabledMessage
		case bot.Exchange != "binance" && !bot.live():
			message = fmt.Sprintf("Exchange %s is not supported by the Go runtime for paper bots yet. The bot was paused.", bot.Exchange)
		}
		if message != "" {
			if bot.live() {
				// Pausing must also remove any resting live orders. A refused
				// start is not allowed to leave exchange orders working.
				return r.pause(ctx, bot, pauseCauseLiveGate, message, "bot.live_trading_disabled", "risk.live_trading_disabled", "critical")
			}

			_, err := r.pool.Exec(ctx,
				`UPDATE bots
				    SET status = 'paused', last_error = $2, pause_reason = $3,
				        pause_reason_recorded_at = now(), updated_at = now()
				  WHERE id = $1 AND deleted_at IS NULL`,
				bot.ID, message, pauseCauseUnsupportedExchange.String(),
			)

			return err
		}
		if bot.live() {
			if err := r.liveBalanceAllowsStart(ctx, bot); err != nil {
				return r.pause(ctx, bot, pauseCauseLiveGate, "Live start refused: "+err.Error(), "bot.live_start_refused", "risk.live_start_refused", "critical")
			}
		}
		if !bot.live() && bot.ReconcilePending {
			if err := r.reconcilePendingPaper(ctx, bot); err != nil {
				message := "Start refused: pending reconcile failed: " + err.Error()
				_, _ = r.pool.Exec(ctx, `UPDATE bots SET last_error = $2, desired_status = status,
					pause_reason_recorded_at = CASE WHEN status = 'paused' AND pause_reason IS NOT NULL THEN now() ELSE pause_reason_recorded_at END,
					updated_at = now() WHERE id = $1 AND deleted_at IS NULL`, bot.ID, message)

				return fmt.Errorf("%s", message)
			}

			refreshed, err := r.loadBot(ctx, bot.ID)
			if err != nil {
				return err
			}
			bot = refreshed
		}
		if bot.StrategyReset {
			if _, err := r.pool.Exec(ctx,
				`UPDATE strategy_configs SET state = NULL, updated_at = now()
				 WHERE bot_id = $1
				   AND EXISTS (SELECT 1 FROM bots WHERE bots.id = $1 AND bots.deleted_at IS NULL)`,
				bot.ID,
			); err != nil {
				return err
			}
			if _, err := r.pool.Exec(ctx, `UPDATE bots SET strategy_reset_pending = false, updated_at = now() WHERE id = $1 AND deleted_at IS NULL`, bot.ID); err != nil {
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
			        consecutive_failures = 0, last_error = NULL,
			        pause_reason = NULL, pause_reason_recorded_at = NULL, updated_at = now()
			  WHERE id = $1 AND user_id = $2 AND deleted_at IS NULL`,
			command.BotID, command.UserID,
		)
	} else if status == "paused" {
		_, err = r.pool.Exec(ctx,
			`UPDATE bots
			    SET status = 'paused', pause_reason = $2, pause_reason_recorded_at = now(), updated_at = now()
			  WHERE id = $1 AND user_id = $3 AND deleted_at IS NULL`,
			command.BotID, pauseCauseUser.String(), command.UserID,
		)
	} else {
		_, err = r.pool.Exec(ctx,
			`UPDATE bots
			    SET status = $2, pause_reason = NULL, pause_reason_recorded_at = NULL, updated_at = now()
			  WHERE id = $1 AND user_id = $3 AND deleted_at IS NULL`,
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
		reconcile, _ := command.PayloadBool("reconcile")
		if reconcile {
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

			if !bot.ReconcilePending {
				return nil
			}

			return r.reconcileRunningPaper(ctx, bot)
		}

		_, err := r.pool.Exec(ctx,
			`UPDATE strategy_configs SET state = NULL, updated_at = now()
			 WHERE bot_id = $1
			   AND EXISTS (SELECT 1 FROM bots WHERE bots.id = $1 AND bots.user_id = $2 AND bots.deleted_at IS NULL)`,
			command.BotID, command.UserID,
		)
		if err != nil {
			return fmt.Errorf("reset strategy state: %w", err)
		}
		_, err = r.pool.Exec(ctx, `UPDATE bots
			SET strategy_reset_pending = false,
			    pause_reason_recorded_at = CASE WHEN status = 'paused' AND pause_reason IS NOT NULL
			                                    THEN now() ELSE pause_reason_recorded_at END,
			    updated_at = now()
			WHERE id = $1 AND user_id = $2 AND deleted_at IS NULL`, command.BotID, command.UserID)

		return err
	})
}

// reconcileRunningPaper completes a pending reconcile for a running paper bot.
// The caller must hold the bot lock. It cancels open orders before resetting
// strategy state so the next tick rebuilds the ladder from a clean slate.
func (r *Runtime) reconcileRunningPaper(ctx context.Context, bot *Bot) error {
	if bot.live() {
		if bot.ReconcilePending {
			if _, err := r.pool.Exec(ctx, `UPDATE bots
				SET reconcile_pending = false,
				    pause_reason_recorded_at = CASE WHEN status = 'paused' AND pause_reason IS NOT NULL
				                                    THEN now() ELSE pause_reason_recorded_at END,
				    updated_at = now()
				WHERE id = $1 AND deleted_at IS NULL`, bot.ID); err != nil {
				return fmt.Errorf("clear live reconcile: %w", err)
			}
			r.logger.Warn("ignoring reconcile for live bot", "bot_id", bot.ID)
		}

		return nil
	}

	if bot.Status != "running" || bot.DesiredStatus != "running" {
		return nil
	}

	return r.reconcilePaperState(ctx, bot)
}

// reconcilePendingPaper completes a pending reconcile before a paper bot starts.
func (r *Runtime) reconcilePendingPaper(ctx context.Context, bot *Bot) error {
	if bot.live() {
		return nil
	}

	return r.reconcilePaperState(ctx, bot)
}

func (r *Runtime) reconcilePaperState(ctx context.Context, bot *Bot) error {
	if !bot.ReconcilePending && !bot.CancelRetry && !bot.StrategyReset {
		return nil
	}

	if err := r.cancelOrders(ctx, bot.UserID, bot.ID, "reconcile"); err != nil {
		_, _ = r.pool.Exec(ctx, `UPDATE bots
			SET cancel_retry_requested = true,
			    pause_reason_recorded_at = CASE WHEN status = 'paused' AND pause_reason IS NOT NULL
			                                    THEN now() ELSE pause_reason_recorded_at END,
			    updated_at = now()
			WHERE id = $1 AND deleted_at IS NULL`, bot.ID)

		return fmt.Errorf("reconcile cancel open orders: %w", err)
	}

	if _, err := r.pool.Exec(ctx,
		`UPDATE strategy_configs SET state = NULL, updated_at = now()
		 WHERE bot_id = $1
		   AND EXISTS (SELECT 1 FROM bots WHERE bots.id = $1 AND bots.deleted_at IS NULL)`,
		bot.ID,
	); err != nil {
		return fmt.Errorf("reconcile reset strategy state: %w", err)
	}

	if _, err := r.pool.Exec(ctx,
		`UPDATE bots
		    SET reconcile_pending = false, cancel_retry_requested = false,
		        strategy_reset_pending = false,
		        pause_reason_recorded_at = CASE WHEN status = 'paused' AND pause_reason IS NOT NULL
		                                        THEN now() ELSE pause_reason_recorded_at END,
		        updated_at = now()
		  WHERE id = $1 AND deleted_at IS NULL`,
		bot.ID,
	); err != nil {
		return fmt.Errorf("reconcile clear pending flags: %w", err)
	}

	return nil
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
		var mode, clientOrderID, exchangeOrderID, filledQuantity string
		err := r.pool.QueryRow(ctx,
			`SELECT orders.bot_id, orders.grid_level_id, orders.mode, orders.client_order_id,
			        COALESCE(orders.exchange_order_id, ''), orders.filled_quantity::text
			 FROM orders JOIN bots ON bots.id = orders.bot_id
			 WHERE orders.id = $1 AND orders.bot_id = $2 AND bots.user_id = $3
			   AND bots.deleted_at IS NULL
			   AND orders.status IN ('pending','open','partially_filled')`,
			orderID, command.BotID, command.UserID,
		).Scan(&botID, &gridLevelID, &mode, &clientOrderID, &exchangeOrderID, &filledQuantity)
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		if err != nil {
			return fmt.Errorf("load order: %w", err)
		}
		if mode == "live" {
			bot, err := r.loadBot(ctx, botID)
			if err != nil {
				return err
			}
			if _, err := r.cancelLiveOrder(ctx, bot, openOrder{
				ID:              orderID,
				ClientOrderID:   clientOrderID,
				ExchangeOrderID: exchangeOrderID,
				FilledQuantity:  mustDecimal(filledQuantity),
			}); err != nil {
				return err
			}
		}

		if _, err := r.pool.Exec(ctx,
			`UPDATE orders SET status = 'canceled', closed_at = now(), updated_at = now()
			 WHERE id = $1 AND status IN ('pending','open','partially_filled')`,
			orderID,
		); err != nil {
			return fmt.Errorf("cancel order: %w", err)
		}

		if gridLevelID != nil {
			_, _ = r.pool.Exec(ctx, `UPDATE grid_levels SET state = 'canceled', updated_at = now()
				WHERE id = $1 AND state IN ('idle', 'open')`, *gridLevelID)
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
		`SELECT id FROM bots WHERE user_id = $1 AND status <> 'stopped' AND desired_status = 'paused' AND deleted_at IS NULL ORDER BY id`,
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
				`UPDATE bots
				    SET status = 'paused', last_error = $2, pause_reason = $3,
				        pause_reason_recorded_at = now(), updated_at = now()
				  WHERE id = $1 AND deleted_at IS NULL`,
				botID, message, pauseCauseKillSwitch.String(),
			); err != nil {
				return fmt.Errorf("pause bot %d: %w", botID, err)
			}
			if _, err := r.cancelOpenOrders(ctx, command.UserID, botID, "kill_switch"); err != nil {
				return fmt.Errorf("cancel bot %d orders: %w", botID, err)
			}
			if _, err := r.pool.Exec(ctx, `UPDATE bots SET kill_switch_requested_at = NULL WHERE id = $1 AND deleted_at IS NULL`, botID); err != nil {
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
	if err := r.pool.QueryRow(ctx, `SELECT count(*) FROM bots WHERE status = 'running' AND deleted_at IS NULL`).Scan(&count); err != nil {
		return 0
	}

	return count
}

// RunScheduler performs the due-check every second until ctx is canceled.
func (r *Runtime) RunScheduler(ctx context.Context) {
	r.syncAllLiveOrders(ctx, true)

	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			r.ticks.Wait()
			return
		case <-ticker.C:
			if err := r.reconcile(ctx); err != nil {
				r.logger.Error("runtime reconciliation failed", "error_type", fmt.Sprintf("%T", err))
			}
			r.syncAllLiveOrders(ctx, false)
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
	r.syncAllLiveOrders(ctx, true)

	return r.reconcile(ctx)
}

func (r *Runtime) reconcile(ctx context.Context) error {
	rows, err := r.pool.Query(ctx,
		`SELECT id FROM bots
		 WHERE deleted_at IS NULL
		   AND ((status = 'running' AND desired_status IN ('paused','stopped'))
		    OR (status = 'paused' AND desired_status = 'stopped')
		    OR (status = 'stopped' AND desired_status = 'running')
		    OR (status = 'idle' AND desired_status = 'running')
		    OR kill_switch_requested_at IS NOT NULL
		    OR cancel_retry_requested = true
		    OR strategy_reset_pending = true
		    OR reconcile_pending = true)`,
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

	cleanupFailed := false
	for _, id := range ids {
		botID := id
		if err := withBotLock(ctx, botID, func(ctx context.Context) error {
			return r.applyDesired(ctx, botID)
		}); err != nil {
			cleanupFailed = true
			r.logger.Error("desired-status reconciliation failed", "bot_id", botID, "error_type", fmt.Sprintf("%T", err))
		}
	}

	// Complete pending cancellation-retry/reconcile/strategy-reset cleanup
	// before resuming stale-paused bots so the provenance refresh in that
	// cleanup makes them eligible in this same pass.
	if r.autoResumePending {
		if err := r.autoResumeStalePausedBots(ctx); err != nil {
			r.logger.Error("auto-resume of stale-paused bots failed", "error_type", fmt.Sprintf("%T", err))
		}
		if cleanupFailed {
			// The blocked bot stays ineligible through its pending-flag
			// predicates, but unrelated stale-paused bots can still resume.
			// Keep the one-shot pass pending so cleanup is retried next time.
			r.autoResumePending = true
		}
	}

	// Retry cancellation for stopped bots whose previous stop attempt failed
	// after the status update committed.
	stopped, err := r.pool.Query(ctx,
		`SELECT DISTINCT b.id, b.user_id FROM bots b JOIN orders o ON o.bot_id = b.id
		 WHERE b.deleted_at IS NULL AND b.status = 'stopped' AND o.status IN ('pending','open','partially_filled')`,
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

	// A bot is stale only when both its own last successful tick and this
	// process's first scheduling attempt for the bot are older than the
	// effective threshold. The latter grants a restart grace so downtime does
	// not pause every running bot before its first post-restart tick.
	stale, err := r.pool.Query(ctx,
		`SELECT id,
		        COALESCE(last_successful_tick_at, last_tick_at, created_at),
		        tick_interval_seconds
		 FROM bots
		 WHERE deleted_at IS NULL
		   AND status = 'running'
		 ORDER BY id`,
	)
	if err != nil {
		return fmt.Errorf("load running bots for stale check: %w", err)
	}
	defer stale.Close()

	type runningBot struct {
		id             int64
		lastSuccessful time.Time
		interval       int
	}
	var running []runningBot
	for stale.Next() {
		var bot runningBot
		if err := stale.Scan(&bot.id, &bot.lastSuccessful, &bot.interval); err != nil {
			return err
		}
		running = append(running, bot)
	}
	if err := stale.Err(); err != nil {
		return err
	}

	now := r.now()
	runningIDs := make(map[int64]struct{}, len(running))
	var staleIDs []int64
	for _, bot := range running {
		runningIDs[bot.id] = struct{}{}
		threshold := staleThreshold(bot.interval)
		firstScheduled := r.firstScheduledAt(bot.id, now)
		if !firstScheduled.Add(threshold).Before(now) {
			continue
		}
		if !bot.lastSuccessful.Add(threshold).Before(now) {
			continue
		}
		staleIDs = append(staleIDs, bot.id)
	}
	r.pruneFirstScheduled(runningIDs)
	r.pruneRiskEventCooldowns(runningIDs)

	for _, id := range staleIDs {
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
			return r.pause(ctx, bot, pauseCauseStaleTick, "No successful tick within the stale-tick threshold. Bot paused and open orders canceled.", "bot.stale", "risk.stale_tick", "critical")
		}); err != nil {
			r.logger.Error("stale-bot reconciliation failed", "bot_id", botID, "error_type", fmt.Sprintf("%T", err))
		}
	}

	return nil
}

// autoResumeStalePausedBots recovers paper bots that were stale-paused before
// this process started. It runs once per process, after pending cleanup and
// before stale checks, and never resumes a live bot or a safety pause.
func (r *Runtime) autoResumeStalePausedBots(ctx context.Context) error {
	rows, err := r.pool.Query(ctx,
		`SELECT id, user_id FROM bots
		  WHERE deleted_at IS NULL
		    AND status = 'paused'
		    AND desired_status = 'running'
		    AND mode = 'paper'
		    AND exchange = 'binance'
		    AND pause_reason = $1
		    AND pause_reason_recorded_at = updated_at
		    AND kill_switch_requested_at IS NULL
		    AND cancel_retry_requested = false
		    AND reconcile_pending = false
		    AND strategy_reset_pending = false
		  ORDER BY id`,
		pauseCauseStaleTick.String(),
	)
	if err != nil {
		return fmt.Errorf("load stale-paused bots: %w", err)
	}
	defer rows.Close()

	type candidate struct {
		id     int64
		userID int64
	}
	var candidates []candidate
	for rows.Next() {
		var c candidate
		if err := rows.Scan(&c.id, &c.userID); err != nil {
			return err
		}
		candidates = append(candidates, c)
	}
	if err := rows.Err(); err != nil {
		return err
	}

	for _, c := range candidates {
		candidate := c
		if err := withBotLock(ctx, candidate.id, func(ctx context.Context) error {
			tag, err := r.pool.Exec(ctx,
				`UPDATE bots
				    SET status = 'running', pause_reason = NULL, pause_reason_recorded_at = NULL,
				        last_error = NULL, updated_at = now()
				  WHERE id = $1
				    AND deleted_at IS NULL
				    AND status = 'paused'
				    AND desired_status = 'running'
				    AND mode = 'paper'
				    AND exchange = 'binance'
				    AND pause_reason = $2
				    AND pause_reason_recorded_at = updated_at
				    AND kill_switch_requested_at IS NULL
				    AND cancel_retry_requested = false
				    AND reconcile_pending = false
				    AND strategy_reset_pending = false`,
				candidate.id, pauseCauseStaleTick.String(),
			)
			if err != nil {
				return err
			}
			if tag.RowsAffected() == 0 {
				return nil
			}

			r.activity(ctx, candidate.userID, candidate.id, "bot.auto_resumed", "info", "Bot auto-resumed after a stale-tick pause.", map[string]any{"cause": pauseCauseStaleTick.String()})
			r.logger.Info("bot auto-resumed", "bot_id", candidate.id, "cause", pauseCauseStaleTick.String())

			return nil
		}); err != nil {
			return fmt.Errorf("auto-resume bot %d: %w", candidate.id, err)
		}
	}

	r.autoResumePending = false

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
		if _, err := r.pool.Exec(ctx,
			`UPDATE bots
			    SET status = 'paused', pause_reason = $2, pause_reason_recorded_at = now(), updated_at = now()
			  WHERE id = $1 AND deleted_at IS NULL`,
			bot.ID, pauseCauseKillSwitch.String(),
		); err != nil {
			return err
		}
		if _, err := r.cancelOpenOrders(ctx, bot.UserID, bot.ID, "kill_switch"); err != nil {
			return err
		}
		if _, err := r.pool.Exec(ctx, `UPDATE bots SET kill_switch_requested_at = NULL WHERE id = $1 AND deleted_at IS NULL`, bot.ID); err != nil {
			return err
		}
		return nil
	}

	if bot.ReconcilePending {
		var reconcileErr error
		if !bot.live() && (bot.Status != "running" || bot.DesiredStatus != "running") {
			reconcileErr = r.reconcilePendingPaper(ctx, bot)
		} else {
			reconcileErr = r.reconcileRunningPaper(ctx, bot)
		}

		if reconcileErr != nil {
			return reconcileErr
		}

		refreshed, err := r.loadBot(ctx, bot.ID)
		if err != nil {
			return err
		}
		bot = refreshed
	}

	if bot.CancelRetry {
		if _, err := r.cancelOpenOrders(ctx, bot.UserID, bot.ID, "cancel_retry"); err != nil {
			return err
		}
		if _, err := r.pool.Exec(ctx, `UPDATE bots
			SET cancel_retry_requested = false,
			    pause_reason_recorded_at = CASE WHEN status = 'paused' AND pause_reason IS NOT NULL
			                                    THEN now() ELSE pause_reason_recorded_at END,
			    updated_at = now()
			WHERE id = $1 AND deleted_at IS NULL`, bot.ID); err != nil {
			return err
		}
	}

	if bot.StrategyReset {
		if _, err := r.pool.Exec(ctx,
			`UPDATE strategy_configs SET state = NULL, updated_at = now()
			 WHERE bot_id = $1
			   AND EXISTS (SELECT 1 FROM bots WHERE id = $1 AND deleted_at IS NULL)`,
			bot.ID,
		); err != nil {
			return err
		}
		if _, err := r.pool.Exec(ctx, `UPDATE bots
			SET strategy_reset_pending = false,
			    pause_reason_recorded_at = CASE WHEN status = 'paused' AND pause_reason IS NOT NULL
			                                    THEN now() ELSE pause_reason_recorded_at END,
			    updated_at = now()
			WHERE id = $1 AND deleted_at IS NULL`, bot.ID); err != nil {
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
		if !bot.live() && bot.ReconcilePending {
			if err := r.reconcilePendingPaper(ctx, bot); err != nil {
				message := "Start refused: pending reconcile failed: " + err.Error()
				_, _ = r.pool.Exec(ctx, `UPDATE bots SET last_error = $2, desired_status = status,
					pause_reason_recorded_at = CASE WHEN status = 'paused' AND pause_reason IS NOT NULL THEN now() ELSE pause_reason_recorded_at END,
					updated_at = now() WHERE id = $1 AND deleted_at IS NULL`, bot.ID, message)

				return fmt.Errorf("%s", message)
			}

			refreshed, err := r.loadBot(ctx, bot.ID)
			if err != nil {
				return err
			}
			bot = refreshed
		}
		if bot.live() && !r.liveTrading {
			return r.pause(ctx, bot, pauseCauseLiveGate, liveDisabledMessage, "bot.live_trading_disabled", "risk.live_trading_disabled", "critical")
		}
		if bot.Exchange != "binance" && !bot.live() {
			return r.markPaused(ctx, bot, pauseCauseUnsupportedExchange, fmt.Sprintf("Exchange %s is not supported by the Go runtime for paper bots yet. The bot was paused.", bot.Exchange))
		}
		if bot.live() {
			if err := r.liveBalanceAllowsStart(ctx, bot); err != nil {
				return r.pause(ctx, bot, pauseCauseLiveGate, "Live start refused: "+err.Error(), "bot.live_start_refused", "risk.live_start_refused", "critical")
			}
		}
		_, err := r.pool.Exec(ctx,
			`UPDATE bots
			    SET status = 'running', last_tick_at = NULL, last_successful_tick_at = now(),
			        consecutive_failures = 0, last_error = NULL,
			        pause_reason = NULL, pause_reason_recorded_at = NULL, updated_at = now()
			  WHERE id = $1 AND deleted_at IS NULL`,
			bot.ID,
		)
		return err
	case "paused":
		_, err := r.pool.Exec(ctx,
			`UPDATE bots
			    SET status = 'paused', pause_reason = $2, pause_reason_recorded_at = now(), updated_at = now()
			  WHERE id = $1 AND deleted_at IS NULL`,
			bot.ID, pauseCauseUser.String(),
		)
		if err != nil {
			return err
		}
		return nil
	case "stopped":
		if _, err := r.pool.Exec(ctx,
			`UPDATE bots
			    SET status = 'stopped', pause_reason = NULL, pause_reason_recorded_at = NULL, updated_at = now()
			  WHERE id = $1 AND deleted_at IS NULL`,
			bot.ID,
		); err != nil {
			return err
		}
		_, err = r.cancelOpenOrders(ctx, bot.UserID, bot.ID, "bot.stop")
		return err
	}

	return nil
}

func (r *Runtime) markPaused(ctx context.Context, bot *Bot, cause pauseCause, message string) error {
	cause = requirePauseCause(cause)
	_, err := r.pool.Exec(ctx,
		`UPDATE bots
		    SET status = 'paused', last_error = $2, pause_reason = $3,
		        pause_reason_recorded_at = now(), updated_at = now()
		  WHERE id = $1 AND deleted_at IS NULL`,
		bot.ID, message, cause.String(),
	)
	return err
}

func (r *Runtime) runDue(ctx context.Context) error {
	rows, err := r.pool.Query(ctx,
		`SELECT id FROM bots
		 WHERE deleted_at IS NULL
		   AND status = 'running'
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

		r.ticks.Add(1)
		go func(botID int64) {
			defer r.ticks.Done()
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

// staleThreshold is the per-bot window allowed between successful ticks. It
// matches the previous SQL rule: at least three minutes, or the bot's interval
// plus thirty seconds when that is longer.
func staleThreshold(tickIntervalSeconds int) time.Duration {
	threshold := time.Duration(tickIntervalSeconds+30) * time.Second
	if threshold < 180*time.Second {
		return 180 * time.Second
	}
	return threshold
}

func (r *Runtime) firstScheduledAt(botID int64, now time.Time) time.Time {
	r.firstScheduledMu.Lock()
	defer r.firstScheduledMu.Unlock()

	if first, ok := r.firstScheduled[botID]; ok {
		return first
	}
	r.firstScheduled[botID] = now
	return now
}

func (r *Runtime) pruneFirstScheduled(running map[int64]struct{}) {
	r.firstScheduledMu.Lock()
	defer r.firstScheduledMu.Unlock()

	for botID := range r.firstScheduled {
		if _, ok := running[botID]; !ok {
			delete(r.firstScheduled, botID)
		}
	}
}

func (r *Runtime) pruneRiskEventCooldowns(running map[int64]struct{}) {
	r.riskEventMu.Lock()
	defer r.riskEventMu.Unlock()

	for key := range r.riskEventNotBefore {
		if _, ok := running[key.botID]; !ok {
			delete(r.riskEventNotBefore, key)
		}
	}
}

// allowRiskEvent reports whether a risk event for this bot and type is outside
// its cooldown, and records the next allowed time when it is. A zero bot ID or
// cooldown skips throttling.
func (r *Runtime) allowRiskEvent(botID int64, typ string, cooldown time.Duration) bool {
	if botID <= 0 || cooldown <= 0 {
		return true
	}

	at := r.now()
	key := riskEventKey{botID: botID, typ: typ}

	r.riskEventMu.Lock()
	defer r.riskEventMu.Unlock()

	if next, ok := r.riskEventNotBefore[key]; ok && at.Before(next) {
		return false
	}
	r.riskEventNotBefore[key] = at.Add(cooldown)

	return true
}

// releaseRiskEvent clears a reservation when the event could not be written, so
// the next tick can retry instead of losing the event to the cooldown.
func (r *Runtime) releaseRiskEvent(botID int64, typ string) {
	r.riskEventMu.Lock()
	defer r.riskEventMu.Unlock()

	delete(r.riskEventNotBefore, riskEventKey{botID: botID, typ: typ})
}

// recordPositionLimitSkips writes at most one position-limit event per bot per
// cooldown window, with the number of orders blocked during this attempt.
func (r *Runtime) recordPositionLimitSkips(ctx context.Context, bot *Bot, skipped int, projectedNotional string) {
	if skipped == 0 || !r.allowRiskEvent(bot.ID, "risk.position_limit", riskEventCooldown) {
		return
	}

	if err := r.riskEvent(ctx, bot.UserID, bot.ID, "risk.position_limit", "critical",
		fmt.Sprintf("%d order(s) skipped: projected position exceeds the limit.", skipped),
		map[string]any{"orders_skipped": skipped, "projected_notional": projectedNotional},
	); err != nil {
		r.releaseRiskEvent(bot.ID, "risk.position_limit")
	}
}

// orderSkips aggregates invalid-order reasons for one placement attempt.
type orderSkips struct {
	reasons map[string]int
	total   int
}

func (s *orderSkips) add(reason string) {
	if s.reasons == nil {
		s.reasons = map[string]int{}
	}
	s.reasons[reason]++
	s.total++
}

// recordInvalidOrderSkips writes at most one invalid-order event per bot per
// cooldown window, with the skipped count and a per-reason breakdown.
func (r *Runtime) recordInvalidOrderSkips(ctx context.Context, bot *Bot, skips *orderSkips) {
	if skips == nil || skips.total == 0 || !r.allowRiskEvent(bot.ID, "risk.invalid_order", riskEventCooldown) {
		return
	}

	if err := r.riskEvent(ctx, bot.UserID, bot.ID, "risk.invalid_order", "critical",
		fmt.Sprintf("%d order(s) skipped: invalid order for the exchange or grid.", skips.total),
		map[string]any{"orders_skipped": skips.total, "reasons": skips.reasons},
	); err != nil {
		r.releaseRiskEvent(bot.ID, "risk.invalid_order")
	}
}

// FeedSymbols returns the distinct public symbols the market-data feed must
// subscribe to for Binance bots that are running or starting. Other venues do
// not have a WebSocket client in this build and use REST snapshots instead.
func (r *Runtime) FeedSymbols(ctx context.Context) ([]string, error) {
	rows, err := r.pool.Query(ctx,
		`SELECT DISTINCT symbol FROM bots
		 WHERE deleted_at IS NULL AND exchange = 'binance' AND status IN ('starting','running')
		   AND symbol IS NOT NULL AND symbol <> ''
		 ORDER BY symbol`,
	)
	if err != nil {
		return nil, fmt.Errorf("load feed symbols: %w", err)
	}
	defer rows.Close()

	var symbols []string
	for rows.Next() {
		var symbol string
		if err := rows.Scan(&symbol); err != nil {
			return nil, err
		}
		symbols = append(symbols, symbol)
	}

	return symbols, rows.Err()
}

// Source adapts the runtime to tick.Source for callers that want the pure
// due-check helper.
func (r *Runtime) Source(ctx context.Context) ([]tick.Bot, error) {
	rows, err := r.pool.Query(ctx,
		`SELECT id, status, tick_interval_seconds, last_tick_at FROM bots WHERE status = 'running' AND deleted_at IS NULL`,
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
	encoded, _ := json.Marshal(withTickCorrelation(ctx, context))
	_, err := r.pool.Exec(ctx,
		`INSERT INTO activity_logs (user_id, bot_id, type, severity, message, context, created_at)
		 VALUES ($1, NULLIF($2, 0), $3, $4, $5, $6::jsonb, now())`,
		userID, botID, typ, severity, message, string(encoded),
	)
	if err != nil {
		r.logger.Error("record activity failed", "type", typ, "bot_id", botID, "error_type", fmt.Sprintf("%T", err))
	}
}

func (r *Runtime) riskEvent(ctx context.Context, userID, botID int64, typ, severity, message string, context map[string]any) error {
	encoded, err := json.Marshal(withTickCorrelation(ctx, context))
	if err != nil {
		r.logger.Error("encode risk event context failed", "type", typ, "error_type", fmt.Sprintf("%T", err))
		return err
	}
	_, err = r.pool.Exec(ctx,
		`INSERT INTO risk_events (user_id, bot_id, type, severity, message, context, created_at)
		 VALUES ($1, NULLIF($2, 0), $3, $4, $5, $6::jsonb, now())`,
		userID, botID, typ, severity, message, string(encoded),
	)
	if err != nil {
		r.logger.Error("record risk event failed", "type", typ, "error_type", fmt.Sprintf("%T", err))
		return err
	}

	return nil
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
