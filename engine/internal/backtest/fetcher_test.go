package backtest_test

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/backtest"
)

func TestBinanceCandleFetcherPagesKlines(t *testing.T) {
	first := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v3/klines" {
			http.NotFound(w, r)
			return
		}
		if got := r.URL.Query().Get("symbol"); got != "BTCUSDT" {
			t.Errorf("symbol = %q, want BTCUSDT", got)
		}
		if got := r.URL.Query().Get("interval"); got != "1m" {
			t.Errorf("interval = %q, want 1m", got)
		}
		rows := [][]any{
			{first.UnixMilli(), "100", "101", "99", "100.5", "1"},
			{first.Add(time.Minute).UnixMilli(), "100.5", "102", "100", "101", "2"},
		}
		_ = json.NewEncoder(w).Encode(rows)
	}))
	defer server.Close()

	fetcher := backtest.NewBinanceCandleFetcher(server.URL, time.Second)
	candles, err := fetcher.Fetch(context.Background(), backtest.Configuration{
		Exchange:  "binance",
		Symbol:    "BTC/USDT",
		Timeframe: backtest.Timeframe1m,
		StartAt:   first,
		EndAt:     first.Add(2 * time.Minute),
	})
	if err != nil {
		t.Fatalf("fetch: %v", err)
	}
	if len(candles) != 2 {
		t.Fatalf("candles = %d, want 2", len(candles))
	}
	if candles[0].Close.String() != "100.5" || candles[1].Open.String() != "100.5" {
		t.Fatalf("unexpected candles: %+v", candles)
	}
}
