package risk

import (
	"fmt"
	"strings"
)

// ExchangeAllowlist ports the TRADING_EXCHANGES allowlist from
// StoreBotRequest. PHP validates only at creation; the Go engine additionally
// blocks start and placement, closing that documented gap.
type ExchangeAllowlist struct {
	Exchanges []string
	Default   string
}

// NewExchangeAllowlist builds an allowlist. An empty default falls back to
// "binance", matching config trading.default_exchange.
func NewExchangeAllowlist(exchanges []string, defaultExchange string) ExchangeAllowlist {
	defaultExchange = strings.TrimSpace(defaultExchange)
	if defaultExchange == "" {
		defaultExchange = "binance"
	}

	normalized := make([]string, 0, len(exchanges))
	for _, exchange := range exchanges {
		normalized = append(normalized, strings.TrimSpace(exchange))
	}

	return ExchangeAllowlist{Exchanges: normalized, Default: defaultExchange}
}

// Allowed reports a case-sensitive membership check.
func (a ExchangeAllowlist) Allowed(exchange string) bool {
	for _, allowed := range a.Exchanges {
		if exchange == allowed {
			return true
		}
	}

	return false
}

// Prepare trims the submitted exchange and applies the default when blank.
func (a ExchangeAllowlist) Prepare(submitted string) string {
	exchange := strings.TrimSpace(submitted)
	if exchange == "" {
		return a.Default
	}

	return exchange
}

// ValidateCreation mirrors StoreBotRequest's prepare plus validation rules.
func (a ExchangeAllowlist) ValidateCreation(submitted string) (prepared string, valid bool, errs []string) {
	prepared = a.Prepare(submitted)
	if a.Allowed(prepared) {
		return prepared, true, []string{}
	}

	return prepared, false, []string{"The selected exchange is invalid."}
}

// AssertStart blocks a bot whose exchange is not allowlisted.
func (a ExchangeAllowlist) AssertStart(bot *Bot) error {
	if a.Allowed(bot.Exchange) {
		return nil
	}

	return fmt.Errorf("exchange %q is not allowed by TRADING_EXCHANGES", bot.Exchange)
}

// AssertPlace blocks order placement for a non-allowlisted exchange.
func (a ExchangeAllowlist) AssertPlace(bot *Bot) error {
	if a.Allowed(bot.Exchange) {
		return nil
	}

	return fmt.Errorf("exchange %q is not allowed by TRADING_EXCHANGES; refusing to place an order", bot.Exchange)
}
