package marketdata

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// tickerPath is Binance's public 24-hour ticker endpoint. It returns last, bid,
// and ask without an API key and is the REST fallback required by the design.
const tickerPath = "/api/v3/ticker/24hr"

const maxTickerResponseBytes = 1 << 20

// RESTClient fetches public ticker snapshots. It never sends credentials.
type RESTClient struct {
	BaseURL    string
	HTTPClient *http.Client
}

type restTicker struct {
	Symbol    string `json:"symbol"`
	LastPrice string `json:"lastPrice"`
	BidPrice  string `json:"bidPrice"`
	AskPrice  string `json:"askPrice"`
}

// Fetch requests a public snapshot for symbols. Binance accepts the symbols
// JSON-array query parameter for one or many symbols, so a poll is one request.
func (c *RESTClient) Fetch(ctx context.Context, symbols []string) (map[string]Ticker, error) {
	if c == nil || strings.TrimSpace(c.BaseURL) == "" {
		return nil, fmt.Errorf("market data REST base URL is not configured")
	}

	symbols = normalizeSymbols(symbols)
	if len(symbols) == 0 {
		return map[string]Ticker{}, nil
	}

	encodedSymbols, err := json.Marshal(symbols)
	if err != nil {
		return nil, fmt.Errorf("encode ticker symbols: %w", err)
	}

	query := url.Values{}
	query.Set("symbols", string(encodedSymbols))

	endpoint := strings.TrimRight(c.BaseURL, "/") + tickerPath + "?" + query.Encode()

	request, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, fmt.Errorf("build public ticker request: %w", err)
	}

	client := c.HTTPClient
	if client == nil {
		client = http.DefaultClient
	}

	response, err := client.Do(request)
	if err != nil {
		return nil, fmt.Errorf("fetch public ticker: %w", err)
	}
	defer func() { _ = response.Body.Close() }()

	if response.StatusCode != http.StatusOK {
		_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 4096))
		return nil, fmt.Errorf("public ticker returned HTTP %d", response.StatusCode)
	}

	body, err := io.ReadAll(io.LimitReader(response.Body, maxTickerResponseBytes))
	if err != nil {
		return nil, fmt.Errorf("read public ticker: %w", err)
	}

	raw, err := decodeTickers(body)
	if err != nil {
		return nil, err
	}

	now := time.Now()
	tickers := make(map[string]Ticker, len(raw))

	for _, item := range raw {
		var parsed restTicker
		if err := json.Unmarshal(item, &parsed); err != nil {
			return nil, fmt.Errorf("decode public ticker entry: %w", err)
		}

		symbol := normalizeSymbol(parsed.Symbol)
		if symbol == "" {
			continue
		}

		if !validDecimal(parsed.LastPrice) {
			return nil, fmt.Errorf("public ticker for %s has an invalid last price", symbol)
		}

		if parsed.BidPrice != "" && !validDecimal(parsed.BidPrice) {
			return nil, fmt.Errorf("public ticker for %s has an invalid bid price", symbol)
		}

		if parsed.AskPrice != "" && !validDecimal(parsed.AskPrice) {
			return nil, fmt.Errorf("public ticker for %s has an invalid ask price", symbol)
		}

		tickers[symbol] = Ticker{
			Symbol:    symbol,
			Last:      parsed.LastPrice,
			Bid:       parsed.BidPrice,
			Ask:       parsed.AskPrice,
			UpdatedAt: now,
		}
	}

	return tickers, nil
}

// decodeTickers accepts either the single-symbol object or the multi-symbol
// array returned by the public endpoint.
func decodeTickers(body []byte) ([]json.RawMessage, error) {
	trimmed := strings.TrimSpace(string(body))

	switch {
	case strings.HasPrefix(trimmed, "{"):
		return []json.RawMessage{json.RawMessage(trimmed)}, nil
	case strings.HasPrefix(trimmed, "["):
		var raw []json.RawMessage
		if err := json.Unmarshal([]byte(trimmed), &raw); err != nil {
			return nil, fmt.Errorf("decode public ticker response: %w", err)
		}

		return raw, nil
	default:
		return nil, fmt.Errorf("public ticker response is not a JSON object or array")
	}
}
