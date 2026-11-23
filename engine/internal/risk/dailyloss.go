package risk

import (
	"fmt"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

const dailyLossBaselineKey = "daily_loss_baseline"

// DailyLossMonitor ports App\Domain\Risk\DailyLossMonitor. It pauses bots and
// cancels their open orders when the per-bot or account-wide daily loss limit
// is reached, and it never resumes a paused bot automatically.
type DailyLossMonitor struct {
	Store    *Store
	Canceler OrderCanceler
}

// NewDailyLossMonitor builds a monitor. A nil canceler uses the store's paper
// canceler.
func NewDailyLossMonitor(store *Store, canceler OrderCanceler) *DailyLossMonitor {
	return &DailyLossMonitor{Store: store, Canceler: canceler}
}

// Evaluate checks the bot's daily loss first, then the account-wide loss.
func (m *DailyLossMonitor) Evaluate(bot *Bot, mark *decimal.Decimal) {
	limit := m.Store.EffectiveLimit(bot, true)
	loss := m.dailyLoss(bot, limit, mark)

	if loss.GreaterThanOrEqual(limit.DailyLossLimit) {
		m.pause(bot, loss, limit.DailyLossLimit, "bot")
	}

	userLimit := m.Store.EnsureUserLimit(bot.UserID)
	total := m.userDailyLoss(bot, userLimit, mark)

	if total.GreaterThanOrEqual(userLimit.DailyLossLimit) {
		for _, userBot := range m.Store.BotsForUser(bot.UserID) {
			m.pause(userBot, total, userLimit.DailyLossLimit, "user")
		}
	}
}

func (m *DailyLossMonitor) dailyLoss(bot *Bot, limit *Limit, mark *decimal.Decimal) decimal.Decimal {
	baseline := m.resetIfNeeded(bot, limit)
	realized := bot.RealizedPnL.Sub(baseline)
	pnl := realized

	if mark != nil {
		pnl = pnl.Add(unrealizedPnL(bot, *mark))
	}

	if pnl.IsNegative() {
		return pnl.Abs()
	}

	return decimal.Zero()
}

func (m *DailyLossMonitor) userDailyLoss(currentBot *Bot, limit *Limit, mark *decimal.Decimal) decimal.Decimal {
	baseline := m.resetUserIfNeeded(limit.UserID)

	realized := decimal.Zero()
	unrealized := decimal.Zero()

	for _, userBot := range m.Store.BotsForUser(limit.UserID) {
		realized = realized.Add(userBot.RealizedPnL)

		if botMark := m.markFor(userBot, currentBot.ID, mark); botMark != nil {
			unrealized = unrealized.Add(unrealizedPnL(userBot, *botMark))
		}
	}

	pnl := realized.Sub(baseline).Add(unrealized)
	if pnl.IsNegative() {
		return pnl.Abs()
	}

	return decimal.Zero()
}

// resetIfNeeded resets a bot-scope override once per UTC day, or falls back to
// the engine-owned bot.settings baseline when the bot has no override.
func (m *DailyLossMonitor) resetIfNeeded(bot *Bot, limit *Limit) decimal.Decimal {
	if limit.Scope != "bot" {
		return m.botSettingsBaseline(bot)
	}

	today := m.Store.StartOfDayUTC()
	if !limit.DayStart.IsZero() && !limit.DayStart.Before(today) {
		return limit.DayStartRealizedPnL
	}

	limit.DayStart = today
	limit.DayStartRealizedPnL = bot.RealizedPnL

	return bot.RealizedPnL
}

// resetUserIfNeeded resets the account-wide baseline once per UTC day.
func (m *DailyLossMonitor) resetUserIfNeeded(userID int64) decimal.Decimal {
	limit := m.Store.EnsureUserLimit(userID)
	today := m.Store.StartOfDayUTC()

	if !limit.DayStart.IsZero() && !limit.DayStart.Before(today) {
		return limit.DayStartRealizedPnL
	}

	realized := decimal.Zero()
	for _, bot := range m.Store.BotsForUser(userID) {
		realized = realized.Add(bot.RealizedPnL)
	}

	limit.DayStart = today
	limit.DayStartRealizedPnL = realized

	return realized
}

// botSettingsBaseline keeps the per-bot baseline in engine-owned settings so a
// bot without an override does not mutate the user-wide limit row.
func (m *DailyLossMonitor) botSettingsBaseline(bot *Bot) decimal.Decimal {
	today := m.Store.StartOfDayUTC().Format("2006-01-02")

	if bot.Settings == nil {
		bot.Settings = map[string]any{}
	}

	if baseline, ok := bot.Settings[dailyLossBaselineKey].(map[string]any); ok {
		if day, _ := baseline["day"].(string); day == today {
			if realized, ok := baseline["realized"].(string); ok {
				if value, err := decimal.Parse(realized); err == nil {
					return value
				}
			}

			return decimal.Zero()
		}
	}

	baseline := map[string]any{
		"day":      today,
		"realized": fixed16(bot.RealizedPnL),
	}
	bot.Settings[dailyLossBaselineKey] = baseline

	return bot.RealizedPnL
}

func (m *DailyLossMonitor) markFor(bot *Bot, currentBotID int64, mark *decimal.Decimal) *decimal.Decimal {
	if bot.ID == currentBotID && mark != nil {
		return mark
	}

	if raw, ok := bot.Settings["mark_price"].(string); ok {
		if value, err := decimal.Parse(raw); err == nil && value.IsPositive() {
			return &value
		}
	}

	if bot.AverageEntryPrice.IsPositive() {
		value := bot.AverageEntryPrice
		return &value
	}

	return nil
}

func (m *DailyLossMonitor) pause(bot *Bot, loss, limit decimal.Decimal, scope string) {
	if bot.Status == StatusPaused || bot.Status == StatusStopped {
		return
	}

	message := fmt.Sprintf("Daily loss limit breached (%s).", scope)
	bot.Status = StatusPaused
	bot.LastError = &message

	for _, failure := range m.Store.CancelOpenOrders(bot.ID, m.Canceler) {
		m.Store.RecordRisk(bot, "risk.daily_loss_cancel_failed", SeverityCritical, "An order could not be canceled after the daily loss limit was breached.", map[string]any{
			"order_id":        failure.Order.ID,
			"client_order_id": failure.Order.ClientOrderID,
			"error":           failure.Err.Error(),
		})
	}

	context := map[string]any{
		"scope": scope,
		"loss":  loss.String(),
		"limit": limit.String(),
	}

	m.Store.RecordRisk(bot, "risk.daily_loss_limit", SeverityCritical, "Daily loss limit breached; bot paused.", context)
	m.Store.RecordActivity(bot, "bot.daily_loss_paused", "error", "Bot paused after breaching the daily loss limit.", context)
}

func unrealizedPnL(bot *Bot, mark decimal.Decimal) decimal.Decimal {
	if bot.BaseInventory.IsZero() {
		return decimal.Zero()
	}

	return mark.Sub(bot.AverageEntryPrice).Mul(bot.BaseInventory)
}

// StartOfDayUTC is exported for callers that need the same day boundary as the
// monitor.
func StartOfDayUTC(t time.Time) time.Time {
	return startOfDayUTC(t)
}
