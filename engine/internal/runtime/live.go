package runtime

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/exchange"
	"github.com/johnson407/trading-bot/engine/internal/paperfill"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

const liveClientIDMax = 36

func (r *Runtime) liveClient(ctx context.Context, bot *Bot) (exchange.Client, error) {
	if !r.liveTrading {
		return nil, exchange.ErrLiveDisabled
	}
	if r.liveClients == nil {
		return nil, fmt.Errorf("no live exchange client is configured")
	}

	return r.liveClients(ctx, bot)
}

// cancelClient returns a client even when the trading gate is closed: pausing
// a live bot must still be able to cancel existing orders.
func (r *Runtime) cancelClient(ctx context.Context, bot *Bot) (exchange.Client, error) {
	if r.liveClients == nil {
		return nil, fmt.Errorf("no live exchange client is configured")
	}

	return r.liveClients(ctx, bot)
}

// liveClientID is deterministic for one intent generation. It is persisted
// before the network call and reused when a pending order is retried.
func liveClientID(botID, gridID int64, sequence *int, strategyKey, side string, generation int) string {
	key := "s" + sanitizeClientKey(strategyKey)
	if sequence != nil {
		key = fmt.Sprintf("l%d", *sequence)
	}

	id := fmt.Sprintf("go-b%d-g%d-%s-%s-%d", botID, gridID, key, side, generation)
	if len(id) <= liveClientIDMax {
		return id
	}

	sum := sha256.Sum256([]byte(id))

	return "go-" + hex.EncodeToString(sum[:14])
}

func sanitizeClientKey(value string) string {
	value = strings.ToLower(value)
	var builder strings.Builder

	for _, character := range value {
		if (character >= 'a' && character <= 'z') || (character >= '0' && character <= '9') {
			builder.WriteRune(character)
		}
	}

	if builder.Len() == 0 {
		return "x"
	}

	return builder.String()
}

type intentOrder struct {
	ID         int64
	ClientID   string
	ExchangeID string
	Status     string
	Price      decimal.Decimal
	Quantity   decimal.Decimal
	Filled     decimal.Decimal
}

func (r *Runtime) latestIntentOrder(ctx context.Context, bot *Bot, gridID int64, intent strategy.OrderIntent) (*intentOrder, error) {
	var sequence *int64
	if intent.GridLevelSequence != nil {
		value := int64(*intent.GridLevelSequence)
		sequence = &value
	}

	var order intentOrder
	var price, quantity, filled string

	err := r.pool.QueryRow(ctx,
		`SELECT o.id, o.client_order_id, COALESCE(o.exchange_order_id, ''), o.status, o.price::text, o.quantity::text, o.filled_quantity::text
		 FROM orders o
		 LEFT JOIN grid_levels gl ON gl.id = o.grid_level_id
		 WHERE o.bot_id = $1 AND o.grid_id = $2 AND o.side = $3
		   AND (($4::bigint IS NOT NULL AND gl.sequence = $4)
		        OR ($4::bigint IS NULL AND o.grid_level_id IS NULL AND o.price = $5))
		 ORDER BY o.id DESC LIMIT 1`,
		bot.ID, gridID, string(intent.Side), sequence, intent.Price.String(),
	).Scan(&order.ID, &order.ClientID, &order.ExchangeID, &order.Status, &price, &quantity, &filled)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, nil
		}

		return nil, err
	}

	order.Price = mustDecimal(price)
	order.Quantity = mustDecimal(quantity)
	order.Filled = mustDecimal(filled)

	return &order, nil
}

func (r *Runtime) nextGeneration(ctx context.Context, bot *Bot, gridID int64, intent strategy.OrderIntent) (int, error) {
	var sequence *int64
	if intent.GridLevelSequence != nil {
		value := int64(*intent.GridLevelSequence)
		sequence = &value
	}

	// Every row for this intent, not only terminal ones, advances the
	// generation. Open rows are handled before this point, and counting all
	// rows guarantees a completed client order ID can never be minted again.
	var count int
	err := r.pool.QueryRow(ctx,
		`SELECT count(*) FROM orders o
		 LEFT JOIN grid_levels gl ON gl.id = o.grid_level_id
		 WHERE o.bot_id = $1 AND o.grid_id = $2 AND o.side = $3
		   AND (($4::bigint IS NOT NULL AND gl.sequence = $4)
		        OR ($4::bigint IS NULL AND o.grid_level_id IS NULL AND o.price = $5))`,
		bot.ID, gridID, string(intent.Side), sequence, intent.Price.String(),
	).Scan(&count)
	if err != nil {
		return 0, err
	}

	return count + 1, nil
}

