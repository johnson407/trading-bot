package marketdata_test

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/gorilla/websocket"

	"github.com/johnson407/trading-bot/engine/internal/marketdata"
)

var testUpgrader = websocket.Upgrader{
	CheckOrigin: func(*http.Request) bool { return true },
}

func websocketURL(serverURL string) string {
	return "ws" + strings.TrimPrefix(serverURL, "http")
}

func testConfig(serverURL string) marketdata.Config {
	return marketdata.Config{
		WSURL:            websocketURL(serverURL),
		RESTURL:          serverURL,
		QuietAfter:       2 * time.Second,
		RESTPollInterval: 20 * time.Millisecond,
		ReconnectMin:     10 * time.Millisecond,
		ReconnectMax:     40 * time.Millisecond,
		RequestTimeout:   time.Second,
	}
}

func waitFor(t *testing.T, description string, condition func() bool) {
	t.Helper()

	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if condition() {
			return
		}

		time.Sleep(5 * time.Millisecond)
	}

	t.Fatalf("timed out waiting for %s", description)
}

func writeRESTTicker(w http.ResponseWriter, symbol, last, bid, ask string) {
	w.Header().Set("Content-Type", "application/json")
	_, _ = fmt.Fprintf(w, `[{"symbol":%q,"lastPrice":%q,"bidPrice":%q,"askPrice":%q}]`, symbol, last, bid, ask)
}

type eventRecorder struct {
	mu     sync.Mutex
	events []marketdata.Event
}

func (r *eventRecorder) record(event marketdata.Event) {
	r.mu.Lock()
	defer r.mu.Unlock()

	r.events = append(r.events, event)
}

func (r *eventRecorder) all() []marketdata.Event {
	r.mu.Lock()
	defer r.mu.Unlock()

	return append([]marketdata.Event(nil), r.events...)
}

func (r *eventRecorder) has(eventType, reason string) bool {
	for _, event := range r.all() {
		if event.Type == eventType && (reason == "" || event.Reason == reason) {
			return true
		}
	}

	return false
}

func TestFeedSubscribesAndAppliesPublicTickerMessages(t *testing.T) {
	subscriptions := make(chan []string, 1)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		connection, err := testUpgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer func() { _ = connection.Close() }()

		if _, payload, err := connection.ReadMessage(); err == nil {
			var request struct {
				Params []string `json:"params"`
			}

			if json.Unmarshal(payload, &request) == nil {
				select {
				case subscriptions <- request.Params:
				default:
				}
			}
		}

		_ = connection.WriteMessage(websocket.TextMessage, []byte(
			`{"stream":"btcusdt@trade","data":{"e":"trade","s":"BTCUSDT","t":7,"p":"100.50"}}`,
		))
		_ = connection.WriteMessage(websocket.TextMessage, []byte(
			`{"stream":"btcusdt@bookTicker","data":{"s":"BTCUSDT","b":"100.40","a":"100.60"}}`,
		))

		for {
			if _, _, err := connection.ReadMessage(); err != nil {
				return
			}
		}
	}))
	t.Cleanup(server.Close)

	cfg := testConfig(server.URL)
	cfg.QuietAfter = 5 * time.Second

	recorder := &eventRecorder{}
	feed := marketdata.New(cfg, slog.New(slog.NewTextHandler(&bytes.Buffer{}, nil)), recorder.record)

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	go func() { _ = feed.Run(ctx, []string{"btcusdt"}) }()

	waitFor(t, "trade and book ticker snapshot", func() bool {
		ticker, ok := feed.Snapshot("BTCUSDT")
		return ok && ticker.Last == "100.50" && ticker.Bid == "100.40" && ticker.Ask == "100.60"
	})

	if status := feed.Status(); status.Mode != marketdata.ModeWebSocket || status.Degraded {
		t.Errorf("status = %+v, want connected websocket", status)
	}

	select {
	case params := <-subscriptions:
		joined := strings.Join(params, ",")
		for _, expected := range []string{"btcusdt@trade", "btcusdt@bookTicker"} {
			if !strings.Contains(joined, expected) {
				t.Errorf("subscription params = %v, want %s", params, expected)
			}
		}
	case <-time.After(time.Second):
		t.Fatal("no subscription request received")
	}

	if recorder.has(marketdata.EventDegraded, "") {
		t.Errorf("unexpected degraded event: %+v", recorder.all())
	}
}

