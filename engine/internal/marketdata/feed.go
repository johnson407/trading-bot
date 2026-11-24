// Package marketdata provides the engine's public exchange market-data feed.
//
// It subscribes to Binance's public WebSocket streams (<symbol>@trade and
// <symbol>@bookTicker), reconnects with exponential backoff, detects trade-id
// gaps and unexpected quiet periods, and recovers with a public REST ticker
// snapshot before resubscribing. While the socket is unavailable it polls the
// public REST ticker endpoint so a last price remains available.
//
// The package never uses API keys, signed endpoints, user-data streams, or
// private credentials. It is inert unless both a WebSocket and a REST base URL
// are configured; the shipped configuration leaves both empty so the process
// opens no exchange connection.
package marketdata

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

// Mode is the current market-data transport.
type Mode string

const (
	// ModeIdle means no feed is running (no configured URLs or no symbols).
	ModeIdle Mode = "idle"
	// ModeWebSocket means the public stream is connected.
	ModeWebSocket Mode = "websocket"
	// ModeREST means the public REST ticker is the fallback transport.
	ModeREST Mode = "rest"
)

// Event types recorded when the feed changes transport. They carry no secret
// or URL; operators use them (and the matching structured log line) to see a
// degraded feed.
const (
	EventDegraded  = "marketdata.degraded"
	EventRecovered = "marketdata.recovered"
)

// Recovery reasons. These are safe to log and store; they never contain a URL
// or credential.
const (
	ReasonWebSocketUnavailable = "websocket_unavailable"
	ReasonDisconnected         = "websocket_disconnected"
	ReasonTradeGap             = "trade_gap"
	ReasonQuietPeriod          = "quiet_period"
)

// Ticker is a normalized public quote. Prices stay decimal strings so callers
// keep exact exchange precision and never introduce floats into money paths.
type Ticker struct {
	Symbol    string
	Last      string
	Bid       string
	Ask       string
	UpdatedAt time.Time
}

// Status describes the transport in use. Degraded is true while the REST
// fallback is active.
type Status struct {
	Mode          Mode
	Degraded      bool
	Reason        string
	Symbols       []string
	Since         time.Time
	LastMessageAt time.Time
}

// Event is a secret-free state change suitable for logs or engine events.
type Event struct {
	Type    string
	Mode    Mode
	Reason  string
	Symbols int
	At      time.Time
}

// Config controls the public market-data feed. Zero duration fields receive
// conservative defaults in New.
type Config struct {
	WSURL            string
	RESTURL          string
	QuietAfter       time.Duration
	RESTPollInterval time.Duration
	ReconnectMin     time.Duration
	ReconnectMax     time.Duration
	RequestTimeout   time.Duration
}

// Configured reports whether both public endpoints are set. An empty
// configuration is the phase-3 default and keeps the feed idle.
func (c Config) Configured() bool {
	return strings.TrimSpace(c.WSURL) != "" && strings.TrimSpace(c.RESTURL) != ""
}

// EventSink receives secret-free state changes. It is optional.
type EventSink func(Event)

// Feed is a public market-data feed. Create it with New and run it with Run.
type Feed struct {
	cfg    Config
	logger *slog.Logger
	sink   EventSink
	rest   *RESTClient
	now    func() time.Time

	mu          sync.RWMutex
	tickers     map[string]Ticker
	lastTradeID map[string]int64
	status      Status
}

// New returns a feed. The caller must call Run to open any connection. New
// applies defaults but does not validate; config.Load validates the
// environment, and tests may construct Config directly.
func New(cfg Config, logger *slog.Logger, sink EventSink) *Feed {
	if logger == nil {
		logger = slog.Default()
	}

	cfg = withDefaults(cfg)

	return &Feed{
		cfg:         cfg,
		logger:      logger,
		sink:        sink,
		rest:        &RESTClient{BaseURL: cfg.RESTURL, HTTPClient: &http.Client{Timeout: cfg.RequestTimeout}},
		now:         time.Now,
		tickers:     make(map[string]Ticker),
		lastTradeID: make(map[string]int64),
		status:      Status{Mode: ModeIdle, Since: time.Now()},
	}
}