func (r *Runtime) persistLiveIntent(ctx context.Context, bot *Bot, active *gridState, levelID *int64, intent strategy.OrderIntent, clientID string) (int64, bool, error) {
	var orderID int64

	err := r.pool.QueryRow(ctx,
		`INSERT INTO orders (bot_id, grid_id, grid_level_id, client_order_id, mode, side, type, price, quantity, filled_quantity, status, requested_at, opened_at, created_at, updated_at)
		 SELECT $1, $2, $3, $4, $5, $6, 'limit', $7, $8, 0, 'pending', now(), now(), now(), now()
		 WHERE EXISTS (SELECT 1 FROM bots WHERE id = $1 AND status = 'running' AND desired_status = 'running' AND deleted_at IS NULL)
		 ON CONFLICT (bot_id, client_order_id) DO NOTHING
		 RETURNING id`,
		bot.ID, active.ID, levelID, clientID, bot.Mode, string(intent.Side), intent.Price.String(), intent.Quantity.String(),
	).Scan(&orderID)
	if errors.Is(err, pgx.ErrNoRows) {
		return 0, false, nil
	}
	if err != nil {
		return 0, false, err
	}

	return orderID, true, nil
}

func (r *Runtime) placeLiveOrders(ctx context.Context, bot *Bot, active *gridState, intents []strategy.OrderIntent) (int, error) {
	if active == nil || len(intents) == 0 {
		return 0, nil
	}
	if !r.liveTrading {
		return 0, exchange.ErrLiveDisabled
	}
	if r.orderSyncBlocked(bot.ID) {
		return 0, nil
	}

	client, err := r.liveClient(ctx, bot)
	if err != nil {
		return 0, err
	}

	rules, err := client.Rules(ctx, bot.Symbol)
	if err != nil {
		return 0, err
	}

	open, err := r.openOrders(ctx, bot.ID)
	if err != nil {
		return 0, err
	}
	openCount := 0
	for _, order := range open {
		if order.Status != "pending" {
			openCount++
		}
	}

	maxOpen, maxNotional := r.riskLimits(ctx, bot)
	atPrice, atSequence, openBuyNotional, reservedSell := orderRiskState(open)

	placed := 0
	positionLimitSkips := 0
	projectedNotional := ""
	defer func() {
		r.recordPositionLimitSkips(ctx, bot, positionLimitSkips, projectedNotional)
	}()
	for _, intent := range intents {
		if !intent.Price.IsPositive() || !intent.Quantity.IsPositive() {
			r.riskEvent(ctx, bot.UserID, bot.ID, "risk.invalid_order", "critical", "Order skipped: price and quantity must be positive.", nil)
			continue
		}

		// A grid calculates at scale 16, but venues only accept prices and
		// quantities that are exact multiples of their tick/step size. Rounding
		// happens before the client order ID is derived so a retry targets the
		// same intent and the same rounded order.
		price, ok := roundOrderValue(intent.Price, rules.PriceStep, rules.PricePrecision)
		if !ok {
			r.riskEvent(ctx, bot.UserID, bot.ID, "risk.invalid_order", "critical", "Order skipped: price could not be rounded to the exchange precision.", nil)
			continue
		}
		quantity, ok := roundOrderValue(intent.Quantity, rules.AmountStep, rules.AmountPrecision)
		if !ok {
			r.riskEvent(ctx, bot.UserID, bot.ID, "risk.invalid_order", "critical", "Order skipped: quantity could not be rounded to the exchange precision.", nil)
			continue
		}
		if !price.IsPositive() || !quantity.IsPositive() {
			r.riskEvent(ctx, bot.UserID, bot.ID, "risk.invalid_order", "critical", "Order skipped: rounding produced a zero price or quantity.", nil)
			continue
		}

		intent.Price = price
		intent.Quantity = quantity

		if atPrice[intent.Price.String()] {
			continue
		}
		if intent.GridLevelSequence != nil && atSequence[*intent.GridLevelSequence] {
			continue
		}
		if openCount+placed >= maxOpen {
			break
		}
		if rules.MinAmount.IsPositive() && intent.Quantity.LessThan(rules.MinAmount) {
			r.riskEvent(ctx, bot.UserID, bot.ID, "risk.invalid_order", "critical", "Order skipped: quantity is below the exchange minimum.", nil)
			continue
		}
		if rules.MinNotional.IsPositive() && intent.Price.Mul(intent.Quantity).LessThan(rules.MinNotional) {
			r.riskEvent(ctx, bot.UserID, bot.ID, "risk.invalid_order", "critical", "Order skipped: notional is below the exchange minimum.", nil)
			continue
		}

		notional := intent.Price.Mul(intent.Quantity)
		fee := notional.Mul(bot.FeeRate)
		if intent.Side == strategy.SideBuy {
			if notional.Add(fee).Add(openBuyNotional).GreaterThan(bot.Quote) {
				continue
			}
		} else if intent.Quantity.Add(reservedSell).GreaterThan(bot.Base) {
			continue
		}

		projected := bot.Base.Abs().Mul(intent.Price).Add(openBuyNotional)
		if intent.Side == strategy.SideBuy {
			projected = projected.Add(notional)
		} else {
			projected = projected.Sub(notional)
			if projected.IsNegative() {
				projected = decimal.Zero()
			}
		}
		if projected.GreaterThan(maxNotional) {
			positionLimitSkips++
			projectedNotional = projected.String()
			continue
		}

		// Recheck the gate and kill switch immediately before every network
		// placement; a stop may have arrived since the tick started.
		var status, desired string
		var killSwitch *time.Time
		if err := r.pool.QueryRow(ctx,
			`SELECT status, desired_status, kill_switch_requested_at FROM bots WHERE id = $1 AND deleted_at IS NULL`,
			bot.ID,
		).Scan(&status, &desired, &killSwitch); err != nil {
			return placed, err
		}
		if status != "running" || desired != "running" || killSwitch != nil {
			break
		}

		existing, err := r.latestIntentOrder(ctx, bot, active.ID, intent)
		if err != nil {
			return placed, err
		}
		if existing == nil {
			// Fall back to a matching pending row from the open snapshot. This
			// keeps a persist-before-place retry on the same client order ID even
			// if the grid-level join changed shape.
			for index := range open {
				row := open[index]
				if row.Status == "pending" && row.Side == string(intent.Side) && row.Price.Equals(intent.Price) {
					existing = &intentOrder{
						ID: row.ID, ClientID: row.ClientOrderID, ExchangeID: row.ExchangeOrderID,
						Status: row.Status, Price: row.Price, Quantity: row.Quantity, Filled: row.FilledQuantity,
					}
					break
				}
			}
		}

		clientID := ""
		orderID := int64(0)
		if existing != nil {
			switch existing.Status {
			case "open", "partially_filled":
				continue
			case "pending":
				remote, lookupErr := client.OrderByClientID(ctx, bot.Symbol, existing.ClientID)
				if lookupErr == nil {
					if err := r.applyLiveOrder(ctx, bot, existing.ID, existing.Filled, remote); err != nil {
						return placed, err
					}
					continue
				}
				if !isNotFound(lookupErr) {
					// Unknown outcome: leave the pending row for reconciliation
					// instead of placing a second order.
					r.logger.Warn("live order lookup failed; leaving the intent pending", "bot_id", bot.ID, "error_type", fmt.Sprintf("%T", lookupErr))
					continue
				}
				clientID = existing.ClientID
				orderID = existing.ID
			default:
				// Terminal order: a new intent needs a fresh generation.
			}
		}

		if clientID == "" {
			generation, err := r.nextGeneration(ctx, bot, active.ID, intent)
			if err != nil {
				return placed, err
			}
			clientID = liveClientID(bot.ID, active.ID, intent.GridLevelSequence, bot.StrategyKey, string(intent.Side), generation)
		}

		var levelID *int64
		if intent.GridLevelSequence != nil {
			var id int64
			err := r.pool.QueryRow(ctx, `SELECT id FROM grid_levels WHERE grid_id = $1 AND sequence = $2`, active.ID, *intent.GridLevelSequence).Scan(&id)
			if errors.Is(err, pgx.ErrNoRows) {
				r.riskEvent(ctx, bot.UserID, bot.ID, "order.level_missing", "warning", "Grid level was missing during order placement.", map[string]any{"sequence": *intent.GridLevelSequence})
				continue
			}
			if err != nil {
				return placed, err
			}
			levelID = &id
		}

		if orderID == 0 {
			var inserted bool
			orderID, inserted, err = r.persistLiveIntent(ctx, bot, active, levelID, intent, clientID)
			if err != nil {
				return placed, err
			}
			if !inserted {
				continue
			}
		} else if _, err := r.pool.Exec(ctx,
			`UPDATE orders SET requested_at = now(), updated_at = now() WHERE id = $1 AND status = 'pending'`,
			orderID,
		); err != nil {
			return placed, err
		}

		order, err := client.PlaceLimit(ctx, exchange.PlaceRequest{
			Symbol:        bot.Symbol,
			Side:          exchange.Side(intent.Side),
			Price:         intent.Price,
			Quantity:      intent.Quantity,
			ClientOrderID: clientID,
		})
		if err != nil {
			var exchangeError *exchange.Error

			// A duplicate client-order-id acknowledgement means the previous
			// attempt may already be live. Adopt it instead of marking the
			// intent rejected and losing track of a real order.
			if errors.As(err, &exchangeError) && exchangeError.Type == exchange.ErrorRejected && strings.Contains(strings.ToLower(exchangeError.Message), "duplicate") {
				remote, lookupErr := client.OrderByClientID(ctx, bot.Symbol, clientID)
				if lookupErr == nil {
					if applyErr := r.applyLiveOrder(ctx, bot, orderID, decimal.Zero(), remote); applyErr != nil {
						return placed, applyErr
					}
					continue
				}
				if !isNotFound(lookupErr) {
					r.activity(ctx, bot.UserID, bot.ID, "order.placement_unknown", "warning", "A duplicate live order acknowledgement could not be resolved; reconciliation will look it up.", map[string]any{"client_order_id": clientID})
					continue
				}
			}

			if errors.As(err, &exchangeError) && (exchangeError.Type == exchange.ErrorRejected || exchangeError.Type == exchange.ErrorInsufficientFunds || exchangeError.Type == exchange.ErrorAuthentication) {
				_, _ = r.pool.Exec(ctx,
					`UPDATE orders SET status = 'rejected', last_error = $2, closed_at = now(), updated_at = now() WHERE id = $1`,
					orderID, exchangeError.Message,
				)
				r.riskEvent(ctx, bot.UserID, bot.ID, "risk.live_order_rejected", "critical", "A live order was rejected; no open order was recorded.", map[string]any{"client_order_id": clientID, "error_type": string(exchangeError.Type)})
				r.livePlacementFailure(ctx, bot, exchangeError.Message, exchangeError.Type == exchange.ErrorAuthentication || exchangeError.Type == exchange.ErrorInsufficientFunds)
				continue
			}

			// Connection, rate-limit, or unknown outcome: keep the row pending so
			// reconciliation can look it up by client order ID before any retry.
			r.recordOrderSyncError(bot.ID, err)
			r.activity(ctx, bot.UserID, bot.ID, "order.placement_unknown", "warning", "A live order has an unknown outcome; reconciliation will look it up before retrying.", map[string]any{"client_order_id": clientID})
			continue
		}

		if err := r.applyLiveOrder(ctx, bot, orderID, decimal.Zero(), order); err != nil {
			return placed, err
		}

		atPrice[intent.Price.String()] = true
		if intent.GridLevelSequence != nil {
			atSequence[*intent.GridLevelSequence] = true
		}
		if intent.Side == strategy.SideBuy {
			openBuyNotional = openBuyNotional.Add(notional)
		} else {
			reservedSell = reservedSell.Add(intent.Quantity)
		}
		placed++
	}

	return placed, nil
}