func TestFeedReconnectsAfterDisconnect(t *testing.T) {
	var connections atomic.Int32
	var restCalls atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v3/ticker/24hr" {
			restCalls.Add(1)
			writeRESTTicker(w, "BTCUSDT", "99.00", "98.90", "99.10")
			return
		}

		connection, err := testUpgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer func() { _ = connection.Close() }()

		number := connections.Add(1)

		_, _, _ = connection.ReadMessage()

		if number == 1 {
			_ = connection.WriteMessage(websocket.TextMessage, []byte(
				`{"e":"trade","s":"BTCUSDT","t":1,"p":"100.00"}`,
			))
			time.Sleep(20 * time.Millisecond)
			return
		}

		_ = connection.WriteMessage(websocket.TextMessage, []byte(
			`{"e":"trade","s":"BTCUSDT","t":1,"p":"101.00"}`,
		))

		for {
			if _, _, err := connection.ReadMessage(); err != nil {
				return
			}
		}
	}))
	t.Cleanup(server.Close)

	recorder := &eventRecorder{}
	feed := marketdata.New(testConfig(server.URL), slog.New(slog.NewTextHandler(&bytes.Buffer{}, nil)), recorder.record)

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	go func() { _ = feed.Run(ctx, []string{"BTCUSDT"}) }()

	waitFor(t, "reconnected stream price", func() bool {
		ticker, ok := feed.Snapshot("BTCUSDT")
		return ok && ticker.Last == "101.00" && feed.Status().Mode == marketdata.ModeWebSocket
	})

	if connections.Load() < 2 {
		t.Errorf("connections = %d, want at least 2", connections.Load())
	}

	if restCalls.Load() == 0 {
		t.Error("REST fallback was not used during the disconnect")
	}
}

func TestFeedDetectsTradeGapAndRecovers(t *testing.T) {
	var connections atomic.Int32
	var restCalls atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v3/ticker/24hr" {
			restCalls.Add(1)
			writeRESTTicker(w, "BTCUSDT", "102.50", "102.40", "102.60")
			return
		}

		connection, err := testUpgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer func() { _ = connection.Close() }()

		number := connections.Add(1)

		_, _, _ = connection.ReadMessage()

		if number == 1 {
			for _, message := range []string{
				`{"e":"trade","s":"BTCUSDT","t":1,"p":"100.00"}`,
				`{"e":"trade","s":"BTCUSDT","t":2,"p":"101.00"}`,
				`{"e":"trade","s":"BTCUSDT","t":10,"p":"102.00"}`,
			} {
				_ = connection.WriteMessage(websocket.TextMessage, []byte(message))
			}
		} else {
			_ = connection.WriteMessage(websocket.TextMessage, []byte(
				`{"e":"trade","s":"BTCUSDT","t":11,"p":"103.00"}`,
			))
		}

		for {
			if _, _, err := connection.ReadMessage(); err != nil {
				return
			}
		}
	}))
	t.Cleanup(server.Close)

	recorder := &eventRecorder{}
	feed := marketdata.New(testConfig(server.URL), slog.New(slog.NewTextHandler(&bytes.Buffer{}, nil)), recorder.record)

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	go func() { _ = feed.Run(ctx, []string{"BTCUSDT"}) }()

	waitFor(t, "gap recovery and resumed stream", func() bool {
		ticker, ok := feed.Snapshot("BTCUSDT")
		return ok && ticker.Last == "103.00" && feed.Status().Mode == marketdata.ModeWebSocket
	})

	if !recorder.has(marketdata.EventDegraded, marketdata.ReasonTradeGap) {
		t.Errorf("events = %+v, want %s/%s", recorder.all(), marketdata.EventDegraded, marketdata.ReasonTradeGap)
	}

	if restCalls.Load() == 0 {
		t.Error("REST snapshot was not requested after the trade-id gap")
	}
}

