package runtime

import (
	"context"
	"encoding/json"
	"time"

	"github.com/jackc/pgx/v5"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/grid"
	"github.com/johnson407/trading-bot/engine/internal/risk"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

// Bot is the runtime view of a bot row.
type Bot struct {
	ID                int64
	UserID            int64
	Name              string
	Mode              string
	Status            string
	DesiredStatus     string
	KillSwitchAt      *time.Time
	StrategyReset     bool
	CancelRetry       bool
	Exchange          string
	Symbol            string
	StrategyKey       string
	Capital           decimal.Decimal
	Base              decimal.Decimal
	Quote             decimal.Decimal
	AverageEntry      decimal.Decimal
	Realized          decimal.Decimal
	Fees              decimal.Decimal
	FeeRate           decimal.Decimal
	TickInterval      int
	LastTickAt        *time.Time
	LastSuccessfulAt  *time.Time
	LastError         *string
	Settings          map[string]any
	StopLossPercent   *string
	TakeProfitPercent *string
	RangeLow          *string
	RangeHigh         *string
}

func (b *Bot) live() bool { return b.Mode == "live" }

func (r *Runtime) loadBot(ctx context.Context, id int64) (*Bot, error) {
	var bot Bot
	var settings []byte
	var capital, base, quote, average, realized, fees, feeRate string

	err := r.pool.QueryRow(ctx,
		`SELECT id, user_id, name, mode, status, desired_status, kill_switch_requested_at, strategy_reset_pending, cancel_retry_requested,
		        exchange, symbol, strategy_key,
		        capital_allocation::text, base_inventory::text, quote_inventory::text,
		        average_entry_price::text, realized_pnl::text, fees_paid::text, fee_rate::text,
		        tick_interval_seconds, last_tick_at, last_successful_tick_at, last_error, settings,
		        stop_loss_percent::text, take_profit_percent::text, range_low::text, range_high::text
		 FROM bots WHERE id = $1 AND deleted_at IS NULL`,
		id,
	).Scan(
		&bot.ID, &bot.UserID, &bot.Name, &bot.Mode, &bot.Status, &bot.DesiredStatus,
		&bot.KillSwitchAt, &bot.StrategyReset, &bot.CancelRetry,
		&bot.Exchange, &bot.Symbol, &bot.StrategyKey,
		&capital, &base, &quote, &average, &realized, &fees, &feeRate,
		&bot.TickInterval, &bot.LastTickAt, &bot.LastSuccessfulAt, &bot.LastError, &settings,
		&bot.StopLossPercent, &bot.TakeProfitPercent, &bot.RangeLow, &bot.RangeHigh,
	)
	if err != nil {
		return nil, err
	}

	bot.Capital = mustDecimal(capital)
	bot.Base = mustDecimal(base)
	bot.Quote = mustDecimal(quote)
	bot.AverageEntry = mustDecimal(average)
	bot.Realized = mustDecimal(realized)
	bot.Fees = mustDecimal(fees)
	bot.FeeRate = mustDecimal(feeRate)
	bot.Settings = map[string]any{}
	if len(settings) > 0 {
		_ = json.Unmarshal(settings, &bot.Settings)
	}

	return &bot, nil
}

func mustDecimal(raw string) decimal.Decimal {
	value, err := decimal.Parse(raw)
	if err != nil {
		return decimal.Zero()
	}
	return value
}

func (b *Bot) riskBot() *risk.Bot {
	return &risk.Bot{
		ID: b.ID, UserID: b.UserID, Name: b.Name, Mode: b.Mode, Status: b.Status,
		Exchange: b.Exchange, Symbol: b.Symbol, CapitalAllocation: b.Capital,
		BaseInventory: b.Base, QuoteInventory: b.Quote, AverageEntryPrice: b.AverageEntry,
		RealizedPnL: b.Realized, FeesPaid: b.Fees, Settings: b.Settings,
		StopLossPercent: b.StopLossPercent, TakeProfitPercent: b.TakeProfitPercent,
		RangeLow: b.RangeLow, RangeHigh: b.RangeHigh, LastError: b.LastError,
	}
}

func (b *Bot) snapshot(open []openOrder) strategy.BotSnapshot {
	orders := make([]strategy.OpenOrderSnapshot, 0, len(open))
	for _, order := range open {
		// A pending live intent has not necessarily reached the exchange. Keep
		// it out of the strategy snapshot so the strategy re-emits the intent;
		// placement then retries the same durable client order ID.
		if order.Status == "pending" {
			continue
		}
		orders = append(orders, strategy.OpenOrderSnapshot{
			ClientOrderID: order.ClientOrderID, Side: strategy.Side(order.Side),
			Price: order.Price, Quantity: order.Quantity, GridLevelSequence: order.Sequence,
		})
	}

	return strategy.BotSnapshot{
		BotID: int(b.ID), BaseInventory: b.Base, QuoteInventory: b.Quote,
		AverageEntryPrice: b.AverageEntry, RealizedPnL: b.Realized,
		CapitalAllocation: b.Capital, OpenOrders: orders,
	}
}

type openOrder struct {
	ID              int64
	ClientOrderID   string
	ExchangeOrderID string
	Side            string
	Price           decimal.Decimal
	Quantity        decimal.Decimal
	FilledQuantity  decimal.Decimal
	Status          string
	GridID          *int64
	GridLevelID     *int64
	Sequence        *int
	OpenedAt        *time.Time
}

func (r *Runtime) openOrders(ctx context.Context, botID int64) ([]openOrder, error) {
	rows, err := r.pool.Query(ctx,
		`SELECT o.id, o.client_order_id, COALESCE(o.exchange_order_id, ''), o.side, o.price::text, o.quantity::text,
		        o.filled_quantity::text, o.status, o.grid_id, o.grid_level_id, gl.sequence, o.opened_at
		 FROM orders o
		 LEFT JOIN grid_levels gl ON gl.id = o.grid_level_id
		 WHERE o.bot_id = $1 AND o.status IN ('pending','open','partially_filled')
		 ORDER BY o.id`,
		botID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var orders []openOrder
	for rows.Next() {
		var order openOrder
		var price, quantity, filled string
		if err := rows.Scan(&order.ID, &order.ClientOrderID, &order.ExchangeOrderID, &order.Side, &price, &quantity,
			&filled, &order.Status, &order.GridID, &order.GridLevelID, &order.Sequence, &order.OpenedAt); err != nil {
			return nil, err
		}
		order.Price = mustDecimal(price)
		order.Quantity = mustDecimal(quantity)
		order.FilledQuantity = mustDecimal(filled)
		orders = append(orders, order)
	}

	return orders, rows.Err()
}

type gridState struct {
	ID         int64
	Center     decimal.Decimal
	Spacing    string
	SpacingVal decimal.Decimal
	LevelCount int
	Levels     []grid.GridLevel
}

func (r *Runtime) activeGrid(ctx context.Context, botID int64) (*gridState, error) {
	var state gridState
	var center, spacing string
	err := r.pool.QueryRow(ctx,
		`SELECT id, center_price::text, spacing_type, spacing_value::text, level_count
		 FROM grids WHERE bot_id = $1 AND status = 'active' ORDER BY id DESC LIMIT 1`,
		botID,
	).Scan(&state.ID, &center, &state.Spacing, &spacing, &state.LevelCount)
	if err != nil {
		if err == pgx.ErrNoRows {
			return nil, nil
		}
		return nil, err
	}
	state.Center = mustDecimal(center)
	state.SpacingVal = mustDecimal(spacing)

	rows, err := r.pool.Query(ctx,
		`SELECT sequence, price::text, side, state FROM grid_levels WHERE grid_id = $1 ORDER BY sequence`,
		state.ID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	for rows.Next() {
		var level grid.GridLevel
		var price string
		if err := rows.Scan(&level.Sequence, &price, &level.Side, &level.State); err != nil {
			return nil, err
		}
		level.Price = mustDecimal(price)
		state.Levels = append(state.Levels, level)
	}

	return &state, rows.Err()
}