// livePlacementFailure counts rejected placements instead of repeating a
// critical risk event on every tick. Authentication and insufficient-funds
// failures pause immediately because every retry would fail the same way.
func (r *Runtime) livePlacementFailure(ctx context.Context, bot *Bot, message string, immediate bool) {
	runes := []rune(message)
	if len(runes) > 500 {
		message = string(runes[:497]) + "..."
	}

	var failures int
	err := r.pool.QueryRow(ctx,
		`UPDATE bots SET last_error = $2, consecutive_failures = consecutive_failures + 1, updated_at = now()
		 WHERE id = $1 AND deleted_at IS NULL RETURNING consecutive_failures`,
		bot.ID, "Live order rejected: "+message,
	).Scan(&failures)
	if err != nil {
		return
	}

	if immediate || failures >= 3 {
		if err := r.pause(ctx, bot, pauseCauseTickFailure, "Bot paused after live order placement failures: "+message, "exchange.failure_threshold", "exchange.failure_threshold", "critical"); err != nil {
			r.logger.Error("pause after live placement failures failed", "bot_id", bot.ID, "error_type", fmt.Sprintf("%T", err))
		}
	}
}

func (r *Runtime) riskLimits(ctx context.Context, bot *Bot) (int, decimal.Decimal) {
	maxOpen := defaultMaxOpen
	maxNotionalRaw := defaultMaxNotional
	_ = r.pool.QueryRow(ctx,
		`SELECT COALESCE(max_open_orders, $2), COALESCE(max_position_notional::text, $4) FROM risk_limits WHERE bot_id = $1
		 UNION ALL SELECT COALESCE(max_open_orders, $2), COALESCE(max_position_notional::text, $4) FROM risk_limits WHERE user_id = $3 AND scope = 'user' AND bot_id IS NULL LIMIT 1`,
		bot.ID, defaultMaxOpen, bot.UserID, defaultMaxNotional,
	).Scan(&maxOpen, &maxNotionalRaw)

	return maxOpen, mustDecimal(maxNotionalRaw)
}