func TestFeedRecoversFromQuietPeriod(t *testing.T) {
	var connections atomic.Int32
	var restCalls atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v3/ticker/24hr" {
			restCalls.Add(1)
			writeRESTTicker(w, "BTCUSDT", "101.00", "100.90", "101.10")
			return
		}

		connection, err := testUpgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer func() { _ = connection.Close() }()

		number := connections.Add(1)

		_, _, _ = connection.ReadMessage()

		if number == 1 {
			_ = connection.WriteMessage(websocket.TextMessage, []byte(
				`{"e":"trade","s":"BTCUSDT","t":1,"p":"100.00"}`,
			))
			time.Sleep(250 * time.Millisecond)
			return
		}

		for {
			if _, _, err := connection.ReadMessage(); err != nil {
				return
			}
		}
	}))
	t.Cleanup(server.Close)

	cfg := testConfig(server.URL)
	cfg.QuietAfter = 60 * time.Millisecond

	recorder := &eventRecorder{}
	feed := marketdata.New(cfg, slog.New(slog.NewTextHandler(&bytes.Buffer{}, nil)), recorder.record)

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	go func() { _ = feed.Run(ctx, []string{"BTCUSDT"}) }()

	waitFor(t, "quiet-period REST recovery", func() bool {
		return recorder.has(marketdata.EventDegraded, marketdata.ReasonQuietPeriod) && restCalls.Load() > 0
	})
}

func TestFeedFallsBackToPeriodicRESTTickers(t *testing.T) {
	const token = "should-not-appear"

	var restCalls atomic.Int32
	var logs bytes.Buffer

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v3/ticker/24hr" {
			restCalls.Add(1)
			writeRESTTicker(w, "BTCUSDT", "100.00", "99.90", "100.10")
			return
		}

		http.Error(w, "no websocket", http.StatusServiceUnavailable)
	}))
	t.Cleanup(server.Close)

	cfg := testConfig(server.URL)
	cfg.WSURL += "?token=" + token
	cfg.RESTPollInterval = 10 * time.Millisecond
	cfg.ReconnectMin = 20 * time.Millisecond
	cfg.ReconnectMax = 40 * time.Millisecond

	recorder := &eventRecorder{}
	feed := marketdata.New(cfg, slog.New(slog.NewTextHandler(&logs, nil)), recorder.record)

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	go func() { _ = feed.Run(ctx, []string{"BTCUSDT"}) }()

	waitFor(t, "periodic REST fallback", func() bool {
		ticker, ok := feed.Snapshot("BTCUSDT")
		status := feed.Status()

		return ok && ticker.Last == "100.00" &&
			status.Mode == marketdata.ModeREST && status.Degraded &&
			status.Reason == marketdata.ReasonWebSocketUnavailable &&
			restCalls.Load() >= 2
	})

	if !recorder.has(marketdata.EventDegraded, marketdata.ReasonWebSocketUnavailable) {
		t.Errorf("events = %+v, want degraded/%s", recorder.all(), marketdata.ReasonWebSocketUnavailable)
	}

	if strings.Contains(logs.String(), token) {
		t.Errorf("logs contain the websocket query token: %s", logs.String())
	}

	for _, event := range recorder.all() {
		if strings.Contains(fmt.Sprintf("%+v", event), token) {
			t.Errorf("event contains the websocket query token: %+v", event)
		}
	}
}

