package marketdata_test

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/marketdata"
)

type staticSnapshots map[string]marketdata.Ticker

func (s staticSnapshots) Snapshot(symbol string) (marketdata.Ticker, bool) {
	ticker, ok := s[symbol]

	return ticker, ok
}

func TestPriceSourceUsesFreshCacheWithoutREST(t *testing.T) {
	var requests atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		http.Error(w, "unexpected REST call", http.StatusInternalServerError)
	}))
	t.Cleanup(server.Close)

	cache := staticSnapshots{
		"BTCUSDT": {Symbol: "BTCUSDT", Last: "100.50", UpdatedAt: time.Now().Add(-time.Second)},
	}

	prices := marketdata.NewPriceSource(cache, &marketdata.RESTClient{BaseURL: server.URL, HTTPClient: server.Client()}, "binance", 15*time.Second, nil)

	price, err := prices.Last(context.Background(), "binance", "BTC/USDT")
	if err != nil {
		t.Fatalf("Last() error = %v", err)
	}
	if price.String() != "100.5" {
		t.Errorf("price = %s, want 100.5", price.String())
	}
	if requests.Load() != 0 {
		t.Errorf("REST requests = %d, want 0", requests.Load())
	}
}

func TestPriceSourceUsesRESTSnapshotForMissingOrStaleCache(t *testing.T) {
	var requests atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		writeRESTTicker(w, "BTCUSDT", "101.25", "101.20", "101.30")
	}))
	t.Cleanup(server.Close)

	rest := &marketdata.RESTClient{BaseURL: server.URL, HTTPClient: server.Client()}

	cases := []struct {
		name  string
		cache staticSnapshots
	}{
		{name: "missing", cache: staticSnapshots{}},
		{name: "stale", cache: staticSnapshots{
			"BTCUSDT": {Symbol: "BTCUSDT", Last: "90.00", UpdatedAt: time.Now().Add(-time.Hour)},
		}},
		{name: "stale trade hidden by a fresh book update", cache: staticSnapshots{
			"BTCUSDT": {
				Symbol:    "BTCUSDT",
				Last:      "90.00",
				UpdatedAt: time.Now(),
				TradeAt:   time.Now().Add(-time.Hour),
			},
		}},
		{name: "invalid price", cache: staticSnapshots{
			"BTCUSDT": {Symbol: "BTCUSDT", Last: "not-a-number", UpdatedAt: time.Now()},
		}},
	}

	for _, test := range cases {
		t.Run(test.name, func(t *testing.T) {
			before := requests.Load()
			prices := marketdata.NewPriceSource(test.cache, rest, "binance", 15*time.Second, nil)

			price, err := prices.Last(context.Background(), "binance", "BTC/USDT")
			if err != nil {
				t.Fatalf("Last() error = %v", err)
			}
			if price.String() != "101.25" {
				t.Errorf("price = %s, want 101.25", price.String())
			}
			if requests.Load() != before+1 {
				t.Errorf("REST requests = %d, want %d", requests.Load(), before+1)
			}
		})
	}
}

func TestPriceSourceFailsInsteadOfUsingStalePrice(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "unavailable", http.StatusServiceUnavailable)
	}))
	t.Cleanup(server.Close)

	cache := staticSnapshots{
		"BTCUSDT": {Symbol: "BTCUSDT", Last: "90.00", UpdatedAt: time.Now().Add(-time.Hour)},
	}

	prices := marketdata.NewPriceSource(cache, &marketdata.RESTClient{BaseURL: server.URL, HTTPClient: server.Client()}, "binance", 15*time.Second, nil)

	if _, err := prices.Last(context.Background(), "binance", "BTC/USDT"); err == nil {
		t.Fatal("Last() error = nil, want failure instead of a stale price")
	}
}

func TestPriceSourceRejectsOtherExchanges(t *testing.T) {
	var requests atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		writeRESTTicker(w, "BTCUSDT", "101.25", "101.20", "101.30")
	}))
	t.Cleanup(server.Close)

	prices := marketdata.NewPriceSource(staticSnapshots{}, &marketdata.RESTClient{BaseURL: server.URL, HTTPClient: server.Client()}, "binance", 15*time.Second, nil)

	if _, err := prices.Last(context.Background(), "max", "BTC/USDT"); err == nil {
		t.Fatal("Last() error = nil, want unsupported exchange error")
	}
	if requests.Load() != 0 {
		t.Errorf("REST requests = %d, want 0", requests.Load())
	}
}

func TestPriceSourceWithoutRESTSourceFails(t *testing.T) {
	prices := marketdata.NewPriceSource(staticSnapshots{}, nil, "binance", 15*time.Second, nil)

	_, err := prices.Last(context.Background(), "binance", "BTC/USDT")
	if err == nil || !strings.Contains(err.Error(), "REST") {
		t.Fatalf("Last() error = %v, want a missing-REST error", err)
	}
}