func withDefaults(cfg Config) Config {
	if cfg.QuietAfter <= 0 {
		cfg.QuietAfter = 30 * time.Second
	}

	if cfg.RESTPollInterval <= 0 {
		cfg.RESTPollInterval = 5 * time.Second
	}

	if cfg.ReconnectMin <= 0 {
		cfg.ReconnectMin = 500 * time.Millisecond
	}

	if cfg.ReconnectMax < cfg.ReconnectMin {
		cfg.ReconnectMax = 30 * time.Second
	}

	if cfg.RequestTimeout <= 0 {
		cfg.RequestTimeout = 10 * time.Second
	}

	return cfg
}

// Run subscribes to the public streams for symbols and blocks until ctx is
// cancelled. With no configured endpoints or no symbols it stays idle and
// opens no connection. It never requires credentials and never places orders.
func (f *Feed) Run(ctx context.Context, symbols []string) error {
	symbols = normalizeSymbols(symbols)

	if !f.cfg.Configured() || len(symbols) == 0 {
		f.setIdle(symbols)
		return nil
	}

	backoff := f.cfg.ReconnectMin

	for {
		if ctx.Err() != nil {
			f.setIdle(symbols)
			return nil
		}

		connection, err := f.dial(ctx, symbols)
		if err != nil {
			if ctx.Err() != nil {
				f.setIdle(symbols)
				return nil
			}

			// The socket could not be established: switch to periodic public
			// REST tickers and keep retrying with backoff.
			f.markDegraded(ReasonWebSocketUnavailable, symbols)
			f.fetchREST(ctx, symbols)

			if !f.wait(ctx, backoff, symbols) {
				f.setIdle(symbols)
				return nil
			}

			backoff = nextBackoff(backoff, f.cfg.ReconnectMax)
			continue
		}

		f.markConnected(symbols)
		backoff = f.cfg.ReconnectMin

		reason, received := f.read(ctx, connection, symbols)
		_ = connection.Close()

		if ctx.Err() != nil {
			f.setIdle(symbols)
			return nil
		}

		// Any disconnect, gap, or quiet period is repaired with a public REST
		// snapshot before the next subscription attempt.
		f.markDegraded(reason, symbols)
		f.fetchREST(ctx, symbols)

		if !f.wait(ctx, backoff, symbols) {
			f.setIdle(symbols)
			return nil
		}

		if !received {
			backoff = nextBackoff(backoff, f.cfg.ReconnectMax)
		}
	}
}

// Snapshot returns the latest normalized quote for symbol.
func (f *Feed) Snapshot(symbol string) (Ticker, bool) {
	f.mu.RLock()
	defer f.mu.RUnlock()

	ticker, ok := f.tickers[normalizeSymbol(symbol)]

	return ticker, ok
}

// Status returns a copy of the current feed status.
func (f *Feed) Status() Status {
	f.mu.RLock()
	defer f.mu.RUnlock()

	status := f.status
	status.Symbols = append([]string(nil), f.status.Symbols...)

	return status
}

func (f *Feed) dial(ctx context.Context, symbols []string) (*websocket.Conn, error) {
	dialer := *websocket.DefaultDialer
	dialer.HandshakeTimeout = f.cfg.RequestTimeout

	connection, response, err := dialer.DialContext(ctx, f.cfg.WSURL, nil)
	if err != nil {
		if response != nil && response.Body != nil {
			_ = response.Body.Close()
		}

		return nil, fmt.Errorf("open public websocket: %w", err)
	}

	request := subscriptionRequest{
		Method: "SUBSCRIBE",
		Params: streamNames(symbols),
		ID:     1,
	}

	if err := connection.WriteJSON(request); err != nil {
		_ = connection.Close()
		return nil, fmt.Errorf("subscribe to public streams: %w", err)
	}

	return connection, nil
}

// read consumes messages until a recoverable condition occurs. It returns the
// secret-free reason and whether at least one quote was applied. A gap is
// detected from the per-symbol trade id; a quiet period from the read deadline.
func (f *Feed) read(ctx context.Context, connection *websocket.Conn, symbols []string) (string, bool) {
	received := false

	for {
		if ctx.Err() != nil {
			return ReasonDisconnected, received
		}

		if f.cfg.QuietAfter > 0 {
			_ = connection.SetReadDeadline(time.Now().Add(f.cfg.QuietAfter))
		}

		_, payload, err := connection.ReadMessage()
		if err != nil {
			if ctx.Err() != nil {
				return ReasonDisconnected, received
			}

			var netError net.Error
			if errors.As(err, &netError) && netError.Timeout() {
				return ReasonQuietPeriod, received
			}

			return ReasonDisconnected, received
		}

		updated, gap := f.applyMessage(payload, symbols)
		if updated {
			received = true
		}

		if gap {
			return ReasonTradeGap, received
		}
	}
}

