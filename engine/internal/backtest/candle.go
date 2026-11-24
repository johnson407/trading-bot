package backtest

import (
	"bytes"
	"encoding/json"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// Candle mirrors App\Domain\Backtest\ValueObjects\Candle.
type Candle struct {
	OpenTime time.Time
	Open     decimal.Decimal
	High     decimal.Decimal
	Low      decimal.Decimal
	Close    decimal.Decimal
	Volume   decimal.Decimal
}

// CandleJSON is the stored representation of a candle row.
type CandleJSON struct {
	OpenTime string `json:"open_time"`
	Open     string `json:"open"`
	High     string `json:"high"`
	Low      string `json:"low"`
	Close    string `json:"close"`
	Volume   string `json:"volume"`
}

// DecodeCandle converts a stored candle row.
func DecodeCandle(raw CandleJSON) (Candle, error) {
	openTime, err := time.Parse(time.RFC3339Nano, raw.OpenTime)
	if err != nil {
		return Candle{}, err
	}

	values := make([]decimal.Decimal, 5)
	for index, value := range []string{raw.Open, raw.High, raw.Low, raw.Close, raw.Volume} {
		parsed, parseErr := decimal.Of(value)
		if parseErr != nil {
			return Candle{}, parseErr
		}
		values[index] = parsed
	}

	return Candle{
		OpenTime: openTime.UTC(),
		Open:     values[0],
		High:     values[1],
		Low:      values[2],
		Close:    values[3],
		Volume:   values[4],
	}, nil
}

// UnmarshalJSON decodes the fixture/stored shape with decimal strings.
func (c *Candle) UnmarshalJSON(data []byte) error {
	var raw CandleJSON
	decoder := json.NewDecoder(bytes.NewReader(data))
	if err := decoder.Decode(&raw); err != nil {
		return err
	}

	candle, err := DecodeCandle(raw)
	if err != nil {
		return err
	}

	*c = candle

	return nil
}

// CloseTime mirrors Candle::closeTime.
func (c Candle) CloseTime(timeframe Timeframe) (time.Time, error) {
	seconds, err := timeframe.Seconds()
	if err != nil {
		return time.Time{}, err
	}

	return c.OpenTime.UTC().Add(time.Duration(seconds) * time.Second), nil
}

// ToArray mirrors Candle::toArray.
func (c Candle) ToArray() map[string]any {
	return map[string]any{
		"open_time": formatAtom(c.OpenTime),
		"open":      c.Open.String(),
		"high":      c.High.String(),
		"low":       c.Low.String(),
		"close":     c.Close.String(),
		"volume":    c.Volume.String(),
	}
}
