package risk

import (
	"fmt"
	"strings"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// LiveTradingDisabledError mirrors LiveTradingDisabledException.
type LiveTradingDisabledError struct {
	BotID int64
}

func (e LiveTradingDisabledError) Error() string {
	return fmt.Sprintf("Live trading is disabled by configuration; bot %d cannot start in live mode.", e.BotID)
}

// LiveConfirmationRequiredError mirrors LiveConfirmationRequiredException.
type LiveConfirmationRequiredError struct {
	BotID int64
	Name  string
}

func (e LiveConfirmationRequiredError) Error() string {
	return fmt.Sprintf("Live mode requires confirmation text matching the bot name %q for bot %d.", e.Name, e.BotID)
}

// InsufficientLiveBalanceError mirrors InsufficientLiveBalanceException.
type InsufficientLiveBalanceError struct {
	Asset     string
	Required  decimal.Decimal
	Available decimal.Decimal
	BotID     int64
}

func (e InsufficientLiveBalanceError) Error() string {
	return fmt.Sprintf("Insufficient %s balance for live bot %d: required %s, available %s.", e.Asset, e.BotID, e.Required.String(), e.Available.String())
}

// BalanceProvider returns the free balance per asset. The engine never talks to
// an exchange itself; callers inject a read-only balance source.
type BalanceProvider func(bot *Bot) (map[string]decimal.Decimal, error)

// LiveModeGuard ports App\Domain\Risk\LiveModeGuard. Enabled is the
// TRADING_LIVE_ENABLED gate and must default to false.
type LiveModeGuard struct {
	Store     *Store
	Enabled   bool
	Balances  BalanceProvider
	Allowlist *ExchangeAllowlist
}

// NewLiveModeGuard builds a guard. enabled must come from config, whose
// TRADING_LIVE_ENABLED default is false.
func NewLiveModeGuard(store *Store, enabled bool, balances BalanceProvider) *LiveModeGuard {
	return &LiveModeGuard{Store: store, Enabled: enabled, Balances: balances}
}

// AssertCanStart applies the live gate, confirmation, and free-quote balance
// checks. Paper bots always pass and never read balances.
func (g *LiveModeGuard) AssertCanStart(bot *Bot, confirmation *string) error {
	if !bot.IsLive() {
		return nil
	}

	if err := g.AssertTradingAllowed(bot); err != nil {
		return err
	}

	if confirmation == nil || *confirmation != fmt.Sprintf("%s on %s", bot.Name, bot.Exchange) {
		return LiveConfirmationRequiredError{BotID: bot.ID, Name: bot.Name}
	}

	var balances map[string]decimal.Decimal
	if g.Balances != nil {
		loaded, err := g.Balances(bot)
		if err != nil {
			return err
		}
		balances = loaded
	}

	asset := quoteAsset(bot.Symbol)
	available := decimal.Zero()
	if balance, ok := balances[asset]; ok {
		available = balance
	}

	if available.LessThan(bot.CapitalAllocation) {
		return InsufficientLiveBalanceError{
			Asset:     asset,
			Required:  bot.CapitalAllocation,
			Available: available,
			BotID:     bot.ID,
		}
	}

	return nil
}

// AssertTradingAllowed rejects a live bot when the configuration gate is off.
// It is called before any private exchange call on every tick.
func (g *LiveModeGuard) AssertTradingAllowed(bot *Bot) error {
	if !bot.IsLive() {
		return nil
	}

	if !g.Enabled {
		return LiveTradingDisabledError{BotID: bot.ID}
	}

	return nil
}

// StartBot ports the StartBot action: a running bot is returned unchanged,
// otherwise the live checks run and the bot moves to running. A rejected start
// leaves the bot idle; it is never converted to paper.
func (g *LiveModeGuard) StartBot(bot *Bot, confirmation *string) error {
	if bot.Status == StatusRunning {
		return nil
	}

	if g.Allowlist != nil {
		if err := g.Allowlist.AssertStart(bot); err != nil {
			return err
		}
	}

	if err := g.AssertCanStart(bot, confirmation); err != nil {
		return err
	}

	bot.Status = StatusRunning
	bot.LastError = nil
	g.Store.RecordActivity(bot, "bot.started", "info", "Bot started", map[string]any{
		"mode":   bot.Mode,
		"symbol": bot.Symbol,
	})

	return nil
}

// PauseForDisabledLiveMode is the tick path when the gate closes after a live
// bot has started: pause, record the critical event, and cancel open orders.
// It never falls back to paper and never calls the market or place endpoints.
func (g *LiveModeGuard) PauseForDisabledLiveMode(bot *Bot, canceler OrderCanceler) error {
	err := LiveTradingDisabledError{BotID: bot.ID}
	message := err.Error()

	bot.Status = StatusPaused
	bot.LastError = &message

	g.Store.RecordActivity(bot, "bot.live_trading_disabled", "error", "Live trading was disabled; the bot was paused.", nil)
	g.Store.RecordRisk(bot, "risk.live_trading_disabled", SeverityCritical, message, nil)

	for _, failure := range g.Store.CancelOpenOrders(bot.ID, canceler) {
		g.Store.RecordRisk(bot, "risk.live_cancel_failed", SeverityCritical, "A live order could not be canceled after live trading was disabled.", map[string]any{
			"order_id":        failure.Order.ID,
			"client_order_id": failure.Order.ClientOrderID,
			"error":           failure.Err.Error(),
		})
	}

	return err
}

func quoteAsset(symbol string) string {
	parts := strings.Split(symbol, "/")
	if len(parts) < 2 {
		return "USDT"
	}

	return parts[1]
}