func orderRiskState(open []openOrder) (map[string]bool, map[int]bool, decimal.Decimal, decimal.Decimal) {
	atPrice := map[string]bool{}
	atSequence := map[int]bool{}
	openBuyNotional := decimal.Zero()
	reservedSell := decimal.Zero()

	for _, order := range open {
		// A pending row has not necessarily reached the exchange yet; it must
		// not block its own lookup-before-retry path.
		if order.Status == "pending" {
			continue
		}
		atPrice[order.Price.String()] = true
		if order.Sequence != nil {
			atSequence[*order.Sequence] = true
		}
		remaining := order.Quantity.Sub(order.FilledQuantity)
		if order.Side == "buy" {
			openBuyNotional = openBuyNotional.Add(order.Price.Mul(remaining))
		} else {
			reservedSell = reservedSell.Add(remaining)
		}
	}

	return atPrice, atSequence, openBuyNotional, reservedSell
}

// roundOrderValue truncates toward zero to the venue's tick/step size. When a
// venue only reports decimal precision, that precision is used as the step.
func roundOrderValue(value decimal.Decimal, step decimal.Decimal, precision int) (decimal.Decimal, bool) {
	if step.IsPositive() {
		rounded, err := value.TruncateToStep(step)

		return rounded, err == nil
	}

	if precision < 0 || precision > decimal.Scale {
		return decimal.Decimal{}, false
	}

	rounded, err := value.Round(precision, decimal.RoundDown)

	return rounded, err == nil
}