// applyMessage normalizes a trade or book-ticker message. Binance bookTicker
// payloads have no event type, so the shape (p for a trade, b/a for book) is
// used to distinguish them. It reports whether a quote changed and whether the
// trade-id sequence skipped.
func (f *Feed) applyMessage(payload []byte, symbols []string) (bool, bool) {
	var message struct {
		Stream     string          `json:"stream"`
		Data       json.RawMessage `json:"data"`
		Event      string          `json:"e"`
		Symbol     string          `json:"s"`
		TradeID    int64           `json:"t"`
		TradePrice string          `json:"p"`
		Bid        string          `json:"b"`
		Ask        string          `json:"a"`
		ID         *int64          `json:"id"`
	}

	if err := json.Unmarshal(payload, &message); err != nil {
		f.logger.Warn("market data message is not JSON", "error_type", fmt.Sprintf("%T", err))
		return false, false
	}

	if len(message.Stream) > 0 {
		if len(message.Data) == 0 {
			return false, false
		}

		if err := json.Unmarshal(message.Data, &message); err != nil {
			f.logger.Warn("market data stream payload is not JSON", "error_type", fmt.Sprintf("%T", err))
			return false, false
		}
	}

	// Subscription acknowledgements carry only an id and a null result.
	if message.ID != nil && message.Symbol == "" {
		return false, false
	}

	symbol := normalizeSymbol(message.Symbol)
	if symbol == "" || !containsSymbol(symbols, symbol) {
		return false, false
	}

	now := f.now()

	if message.TradeID > 0 && message.TradePrice != "" {
		if !validDecimal(message.TradePrice) {
			f.logger.Warn("market data trade has an invalid price", "symbol", symbol)
			return false, false
		}

		f.mu.Lock()
		previous := f.lastTradeID[symbol]
		gap := previous > 0 && message.TradeID > previous+1
		// Ignore stale or duplicate updates; only advance the sequence.
		if message.TradeID <= previous {
			f.mu.Unlock()
			return false, false
		}

		f.lastTradeID[symbol] = message.TradeID
		ticker := f.tickers[symbol]
		ticker.Symbol = symbol
		ticker.Last = message.TradePrice
		ticker.UpdatedAt = now
		f.tickers[symbol] = ticker
		f.status.LastMessageAt = now
		f.mu.Unlock()

		return true, gap
	}

	if message.Bid != "" || message.Ask != "" {
		if message.Bid != "" && !validDecimal(message.Bid) {
			f.logger.Warn("market data book has an invalid bid", "symbol", symbol)
			return false, false
		}

		if message.Ask != "" && !validDecimal(message.Ask) {
			f.logger.Warn("market data book has an invalid ask", "symbol", symbol)
			return false, false
		}

		f.mu.Lock()
		ticker := f.tickers[symbol]
		ticker.Symbol = symbol
		if message.Bid != "" {
			ticker.Bid = message.Bid
		}
		if message.Ask != "" {
			ticker.Ask = message.Ask
		}
		ticker.UpdatedAt = now
		f.tickers[symbol] = ticker
		f.status.LastMessageAt = now
		f.mu.Unlock()

		return true, false
	}

	return false, false
}

func (f *Feed) fetchREST(ctx context.Context, symbols []string) {
	snapshots, err := f.rest.Fetch(ctx, symbols)
	if err != nil {
		// Log only the error type. URLs, response bodies, and headers must not
		// reach the log.
		f.logger.Warn("market data REST snapshot failed", "error_type", fmt.Sprintf("%T", err), "symbols", len(symbols))
		return
	}

	now := f.now()

	f.mu.Lock()
	for symbol, ticker := range snapshots {
		ticker.UpdatedAt = now
		f.tickers[symbol] = ticker
	}
	f.mu.Unlock()
}

// wait sleeps for duration while polling the REST ticker at RESTPollInterval.
// It returns false when ctx is cancelled.
func (f *Feed) wait(ctx context.Context, duration time.Duration, symbols []string) bool {
	if duration <= 0 {
		duration = time.Millisecond
	}

	deadline := time.NewTimer(duration)
	defer deadline.Stop()

	poll := f.cfg.RESTPollInterval
	if poll <= 0 || poll >= duration {
		select {
		case <-ctx.Done():
			return false
		case <-deadline.C:
			return true
		}
	}

	ticker := time.NewTicker(poll)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return false
		case <-deadline.C:
			return true
		case <-ticker.C:
			f.fetchREST(ctx, symbols)
		}
	}
}