// TestFeedResumesAfterDisconnectAndRESTSnapshot is the phase-3 acceptance test:
// a disconnect is bridged by a public REST snapshot, and the feed then resumes
// on the re-established socket.
func TestFeedResumesAfterDisconnectAndRESTSnapshot(t *testing.T) {
	var connections atomic.Int32
	var restCalls atomic.Int32

	allowSecondConnection := make(chan struct{})

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v3/ticker/24hr" {
			restCalls.Add(1)
			writeRESTTicker(w, "BTCUSDT", "101.00", "100.90", "101.10")
			return
		}

		connection, err := testUpgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer func() { _ = connection.Close() }()

		number := connections.Add(1)

		_, _, _ = connection.ReadMessage()

		if number == 1 {
			_ = connection.WriteMessage(websocket.TextMessage, []byte(
				`{"e":"trade","s":"BTCUSDT","t":1,"p":"100.00"}`,
			))
			// Give the client a moment to apply the quote, then drop the socket.
			time.Sleep(20 * time.Millisecond)
			return
		}

		// Hold the replacement socket until the test has observed the REST
		// snapshot, so the recovery path is deterministic.
		<-allowSecondConnection

		_ = connection.WriteMessage(websocket.TextMessage, []byte(
			`{"e":"trade","s":"BTCUSDT","t":1,"p":"102.00"}`,
		))

		for {
			if _, _, err := connection.ReadMessage(); err != nil {
				return
			}
		}
	}))
	t.Cleanup(server.Close)

	recorder := &eventRecorder{}
	feed := marketdata.New(testConfig(server.URL), slog.New(slog.NewTextHandler(&bytes.Buffer{}, nil)), recorder.record)

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	go func() { _ = feed.Run(ctx, []string{"BTCUSDT"}) }()

	waitFor(t, "REST snapshot while disconnected", func() bool {
		ticker, ok := feed.Snapshot("BTCUSDT")
		status := feed.Status()

		return restCalls.Load() > 0 && ok && ticker.Last == "101.00" &&
			status.Mode == marketdata.ModeREST && status.Degraded
	})

	if !recorder.has(marketdata.EventDegraded, marketdata.ReasonDisconnected) {
		t.Errorf("events = %+v, want degraded/%s", recorder.all(), marketdata.ReasonDisconnected)
	}

	close(allowSecondConnection)

	waitFor(t, "feed resumed on the websocket", func() bool {
		ticker, ok := feed.Snapshot("BTCUSDT")
		return ok && ticker.Last == "102.00" && feed.Status().Mode == marketdata.ModeWebSocket
	})

	if !recorder.has(marketdata.EventRecovered, "") {
		t.Errorf("events = %+v, want %s", recorder.all(), marketdata.EventRecovered)
	}
}

func TestFeedStaysIdleWithoutConfiguration(t *testing.T) {
	feed := marketdata.New(
		marketdata.Config{},
		slog.New(slog.NewTextHandler(&bytes.Buffer{}, nil)),
		nil,
	)

	done := make(chan error, 1)
	go func() { done <- feed.Run(context.Background(), []string{"BTCUSDT"}) }()

	select {
	case err := <-done:
		if err != nil {
			t.Fatalf("Run() error = %v, want nil idle return", err)
		}
	case <-time.After(time.Second):
		t.Fatal("Run() did not stay idle with an empty configuration")
	}

	if status := feed.Status(); status.Mode != marketdata.ModeIdle {
		t.Errorf("status = %+v, want idle", status)
	}
}

func TestFeedStaysIdleWithoutSymbols(t *testing.T) {
	feed := marketdata.New(
		marketdata.Config{WSURL: "ws://127.0.0.1:1", RESTURL: "http://127.0.0.1:1"},
		slog.New(slog.NewTextHandler(&bytes.Buffer{}, nil)),
		nil,
	)

	ctx, cancel := context.WithCancel(context.Background())

	done := make(chan error, 1)
	go func() { done <- feed.Run(ctx) }()

	waitFor(t, "idle feed", func() bool {
		return feed.Status().Mode == marketdata.ModeIdle
	})

	if symbols := feed.Status().Symbols; len(symbols) != 0 {
		t.Errorf("symbols = %v, want none", symbols)
	}

	cancel()

	select {
	case err := <-done:
		if err != nil {
			t.Fatalf("Run() error = %v, want nil idle return", err)
		}
	case <-time.After(time.Second):
		t.Fatal("Run() did not stop after cancellation")
	}
}