func isNotFound(err error) bool {
	var exchangeError *exchange.Error

	return errors.As(err, &exchangeError) && exchangeError.Type == exchange.ErrorNotFound
}

// applyLiveOrder stores an exchange order snapshot and applies any new fill to
// inventory. previousFilled is the local filled quantity before this snapshot.
func (r *Runtime) applyLiveOrder(ctx context.Context, bot *Bot, orderID int64, previousFilled decimal.Decimal, remote exchange.Order) error {
	delta := remote.FilledQuantity.Sub(previousFilled)
	if delta.IsNegative() {
		delta = decimal.Zero()
	}

	tx, err := r.pool.Begin(ctx)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback(ctx) }()

	var activeBotID int64
	if err := tx.QueryRow(ctx,
		`SELECT id FROM bots WHERE id = $1 AND deleted_at IS NULL FOR UPDATE`,
		bot.ID,
	).Scan(&activeBotID); err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		return err
	}

	closed := remote.Status == exchange.StatusFilled || remote.Status == exchange.StatusCanceled || remote.Status == exchange.StatusRejected
	if _, err := tx.Exec(ctx,
		`UPDATE orders SET exchange_order_id = NULLIF($2::text, ''), status = $3::text, filled_quantity = $4::numeric,
		        last_error = NULL, closed_at = CASE WHEN $5::boolean THEN now() ELSE closed_at END, updated_at = now()
		 WHERE id = $1`,
		orderID, remote.ExchangeOrderID, string(remote.Status), remote.FilledQuantity.String(), closed,
	); err != nil {
		return err
	}

	appliedFill := false
	if delta.IsPositive() {
		price := remote.AveragePrice
		if !price.IsPositive() {
			price = remote.Price
		}

		fee := decimal.Zero()
		if remote.Fee != nil && remote.FilledQuantity.IsPositive() {
			if computed, divErr := remote.Fee.Mul(delta).Div(remote.FilledQuantity); divErr == nil {
				fee = computed
			}
		}

		fill := paperfill.Fill{
			ClientOrderID: remote.ClientOrderID,
			Side:          paperfill.Side(strings.ToLower(string(remote.Side))),
			Price:         price,
			Quantity:      delta,
			Fee:           fee,
		}
		fillID := remote.ClientOrderID + "-" + remote.FilledQuantity.String()

		var inserted int64
		err := tx.QueryRow(ctx,
			`INSERT INTO fills (bot_id, order_id, exchange, exchange_fill_id, side, price, quantity, fee, fee_currency, filled_at, created_at, updated_at)
			 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, now(), now(), now())
			 ON CONFLICT (exchange, exchange_fill_id) DO NOTHING
			 RETURNING id`,
			bot.ID, orderID, bot.Exchange, fillID, string(fill.Side), fill.Price.String(), fill.Quantity.String(), fill.Fee.String(), remote.FeeCurrency,
		).Scan(&inserted)
		if err != nil && !errors.Is(err, pgx.ErrNoRows) {
			return err
		}

		if !errors.Is(err, pgx.ErrNoRows) {
			appliedFill = true
			if stats := tickStatsFrom(ctx); stats != nil {
				stats.liveFills++
			}

			// Only a fee denominated in the quote asset reduces quote
			// inventory. A BNB/other-asset fee is recorded on the fill but not
			// silently converted into the quote balance at an invented price.
			inventoryFill := fill
			if remote.FeeCurrency != "" && !strings.EqualFold(remote.FeeCurrency, quoteAsset(bot.Symbol)) {
				inventoryFill.Fee = decimal.Zero()
			}

			newBase, newQuote, newAverage, newRealized, newFees := applyInventory(bot, inventoryFill)
			baseDelta := newBase.Sub(bot.Base)
			quoteDelta := newQuote.Sub(bot.Quote)
			if _, err := tx.Exec(ctx,
				`UPDATE bots SET base_inventory = $2, quote_inventory = $3, average_entry_price = $4,
				        realized_pnl = $5, fees_paid = $6, updated_at = now() WHERE id = $1`,
				bot.ID, newBase.String(), newQuote.String(), newAverage.String(), newRealized.String(), newFees.String(),
			); err != nil {
				return err
			}
			if _, err := tx.Exec(ctx,
				`INSERT INTO inventory_ledger_entries
				 (bot_id, fill_id, side, quantity, price, base_delta, quote_delta, base_balance, quote_balance, average_entry_price, realized_pnl, created_at)
				 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, now())`,
				bot.ID, inserted, string(fill.Side), fill.Quantity.String(), fill.Price.String(),
				baseDelta.String(), quoteDelta.String(), newBase.String(), newQuote.String(), newAverage.String(), newRealized.String(),
			); err != nil {
				return err
			}
			bot.Base, bot.Quote, bot.AverageEntry, bot.Realized, bot.Fees = newBase, newQuote, newAverage, newRealized, newFees
		}
	}

	// Keep the grid level in sync with the exchange status.
	state := "open"
	switch remote.Status {
	case exchange.StatusFilled:
		state = "filled"
	case exchange.StatusCanceled, exchange.StatusRejected:
		state = "canceled"
	}
	if _, err := tx.Exec(ctx,
		`UPDATE grid_levels SET state = $2::text, updated_at = now()
		 WHERE id = (SELECT grid_level_id FROM orders WHERE id = $1)`,
		orderID, state,
	); err != nil {
		return err
	}

	if err := tx.Commit(ctx); err != nil {
		return err
	}

	if appliedFill {
		r.activity(ctx, bot.UserID, bot.ID, "order.filled", "info", "Live order filled.", map[string]any{
			"client_order_id": remote.ClientOrderID, "exchange": bot.Exchange,
		})
	}

	return nil
}