func (f *Feed) markConnected(symbols []string) {
	f.mu.Lock()
	recovered := f.status.Degraded
	// The REST snapshot taken during the outage already covers the gap, so
	// start a fresh trade-id baseline after resubscribing. Keeping the old
	// baseline would turn every reconnect into another false gap.
	f.lastTradeID = make(map[string]int64)
	f.status = Status{
		Mode:          ModeWebSocket,
		Symbols:       append([]string(nil), symbols...),
		Since:         f.now(),
		LastMessageAt: f.status.LastMessageAt,
	}
	f.mu.Unlock()

	if recovered {
		f.emit(Event{Type: EventRecovered, Mode: ModeWebSocket, Symbols: len(symbols), At: f.now()})
	}
}

func (f *Feed) markDegraded(reason string, symbols []string) {
	f.mu.Lock()
	changed := !f.status.Degraded || f.status.Reason != reason
	since := f.status.Since
	if changed {
		since = f.now()
	}
	f.status = Status{
		Mode:          ModeREST,
		Degraded:      true,
		Reason:        reason,
		Symbols:       append([]string(nil), symbols...),
		Since:         since,
		LastMessageAt: f.status.LastMessageAt,
	}
	f.mu.Unlock()

	if changed {
		f.emit(Event{Type: EventDegraded, Mode: ModeREST, Reason: reason, Symbols: len(symbols), At: f.now()})
	}
}

func (f *Feed) setIdle(symbols []string) {
	f.mu.Lock()
	f.status = Status{
		Mode:          ModeIdle,
		Symbols:       append([]string(nil), symbols...),
		Since:         f.now(),
		LastMessageAt: f.status.LastMessageAt,
	}
	f.mu.Unlock()
}

func (f *Feed) emit(event Event) {
	if f.sink != nil {
		f.sink(event)
	}

	switch event.Type {
	case EventDegraded:
		f.logger.Warn("market data degraded; using public REST ticker fallback",
			"event", event.Type,
			"mode", string(event.Mode),
			"reason", event.Reason,
			"symbols", event.Symbols,
		)
	case EventRecovered:
		f.logger.Info("market data recovered; public websocket connected",
			"event", event.Type,
			"mode", string(event.Mode),
			"symbols", event.Symbols,
		)
	}
}

func nextBackoff(current, maximum time.Duration) time.Duration {
	if maximum <= 0 {
		maximum = current
	}

	next := current * 2
	if next <= 0 || next > maximum {
		return maximum
	}

	return next
}

type subscriptionRequest struct {
	Method string   `json:"method"`
	Params []string `json:"params"`
	ID     int      `json:"id"`
}

func streamNames(symbols []string) []string {
	names := make([]string, 0, len(symbols)*2)
	for _, symbol := range symbols {
		lower := strings.ToLower(symbol)
		names = append(names, lower+"@trade", lower+"@bookTicker")
	}

	return names
}

func normalizeSymbols(symbols []string) []string {
	normalized := make([]string, 0, len(symbols))
	seen := make(map[string]struct{}, len(symbols))

	for _, symbol := range symbols {
		symbol = normalizeSymbol(symbol)
		if symbol == "" {
			continue
		}

		if _, ok := seen[symbol]; ok {
			continue
		}

		seen[symbol] = struct{}{}
		normalized = append(normalized, symbol)
	}

	return normalized
}

func normalizeSymbol(symbol string) string {
	return strings.ReplaceAll(strings.ToUpper(strings.TrimSpace(symbol)), "/", "")
}

func containsSymbol(symbols []string, symbol string) bool {
	for _, candidate := range symbols {
		if candidate == symbol {
			return true
		}
	}

	return false
}

// validDecimal accepts a plain decimal string with an optional leading minus.
// It deliberately avoids strconv.ParseFloat so no float enters a price path.
func validDecimal(value string) bool {
	if value == "" {
		return false
	}

	seenDigit := false
	seenDot := false

	for index := 0; index < len(value); index++ {
		switch character := value[index]; {
		case character >= '0' && character <= '9':
			seenDigit = true
		case character == '.' && !seenDot:
			seenDot = true
		case character == '-' && index == 0:
		default:
			return false
		}
	}

	return seenDigit
}