func TestFeedAddsAndRemovesRunningBotSymbols(t *testing.T) {
	type request struct {
		Method string   `json:"method"`
		Params []string `json:"params"`
	}

	requests := make(chan request, 16)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		connection, err := testUpgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer func() { _ = connection.Close() }()

		for {
			_, payload, err := connection.ReadMessage()
			if err != nil {
				return
			}

			var subscription request
			if json.Unmarshal(payload, &subscription) != nil {
				continue
			}

			select {
			case requests <- subscription:
			default:
			}

			for _, param := range subscription.Params {
				symbol := strings.TrimSuffix(strings.TrimSuffix(param, "@trade"), "@bookTicker")
				switch {
				case strings.HasSuffix(param, "@trade"):
					_ = connection.WriteMessage(websocket.TextMessage, []byte(fmt.Sprintf(
						`{"stream":%q,"data":{"e":"trade","s":%q,"t":1,"p":"100.00"}}`,
						param, strings.ToUpper(symbol),
					)))
				case strings.HasSuffix(param, "@bookTicker"):
					_ = connection.WriteMessage(websocket.TextMessage, []byte(fmt.Sprintf(
						`{"stream":%q,"data":{"s":%q,"b":"99.90","a":"100.10"}}`,
						param, strings.ToUpper(symbol),
					)))
				}
			}
		}
	}))
	t.Cleanup(server.Close)

	cfg := testConfig(server.URL)
	cfg.QuietAfter = 5 * time.Second

	feed := marketdata.New(cfg, slog.New(slog.NewTextHandler(&bytes.Buffer{}, nil)), nil)
	feed.SetSymbols([]string{"BTCUSDT"})

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	go func() { _ = feed.Run(ctx) }()

	waitFor(t, "initial BTC subscription", func() bool {
		return feed.Status().Mode == marketdata.ModeWebSocket
	})
	waitFor(t, "BTC ticker", func() bool {
		_, ok := feed.Snapshot("BTC/USDT")

		return ok
	})

	first := <-requests
	if first.Method != "SUBSCRIBE" {
		t.Fatalf("first request = %+v, want SUBSCRIBE", first)
	}

	feed.SetSymbols([]string{"BTCUSDT", "ETHUSDT"})

	waitFor(t, "ETH subscription request", func() bool {
		for {
			select {
			case request := <-requests:
				if request.Method != "SUBSCRIBE" {
					continue
				}

				for _, param := range request.Params {
					if strings.HasPrefix(param, "ethusdt@") {
						return true
					}
				}
			default:
				return false
			}
		}
	})

	waitFor(t, "ETH ticker", func() bool {
		_, ok := feed.Snapshot("ETH/USDT")

		return ok
	})

	feed.SetSymbols([]string{"ETHUSDT"})

	waitFor(t, "BTC unsubscribe request", func() bool {
		for {
			select {
			case request := <-requests:
				if request.Method != "UNSUBSCRIBE" {
					continue
				}

				for _, param := range request.Params {
					if strings.HasPrefix(param, "btcusdt@") {
						return true
					}
				}
			default:
				return false
			}
		}
	})

	waitFor(t, "BTC ticker removed", func() bool {
		_, ok := feed.Snapshot("BTC/USDT")

		return !ok
	})

	if symbols := feed.Status().Symbols; len(symbols) != 1 || symbols[0] != "ETHUSDT" {
		t.Errorf("status symbols = %v, want [ETHUSDT]", symbols)
	}

	// The server writes a BTC quote for the unsubscribe request too; the feed
	// must ignore it because the symbol is no longer active.
	time.Sleep(50 * time.Millisecond)
	if _, ok := feed.Snapshot("BTC/USDT"); ok {
		t.Error("removed symbol received a quote after unsubscribe")
	}
}