// syncLiveOrders reconciles the bot's open/pending live orders with the
// exchange. It serializes with ticks and control commands for the same bot.
func (r *Runtime) syncLiveOrders(ctx context.Context, bot *Bot, force bool) error {
	return withBotLock(ctx, bot.ID, func(ctx context.Context) error {
		return r.syncLiveOrdersLocked(ctx, bot, force)
	})
}

// syncLiveOrdersLocked is the reconciliation body. The caller must hold the
// bot lock (a tick already does). It deliberately does not depend on the live
// gate: a closed gate must still apply fills and cancel resting orders.
func (r *Runtime) syncLiveOrdersLocked(ctx context.Context, bot *Bot, force bool) error {
	if !bot.live() {
		return nil
	}

	if r.orderSyncBlocked(bot.ID) {
		return nil
	}
	if !force && !r.orderSyncDue(bot.ID) {
		return nil
	}

	orders, err := r.openOrders(ctx, bot.ID)
	if err != nil {
		return err
	}
	if len(orders) == 0 {
		r.markOrderSync(bot.ID)

		return nil
	}

	client, err := r.cancelClient(ctx, bot)
	if err != nil {
		return err
	}

	remoteOpen, err := client.OpenOrders(ctx, bot.Symbol)
	if err != nil {
		r.recordOrderSyncError(bot.ID, err)

		return nil
	}

	openByClient := make(map[string]exchange.Order, len(remoteOpen))
	for _, order := range remoteOpen {
		openByClient[order.ClientOrderID] = order
	}

	for _, local := range orders {
		if r.orderSyncBlocked(bot.ID) {
			break
		}

		remote, exists := openByClient[local.ClientOrderID]
		if !exists {
			lookup, lookupErr := client.OrderByClientID(ctx, bot.Symbol, local.ClientOrderID)
			if lookupErr != nil {
				if isNotFound(lookupErr) {
					if err := r.closeMissingLiveOrder(ctx, bot, local); err != nil {
						return err
					}

					continue
				}

				r.recordOrderSyncError(bot.ID, lookupErr)
				r.logger.Warn("live order lookup failed during sync", "bot_id", bot.ID, "error_type", fmt.Sprintf("%T", lookupErr))

				break
			}
			remote = lookup
		}

		if err := r.applyLiveOrder(ctx, bot, local.ID, local.FilledQuantity, remote); err != nil {
			return err
		}
	}

	if !r.orderSyncBlocked(bot.ID) {
		r.markOrderSync(bot.ID)
	}

	return nil
}

