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
// TradeAt records when Last changed; a book-ticker update refreshes UpdatedAt
// but must not make an old trade price look fresh to a tick.
type Ticker struct {
	Symbol    string
	Last      string
	Bid       string
	Ask       string
	UpdatedAt time.Time
	TradeAt   time.Time
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
// The subscribed symbol set can be changed at runtime with SetSymbols; Run
// applies the difference to the live connection (or dials the new set).
type Feed struct {
	cfg    Config
	logger *slog.Logger
	sink   EventSink
	rest   *RESTClient
	now    func() time.Time

	mu          sync.RWMutex
	desired     []string
	active      []string
	tickers     map[string]Ticker
	lastTradeID map[string]int64
	status      Status
	changed     chan struct{}
	requestID   int

	decodeWarnings    int
	lastDecodeWarning time.Time
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
		changed:     make(chan struct{}, 1),
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

// SetSymbols replaces the desired subscription set. It is safe to call from
// any goroutine. Run applies additions and removals to the live connection on
// its next wake-up; a symbol removed here stops receiving quotes and its cached
// ticker is dropped.
func (f *Feed) SetSymbols(symbols []string) {
	normalized := normalizeSymbols(symbols)

	f.mu.Lock()
	if sameSymbolSet(f.desired, normalized) {
		f.mu.Unlock()
		return
	}

	f.desired = normalized
	f.pruneTickersLocked(normalized)
	f.mu.Unlock()

	select {
	case f.changed <- struct{}{}:
	default:
	}
}

// Symbols returns a copy of the desired subscription set.
func (f *Feed) Symbols() []string {
	f.mu.RLock()
	defer f.mu.RUnlock()

	return append([]string(nil), f.desired...)
}

// Run subscribes to the public streams for the desired symbol set and blocks
// until ctx is cancelled. An optional initial symbol list seeds the set; call
// SetSymbols later to change it. With no configured endpoints the feed stays
// idle and returns immediately. It never requires credentials and never places
// orders.
func (f *Feed) Run(ctx context.Context, initial ...[]string) error {
	if len(initial) > 0 {
		f.SetSymbols(initial[0])
	}

	if !f.cfg.Configured() {
		f.setIdle()
		return nil
	}

	backoff := f.cfg.ReconnectMin

	for {
		if ctx.Err() != nil {
			f.setIdle()
			return nil
		}

		symbols := f.Symbols()
		if len(symbols) == 0 {
			// Stay idle until a bot starts and the symbol synchronizer calls
			// SetSymbols. Opening no connection is intentional.
			f.setIdle()
			if !f.waitForChange(ctx) {
				f.setIdle()
				return nil
			}
			continue
		}

		connection, err := f.dial(ctx, symbols)
		if err != nil {
			if ctx.Err() != nil {
				f.setIdle()
				return nil
			}

			// The socket could not be established: switch to periodic public
			// REST tickers and keep retrying with backoff.
			f.markDegraded(ReasonWebSocketUnavailable)
			f.fetchREST(ctx)

			if !f.wait(ctx, backoff) {
				f.setIdle()
				return nil
			}

			backoff = nextBackoff(backoff, f.cfg.ReconnectMax)
			continue
		}

		f.markConnected(symbols)
		backoff = f.cfg.ReconnectMin

		result := f.session(ctx, connection, symbols)
		_ = connection.Close()

		if ctx.Err() != nil {
			f.setIdle()
			return nil
		}

		if result.reason == reasonNoSymbols {
			f.setIdle()
			continue
		}

		// Any disconnect, gap, or quiet period is repaired with a public REST
		// snapshot before the next subscription attempt.
		f.markDegraded(result.reason)
		f.fetchREST(ctx)

		if !f.wait(ctx, backoff) {
			f.setIdle()
			return nil
		}

		if !result.received {
			backoff = nextBackoff(backoff, f.cfg.ReconnectMax)
		}
	}
}

// reasonNoSymbols is internal: the last running bot stopped and the feed must
// close the socket without recording a degraded event.
const reasonNoSymbols = "subscription_empty"

type sessionResult struct {
	reason   string
	received bool
}

// session reads quotes until the connection fails, the context is canceled, or
// the desired symbol set changes. Subscription updates are written on the same
// connection so existing streams stay open.
func (f *Feed) session(ctx context.Context, connection *websocket.Conn, symbols []string) sessionResult {
	results := make(chan sessionResult, 1)

	go func() {
		reason, received := f.read(ctx, connection)
		results <- sessionResult{reason: reason, received: received}
	}()

	for {
		select {
		case <-ctx.Done():
			return sessionResult{reason: ReasonDisconnected}
		case <-f.changed:
			desired := f.Symbols()
			if len(desired) == 0 {
				return sessionResult{reason: reasonNoSymbols}
			}
			if err := f.updateSubscription(connection, desired); err != nil {
				// Log only the error type: the endpoint and request body must not
				// reach the log.
				f.logger.Warn("market data subscription update failed", "error_type", fmt.Sprintf("%T", err))
				return sessionResult{reason: ReasonDisconnected}
			}
		case result := <-results:
			return result
		}
	}
}

// updateSubscription sends incremental SUBSCRIBE/UNSUBSCRIBE requests for the
// difference between the active and desired sets. The active set changes only
// after the request is written, so quotes for removed symbols are ignored.
func (f *Feed) updateSubscription(connection *websocket.Conn, desired []string) error {
	f.mu.Lock()
	added, removed := symbolDiff(f.active, desired)
	pruned := make([]string, 0, len(removed))
	for _, symbol := range removed {
		if _, ok := f.tickers[symbol]; ok {
			pruned = append(pruned, symbol)
		}
		delete(f.tickers, symbol)
		delete(f.lastTradeID, symbol)
	}
	f.active = append([]string(nil), desired...)
	f.status.Symbols = append([]string(nil), desired...)
	f.mu.Unlock()

	if len(removed) > 0 {
		if err := f.writeRequest(connection, "UNSUBSCRIBE", removed); err != nil {
			return err
		}
	}

	if len(added) > 0 {
		if err := f.writeRequest(connection, "SUBSCRIBE", added); err != nil {
			return err
		}
	}

	if len(pruned) > 0 {
		f.logger.Info("market data unsubscribed idle symbols", "symbols", len(pruned))
	}

	return nil
}

func (f *Feed) writeRequest(connection *websocket.Conn, method string, symbols []string) error {
	return connection.WriteJSON(subscriptionRequest{
		Method: method,
		Params: streamNames(symbols),
		ID:     f.nextRequestID(),
	})
}

// nextRequestID is called only from the Run goroutine, which is the sole
// writer on the WebSocket connection.
func (f *Feed) nextRequestID() int {
	f.requestID++

	return f.requestID
}

func (f *Feed) waitForChange(ctx context.Context) bool {
	select {
	case <-ctx.Done():
		return false
	case <-f.changed:
		return true
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
		ID:     f.nextRequestID(),
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
func (f *Feed) read(ctx context.Context, connection *websocket.Conn) (string, bool) {
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

		updated, gap := f.applyMessage(payload)
		if updated {
			received = true
		}

		if gap {
			return ReasonTradeGap, received
		}
	}
}

// applyMessage normalizes a trade or book-ticker message. It decodes the event
// type before binding fields because Binance trade frames use numeric b/a for
// buyer/seller order IDs, while bookTicker frames use b/a for best bid/ask.
// BookTicker payloads may omit an event type, so the shape is used as a
// fallback. It ignores symbols that are not actively subscribed and reports
// whether a quote changed and whether the trade-id sequence skipped.
func (f *Feed) applyMessage(payload []byte) (bool, bool) {
	var envelope struct {
		Stream string          `json:"stream"`
		Data   json.RawMessage `json:"data"`
		ID     *int64          `json:"id"`
		Result json.RawMessage `json:"result"`
	}

	if err := json.Unmarshal(payload, &envelope); err != nil {
		f.warnDecodeFailure(err)
		return false, false
	}

	if len(envelope.Stream) > 0 {
		if len(envelope.Data) == 0 {
			return false, false
		}

		payload = envelope.Data
	}

	// A struct bind is unsafe here: encoding/json matches keys case-insensitively,
	// so trade frames' E/T collide with e/t and bookTicker's B/A collide with b/a.
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(payload, &fields); err != nil {
		f.warnDecodeFailure(err)
		return false, false
	}

	event, _ := jsonString(fields["e"])
	symbolText, _ := jsonString(fields["s"])

	// Subscription acknowledgements carry only an id and a null result.
	if envelope.ID != nil && symbolText == "" {
		return false, false
	}

	symbol := normalizeSymbol(symbolText)
	if symbol == "" || !f.isActive(symbol) {
		return false, false
	}

	tradeID, hasTradeID := jsonInt64(fields["t"])
	tradePrice, hasTradePrice := jsonString(fields["p"])
	bidRaw := fields["b"]
	askRaw := fields["a"]

	if event == "trade" || (event == "" && hasTradeID && hasTradePrice) {
		return f.applyTrade(symbol, tradeID, hasTradeID, tradePrice)
	}

	if event == "bookTicker" || (event == "" && (len(bidRaw) > 0 || len(askRaw) > 0)) {
		bid, _ := jsonString(bidRaw)
		ask, _ := jsonString(askRaw)

		return f.applyBookTicker(symbol, bid, ask), false
	}

	return false, false
}

func jsonString(raw json.RawMessage) (string, bool) {
	if len(raw) == 0 || string(raw) == "null" {
		return "", false
	}

	var value string
	if err := json.Unmarshal(raw, &value); err != nil {
		return "", false
	}

	return value, true
}

func jsonInt64(raw json.RawMessage) (int64, bool) {
	if len(raw) == 0 || string(raw) == "null" {
		return 0, false
	}

	var value int64
	if err := json.Unmarshal(raw, &value); err != nil {
		return 0, false
	}

	return value, true
}

func (f *Feed) applyTrade(symbol string, tradeID int64, hasTradeID bool, price string) (bool, bool) {
	if !hasTradeID || tradeID <= 0 || price == "" {
		return false, false
	}

	if !validDecimal(price) {
		f.logger.Warn("market data trade has an invalid price", "symbol", symbol)
		return false, false
	}

	now := f.now()

	f.mu.Lock()
	defer f.mu.Unlock()

	previous := f.lastTradeID[symbol]
	gap := previous > 0 && tradeID > previous+1
	// Ignore stale or duplicate updates; only advance the sequence.
	if tradeID <= previous {
		return false, false
	}

	f.lastTradeID[symbol] = tradeID
	ticker := f.tickers[symbol]
	ticker.Symbol = symbol
	ticker.Last = price
	ticker.UpdatedAt = now
	ticker.TradeAt = now
	f.tickers[symbol] = ticker
	f.status.LastMessageAt = now

	return true, gap
}

func (f *Feed) applyBookTicker(symbol, bid, ask string) bool {
	if bid == "" && ask == "" {
		return false
	}

	if bid != "" && !validDecimal(bid) {
		f.logger.Warn("market data book has an invalid bid", "symbol", symbol)
		return false
	}

	if ask != "" && !validDecimal(ask) {
		f.logger.Warn("market data book has an invalid ask", "symbol", symbol)
		return false
	}

	now := f.now()

	f.mu.Lock()
	defer f.mu.Unlock()

	ticker := f.tickers[symbol]
	ticker.Symbol = symbol
	if bid != "" {
		ticker.Bid = bid
	}
	if ask != "" {
		ticker.Ask = ask
	}
	ticker.UpdatedAt = now
	f.tickers[symbol] = ticker
	f.status.LastMessageAt = now

	return true
}

// warnDecodeFailure records malformed frames without flooding the log. The
// first failure is logged, later failures within a minute are counted and
// reported with the next visible warning.
func (f *Feed) warnDecodeFailure(err error) {
	f.mu.Lock()
	now := f.now()
	if !f.lastDecodeWarning.IsZero() && now.Sub(f.lastDecodeWarning) < time.Minute {
		f.decodeWarnings++
		f.mu.Unlock()
		return
	}

	suppressed := f.decodeWarnings
	f.decodeWarnings = 0
	f.lastDecodeWarning = now
	f.mu.Unlock()

	attributes := []any{"error_type", fmt.Sprintf("%T", err)}
	if suppressed > 0 {
		attributes = append(attributes, "suppressed", suppressed)
	}

	f.logger.Warn("market data message could not be decoded", attributes...)
}

func (f *Feed) fetchREST(ctx context.Context) {
	symbols := f.Symbols()
	if len(symbols) == 0 {
		return
	}

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
		// Only cache quotes an active (or newly desired) bot needs.
		if !f.wantedLocked(symbol) {
			continue
		}
		ticker.UpdatedAt = now
		ticker.TradeAt = now
		f.tickers[symbol] = ticker
	}
	f.mu.Unlock()
}

// wait sleeps for duration while polling the REST ticker at RESTPollInterval.
// It returns true when the deadline expires or the desired symbol set changes,
// and false only when ctx is cancelled.
func (f *Feed) wait(ctx context.Context, duration time.Duration) bool {
	if duration <= 0 {
		duration = time.Millisecond
	}

	deadline := time.NewTimer(duration)
	defer deadline.Stop()

	var poll <-chan time.Time
	if f.cfg.RESTPollInterval > 0 {
		ticker := time.NewTicker(f.cfg.RESTPollInterval)
		defer ticker.Stop()
		poll = ticker.C
	}

	for {
		select {
		case <-ctx.Done():
			return false
		case <-f.changed:
			return true
		case <-deadline.C:
			return true
		case <-poll:
			f.fetchREST(ctx)
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
	f.active = append([]string(nil), symbols...)
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

func (f *Feed) markDegraded(reason string) {
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
		Symbols:       append([]string(nil), f.desired...),
		Since:         since,
		LastMessageAt: f.status.LastMessageAt,
	}
	symbols := len(f.desired)
	f.mu.Unlock()

	if changed {
		f.emit(Event{Type: EventDegraded, Mode: ModeREST, Reason: reason, Symbols: symbols, At: f.now()})
	}
}

func (f *Feed) setIdle() {
	f.mu.Lock()
	f.active = nil
	f.status = Status{
		Mode:          ModeIdle,
		Symbols:       append([]string(nil), f.desired...),
		Since:         f.now(),
		LastMessageAt: f.status.LastMessageAt,
	}
	f.mu.Unlock()
}

// isActive reports whether symbol is part of the current WebSocket
// subscription. Messages for removed symbols are ignored.
func (f *Feed) isActive(symbol string) bool {
	f.mu.RLock()
	defer f.mu.RUnlock()

	return containsSymbol(f.active, symbol)
}

func (f *Feed) wantedLocked(symbol string) bool {
	return containsSymbol(f.desired, symbol) || containsSymbol(f.active, symbol)
}

// pruneTickersLocked drops cached quotes for symbols the desired set no longer
// contains, so a stopped bot cannot keep a stale price alive.
func (f *Feed) pruneTickersLocked(symbols []string) {
	for symbol := range f.tickers {
		if !containsSymbol(symbols, symbol) {
			delete(f.tickers, symbol)
			delete(f.lastTradeID, symbol)
		}
	}
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

// sameSymbolSet compares two normalized sets without caring about order.
func sameSymbolSet(left, right []string) bool {
	if len(left) != len(right) {
		return false
	}

	for _, symbol := range left {
		if !containsSymbol(right, symbol) {
			return false
		}
	}

	return true
}

// symbolDiff returns symbols to subscribe and unsubscribe when moving from
// active to desired.
func symbolDiff(active, desired []string) (added, removed []string) {
	for _, symbol := range desired {
		if !containsSymbol(active, symbol) {
			added = append(added, symbol)
		}
	}

	for _, symbol := range active {
		if !containsSymbol(desired, symbol) {
			removed = append(removed, symbol)
		}
	}

	return added, removed
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
