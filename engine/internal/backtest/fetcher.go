package backtest

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// CandleFetcher supplies public historical candles for a configuration when
// the market_candles cache does not cover the requested range.
type CandleFetcher interface {
	Fetch(ctx context.Context, configuration Configuration) ([]Candle, error)
}

// BinanceCandleFetcher reads public Binance klines. It never uses credentials.
type BinanceCandleFetcher struct {
	BaseURL    string
	HTTPClient *http.Client
}

// NewBinanceCandleFetcher builds a public kline client.
func NewBinanceCandleFetcher(baseURL string, timeout time.Duration) *BinanceCandleFetcher {
	if baseURL == "" {
		baseURL = "https://api.binance.com"
	}
	if timeout <= 0 {
		timeout = 10 * time.Second
	}

	return &BinanceCandleFetcher{
		BaseURL:    strings.TrimRight(baseURL, "/"),
		HTTPClient: &http.Client{Timeout: timeout},
	}
}

// Fetch pages through klines until the requested end time is covered.
func (f *BinanceCandleFetcher) Fetch(ctx context.Context, configuration Configuration) ([]Candle, error) {
	if configuration.Exchange != "binance" {
		return nil, fmt.Errorf("candle backfill is not available for exchange %s", configuration.Exchange)
	}

	first, last, expected, err := candleWindow(configuration, time.Now().UTC())
	if err != nil {
		return nil, err
	}
	if expected == 0 {
		return nil, nil
	}

	secondsValue, err := configuration.Timeframe.Seconds()
	if err != nil {
		return nil, err
	}
	step := time.Duration(secondsValue) * time.Second

	symbol := strings.ToUpper(strings.ReplaceAll(configuration.Symbol, "/", ""))
	candles := make([]Candle, 0, expected)
	cursor := first.UnixMilli()
	end := last.UnixMilli()
	now := time.Now().UTC()

	for cursor <= end {
		query := url.Values{
			"symbol":    {symbol},
			"interval":  {string(configuration.Timeframe)},
			"startTime": {strconv.FormatInt(cursor, 10)},
			"endTime":   {strconv.FormatInt(end, 10)},
			"limit":     {"1000"},
		}
		request, err := http.NewRequestWithContext(ctx, http.MethodGet, f.BaseURL+"/api/v3/klines?"+query.Encode(), nil)
		if err != nil {
			return nil, err
		}

		response, err := f.HTTPClient.Do(request)
		if err != nil {
			return nil, fmt.Errorf("fetch candles: %w", err)
		}

		var rows [][]json.RawMessage
		decodeErr := json.NewDecoder(response.Body).Decode(&rows)
		_ = response.Body.Close()
		if response.StatusCode != http.StatusOK {
			return nil, fmt.Errorf("fetch candles: binance returned status %d", response.StatusCode)
		}
		if decodeErr != nil {
			return nil, fmt.Errorf("decode candles: %w", decodeErr)
		}
		if len(rows) == 0 {
			break
		}

		for _, row := range rows {
			if len(row) < 6 {
				return nil, fmt.Errorf("decode candles: short kline row")
			}
			candle, err := kline(row)
			if err != nil {
				return nil, err
			}
			next := candle.OpenTime.Add(step).UnixMilli()
			if next > cursor {
				cursor = next
			}
			if candle.OpenTime.Before(first) || candle.OpenTime.After(last) {
				continue
			}
			closeTime, err := candle.CloseTime(configuration.Timeframe)
			if err != nil {
				return nil, err
			}
			if closeTime.After(now) {
				continue
			}
			candles = append(candles, candle)
		}
	}

	return candles, nil
}

func kline(row []json.RawMessage) (Candle, error) {
	var openTime int64
	if err := json.Unmarshal(row[0], &openTime); err != nil {
		return Candle{}, fmt.Errorf("decode candle open time: %w", err)
	}

	values := make([]string, 5)
	for index := range values {
		if err := json.Unmarshal(row[index+1], &values[index]); err != nil {
			return Candle{}, fmt.Errorf("decode candle value: %w", err)
		}
	}

	return DecodeCandle(CandleJSON{
		OpenTime: formatAtom(time.UnixMilli(openTime).UTC()),
		Open:     values[0],
		High:     values[1],
		Low:      values[2],
		Close:    values[3],
		Volume:   values[4],
	})
}
