package backtest_test

import (
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/backtest"
	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/strategy"
)

func TestSequentialBuyCandlesPlaceSixOppositeSells(t *testing.T) {
	snapshot := []byte(`{
		"bot": {
			"id": 1,
			"name": "Sequential ladder",
			"exchange": "binance",
			"symbol": "BTC/USDT",
			"strategy_key": "simple-grid",
			"capital_allocation": "10000",
			"fee_rate": "0.001"
		},
		"strategy": {
			"key": "simple-grid",
			"configuration": {
				"center_price": "100",
				"spacing_type": "arithmetic",
				"spacing_value": "10",
				"order_quantity": "1",
				"levels_per_side": 6
			}
		},
		"risk": {
			"max_position_notional": "100000",
			"max_open_orders": 20,
			"daily_loss_limit": "100000"
		},
		"exit": {
			"stop_loss_percent": null,
			"take_profit_percent": null,
			"range_low": null,
			"range_high": null
		},
		"period": {
			"timeframe": "15m",
			"start_at": "2026-11-23T00:00:00+00:00",
			"end_at": "2026-11-23T02:00:00+00:00"
		}
	}`)

	configuration, _, err := backtest.DecodeSnapshot(snapshot)
	if err != nil {
		t.Fatalf("DecodeSnapshot returned error: %v", err)
	}

	prices := []string{"100", "90", "80", "70", "60", "50", "40", "110"}
	candles := make([]backtest.Candle, 0, len(prices))
	for index, price := range prices {
		candles = append(candles, flatCandle(index, price))
	}

	result, err := backtest.NewEngine().Run(configuration, candles, nil)
	if err != nil {
		t.Fatalf("Run returned error: %v", err)
	}

	sells := map[string]bool{}
	for _, fill := range result.Fills {
		if fill.Side == strategy.SideSell {
			sells[fill.Price.String()] = true
		}
	}

	expected := []string{"50", "60", "70", "80", "90", "110"}
	if len(sells) != len(expected) {
		t.Fatalf("expected six sell fills, got %v", sells)
	}
	for _, price := range expected {
		if !sells[price] {
			t.Fatalf("expected a sell fill at %s, got %v", price, sells)
		}
	}
	if result.Metrics.BuyCount != 6 || result.Metrics.SellCount != 6 {
		t.Fatalf("expected 6 buys and 6 sells, got %d buys and %d sells", result.Metrics.BuyCount, result.Metrics.SellCount)
	}
}

func flatCandle(index int, price string) backtest.Candle {
	value := decimal.MustParse(price)

	return backtest.Candle{
		OpenTime: time.Date(2026, 11, 23, 0, index*15, 0, 0, time.UTC),
		Open:     value,
		High:     value,
		Low:      value,
		Close:    value,
		Volume:   decimal.MustParse("1"),
	}
}
