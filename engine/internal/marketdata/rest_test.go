package marketdata_test

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/marketdata"
)

func TestRESTClientFetchesPublicTickerArray(t *testing.T) {
	var path string
	var symbols string
	var authorization string
	var apiKey string

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		path = r.URL.Path
		symbols = r.URL.Query().Get("symbols")
		authorization = r.Header.Get("Authorization")
		apiKey = r.Header.Get("X-MBX-APIKEY")

		w.Header().Set("Content-Type", "application/json")
		_, _ = fmt.Fprint(w, `[
			{"symbol":"BTCUSDT","lastPrice":"100.50","bidPrice":"100.40","askPrice":"100.60"},
			{"symbol":"ETHUSDT","lastPrice":"2000.10","bidPrice":"2000.00","askPrice":"2000.20"}
		]`)
	}))
	t.Cleanup(server.Close)

	client := &marketdata.RESTClient{BaseURL: server.URL + "/", HTTPClient: server.Client()}

	tickers, err := client.Fetch(context.Background(), []string{"btcusdt", "ETHUSDT", "btcusdt", "BTC/USDT"})
	if err != nil {
		t.Fatalf("Fetch() error = %v", err)
	}

	if path != "/api/v3/ticker/24hr" {
		t.Errorf("path = %q, want /api/v3/ticker/24hr", path)
	}

	if symbols != `["BTCUSDT","ETHUSDT"]` {
		t.Errorf("symbols = %q, want normalized, de-duplicated JSON array", symbols)
	}

	if authorization != "" || apiKey != "" {
		t.Errorf("request sent credentials: Authorization=%q X-MBX-APIKEY=%q", authorization, apiKey)
	}

	if len(tickers) != 2 {
		t.Fatalf("tickers = %+v, want two entries", tickers)
	}

	bitcoin := tickers["BTCUSDT"]
	if bitcoin.Last != "100.50" || bitcoin.Bid != "100.40" || bitcoin.Ask != "100.60" {
		t.Errorf("BTCUSDT ticker = %+v, want 100.50/100.40/100.60", bitcoin)
	}

	ethereum := tickers["ETHUSDT"]
	if ethereum.Last != "2000.10" || ethereum.Bid != "2000.00" || ethereum.Ask != "2000.20" {
		t.Errorf("ETHUSDT ticker = %+v, want 2000.10/2000.00/2000.20", ethereum)
	}
}

func TestRESTClientAcceptsSingleSymbolObject(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = fmt.Fprint(w, `{"symbol":"BTCUSDT","lastPrice":"100.50","bidPrice":"100.40","askPrice":"100.60"}`)
	}))
	t.Cleanup(server.Close)

	client := &marketdata.RESTClient{BaseURL: server.URL, HTTPClient: server.Client()}

	tickers, err := client.Fetch(context.Background(), []string{"BTCUSDT"})
	if err != nil {
		t.Fatalf("Fetch() error = %v", err)
	}

	if ticker := tickers["BTCUSDT"]; ticker.Last != "100.50" {
		t.Errorf("BTCUSDT ticker = %+v, want last 100.50", ticker)
	}
}

func TestRESTClientRejectsInvalidPrices(t *testing.T) {
	cases := map[string]string{
		"last": `{"symbol":"BTCUSDT","lastPrice":"not-a-number","bidPrice":"1.0","askPrice":"1.1"}`,
		"bid":  `{"symbol":"BTCUSDT","lastPrice":"1.0","bidPrice":"NaN","askPrice":"1.1"}`,
		"ask":  `{"symbol":"BTCUSDT","lastPrice":"1.0","bidPrice":"1.0","askPrice":"1e3"}`,
	}

	for name, body := range cases {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			_, _ = fmt.Fprint(w, body)
		}))

		client := &marketdata.RESTClient{BaseURL: server.URL, HTTPClient: server.Client()}

		if _, err := client.Fetch(context.Background(), []string{"BTCUSDT"}); err == nil {
			t.Errorf("%s: Fetch() error = nil, want an invalid-price error", name)
		}

		server.Close()
	}
}

func TestRESTClientRejectsHTTPError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		http.Error(w, "unavailable", http.StatusServiceUnavailable)
	}))
	t.Cleanup(server.Close)

	client := &marketdata.RESTClient{BaseURL: server.URL, HTTPClient: server.Client()}

	if _, err := client.Fetch(context.Background(), []string{"BTCUSDT"}); err == nil {
		t.Fatal("Fetch() error = nil, want an HTTP status error")
	}
}

func TestRESTClientRequiresBaseURL(t *testing.T) {
	client := &marketdata.RESTClient{}

	if _, err := client.Fetch(context.Background(), []string{"BTCUSDT"}); err == nil {
		t.Fatal("Fetch() error = nil, want a not-configured error")
	}
}