// closeMissingLiveOrder treats an order the venue no longer knows as terminal.
// The local row would otherwise stay open forever and block its grid level.
func (r *Runtime) closeMissingLiveOrder(ctx context.Context, bot *Bot, local openOrder) error {
	if local.Status == "pending" {
		// A pending intent was never confirmed: leave it for the
		// lookup-before-retry path instead of guessing that it is gone.
		return nil
	}

	status := "canceled"
	state := "canceled"
	if local.Quantity.IsPositive() && local.FilledQuantity.GreaterThanOrEqual(local.Quantity) {
		status = "filled"
		state = "filled"
	}

	if _, err := r.pool.Exec(ctx,
		`UPDATE orders SET status = $2, closed_at = now(), updated_at = now() WHERE id = $1 AND status IN ('open','partially_filled')`,
		local.ID, status,
	); err != nil {
		return err
	}
	if local.GridLevelID != nil {
		if _, err := r.pool.Exec(ctx,
			`UPDATE grid_levels SET state = $2, updated_at = now() WHERE id = $1 AND state IN ('idle','open')`,
			*local.GridLevelID, state,
		); err != nil {
			return err
		}
	}

	r.activity(ctx, bot.UserID, bot.ID, "order.missing_at_exchange", "warning", "An open order was no longer known by the exchange; it was closed locally.", map[string]any{
		"client_order_id": local.ClientOrderID,
	})

	return nil
}

func (r *Runtime) orderSyncDue(botID int64) bool {
	r.syncMu.Lock()
	defer r.syncMu.Unlock()

	last, ok := r.lastOrderSync[botID]
	if !ok {
		return true
	}

	return r.now().Sub(last) >= r.orderSyncInterval
}

func (r *Runtime) orderSyncBlocked(botID int64) bool {
	r.syncMu.Lock()
	defer r.syncMu.Unlock()

	until, ok := r.orderSyncNotBefore[botID]

	return ok && r.now().Before(until)
}

