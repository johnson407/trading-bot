package marketdata

import (
	"context"
	"fmt"
	"log/slog"
	"strings"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// Snapshotter is the part of Feed the price source needs. Tests can supply a
// fake; production passes the public feed.
type Snapshotter interface {
	Snapshot(symbol string) (Ticker, bool)
}

// PriceSource reads the cached public last price from the market-data feed and
// falls back to a single public REST ticker snapshot when the cache is missing
// or stale. It never uses credentials and never places orders.
type PriceSource struct {
	cache      Snapshotter
	rest       *RESTClient
	exchange   string
	staleAfter time.Duration
	logger     *slog.Logger
	now        func() time.Time
}

// NewPriceSource builds a cached price source. A non-positive staleAfter uses
// a conservative 15-second bound. A nil cache or REST client is allowed; the
// source then fails closed instead of inventing a price.
func NewPriceSource(cache Snapshotter, rest *RESTClient, exchange string, staleAfter time.Duration, logger *slog.Logger) *PriceSource {
	if logger == nil {
		logger = slog.Default()
	}

	if strings.TrimSpace(exchange) == "" {
		exchange = "binance"
	}

	if staleAfter <= 0 {
		staleAfter = 15 * time.Second
	}

	return &PriceSource{
		cache:      cache,
		rest:       rest,
		exchange:   exchange,
		staleAfter: staleAfter,
		logger:     logger,
		now:        time.Now,
	}
}

// Last returns the last price for exchange/symbol. A fresh cached quote is used
// directly. A missing or stale quote triggers a public REST snapshot; when
// that also fails, the call fails rather than trading on a too-old price.
func (p *PriceSource) Last(ctx context.Context, exchange, symbol string) (decimal.Decimal, error) {
	if exchange != p.exchange {
		return decimal.Decimal{}, fmt.Errorf("public market data is not available for exchange %s", exchange)
	}

	normalized := normalizeSymbol(symbol)

	if p.cache != nil {
		if ticker, ok := p.cache.Snapshot(normalized); ok {
			observed := ticker.TradeAt
			if observed.IsZero() {
				// REST snapshots and simple caches may only carry UpdatedAt.
				observed = ticker.UpdatedAt
			}
			age := p.now().Sub(observed)
			switch {
			case ticker.Last == "":
				// A book-ticker-only entry has no trade price yet.
			case age < 0:
				p.logger.Warn("market data cache timestamp is in the future; requesting REST snapshot",
					"exchange", exchange, "symbol", normalized)
			case age <= p.staleAfter:
				price, err := decimal.Parse(ticker.Last)
				if err == nil && price.IsPositive() {
					return price, nil
				}
				p.logger.Warn("market data cache has an invalid price; requesting REST snapshot",
					"exchange", exchange, "symbol", normalized)
			default:
				p.logger.Warn("market data cache is stale; requesting REST snapshot",
					"exchange", exchange, "symbol", normalized,
					"age", age.Round(time.Millisecond).String(),
					"stale_after", p.staleAfter.String())
			}
		}
	}

	if p.rest == nil {
		return decimal.Decimal{}, fmt.Errorf("no public REST market data source is configured for %s", normalized)
	}

	tickers, err := p.rest.Fetch(ctx, []string{normalized})
	if err != nil {
		p.logger.Warn("market data REST snapshot failed",
			"exchange", exchange, "symbol", normalized, "error_type", fmt.Sprintf("%T", err))

		return decimal.Decimal{}, fmt.Errorf("public REST snapshot for %s failed: %w", normalized, err)
	}

	ticker, ok := tickers[normalized]
	if !ok {
		return decimal.Decimal{}, fmt.Errorf("public market data omitted %s", normalized)
	}

	price, err := decimal.Parse(ticker.Last)
	if err != nil || !price.IsPositive() {
		return decimal.Decimal{}, fmt.Errorf("public market data returned an invalid price for %s", normalized)
	}

	return price, nil
}