// recordOrderSyncError honors Retry-After instead of retrying a rate-limited
// venue at the fixed sync cadence.
func (r *Runtime) recordOrderSyncError(botID int64, err error) {
	var exchangeError *exchange.Error
	if !errors.As(err, &exchangeError) || exchangeError.RetryAfter <= 0 {
		return
	}

	r.syncMu.Lock()
	defer r.syncMu.Unlock()

	r.orderSyncNotBefore[botID] = r.now().Add(exchangeError.RetryAfter)
}

func (r *Runtime) markOrderSync(botID int64) {
	r.syncMu.Lock()
	defer r.syncMu.Unlock()

	r.lastOrderSync[botID] = r.now()
	delete(r.orderSyncNotBefore, botID)
}

// syncAllLiveOrders runs at engine start so fills and cancellations that
// happened while the engine was down are applied before the first tick.
func (r *Runtime) syncAllLiveOrders(ctx context.Context, force bool) {
	// Paused bots can still have resting orders, and a stopped bot may have
	// been interrupted mid-cancel. Reconcile anything live with open work.
	rows, err := r.pool.Query(ctx, `SELECT b.id FROM bots b
		WHERE b.deleted_at IS NULL AND b.mode = 'live' AND (
			b.status IN ('starting','running','paused')
			OR EXISTS (SELECT 1 FROM orders o WHERE o.bot_id = b.id AND o.status IN ('pending','open','partially_filled'))
		) ORDER BY b.id`)
	if err != nil {
		r.logger.Error("load live bots for startup reconcile failed", "error_type", fmt.Sprintf("%T", err))

		return
	}
	defer rows.Close()

	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return
		}
		ids = append(ids, id)
	}

	for _, id := range ids {
		bot, err := r.loadBot(ctx, id)
		if err != nil {
			continue
		}
		if err := r.syncLiveOrders(ctx, bot, force); err != nil {
			r.logger.Error("live order reconcile failed", "bot_id", id, "error_type", fmt.Sprintf("%T", err))
		}
	}
}

// liveBalanceAllowsStart applies the free-quote balance check before a live bot
// starts. It never falls back to paper.
func (r *Runtime) liveBalanceAllowsStart(ctx context.Context, bot *Bot) error {
	client, err := r.liveClient(ctx, bot)
	if err != nil {
		return err
	}

	balances, err := client.Balances(ctx)
	if err != nil {
		return err
	}

	quote := quoteAsset(bot.Symbol)
	available := decimal.Zero()
	for _, balance := range balances {
		if balance.Asset == quote {
			available = balance.Free
			break
		}
	}

	if available.LessThan(bot.Capital) {
		return fmt.Errorf("insufficient %s balance for live bot %d: required %s, available %s", quote, bot.ID, bot.Capital.String(), available.String())
	}

	return nil
}

func (r *Runtime) cancelLiveOrder(ctx context.Context, bot *Bot, order openOrder) (bool, error) {
	client, err := r.cancelClient(ctx, bot)
	if err != nil {
		return false, err
	}

	remote, err := client.Cancel(ctx, bot.Symbol, order.ExchangeOrderID, order.ClientOrderID)
	if err == nil {
		// The cancel response is the final exchange snapshot. Apply it before
		// the caller marks the remainder canceled, so a fill that raced the
		// cancel is never discarded.
		if err := r.applyLiveOrder(ctx, bot, order.ID, order.FilledQuantity, remote); err != nil {
			return false, err
		}

		return remote.Status == exchange.StatusCanceled || remote.Status == exchange.StatusRejected, nil
	}
	if !isNotFound(err) {
		return false, err
	}

	// The venue may have already filled or canceled the order, or the cancel
	// landed while the response was lost. Look it up before declaring victory.
	if order.ClientOrderID == "" {
		return false, nil
	}

	remote, lookupErr := client.OrderByClientID(ctx, bot.Symbol, order.ClientOrderID)
	if lookupErr == nil {
		if err := r.applyLiveOrder(ctx, bot, order.ID, order.FilledQuantity, remote); err != nil {
			return false, err
		}

		return remote.Status == exchange.StatusCanceled || remote.Status == exchange.StatusRejected, nil
	}
	if isNotFound(lookupErr) {
		return true, nil
	}

	return false, lookupErr
}
