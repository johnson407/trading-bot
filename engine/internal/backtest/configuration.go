// Package backtest ports the PHP backtest replay engine, sweep planning, and
// stale-run failure to Go. The engine is deterministic: money and quantities
// use the shared decimal package, fees are charged in the quote currency, and
// only candle closes can fill simulated orders.
package backtest

import (
	"bytes"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/risk"
)

// Timeframe mirrors App\Domain\Backtest\Enums\Timeframe.
type Timeframe string

// Supported candle timeframes.
const (
	Timeframe1m  Timeframe = "1m"
	Timeframe5m  Timeframe = "5m"
	Timeframe15m Timeframe = "15m"
	Timeframe1h  Timeframe = "1h"
	Timeframe4h  Timeframe = "4h"
	Timeframe1d  Timeframe = "1d"
)

// DefaultTimeframe mirrors config('backtest.default_timeframe', '15m').
const DefaultTimeframe = Timeframe15m

var timeframeSeconds = map[Timeframe]int{
	Timeframe1m:  60,
	Timeframe5m:  300,
	Timeframe15m: 900,
	Timeframe1h:  3600,
	Timeframe4h:  14400,
	Timeframe1d:  86400,
}

// ParseTimeframe validates a configured timeframe.
func ParseTimeframe(value string) (Timeframe, error) {
	timeframe := Timeframe(value)
	if _, ok := timeframeSeconds[timeframe]; !ok {
		return "", fmt.Errorf("Unsupported timeframe [%s].", value)
	}

	return timeframe, nil
}

// Seconds returns the candle length.
func (t Timeframe) Seconds() (int, error) {
	seconds, ok := timeframeSeconds[t]
	if !ok {
		return 0, fmt.Errorf("Unsupported timeframe [%s].", t)
	}

	return seconds, nil
}

// Snapshot is the immutable config_snapshot JSON stored on a backtest run.
type Snapshot struct {
	Bot      SnapshotBot      `json:"bot"`
	Strategy SnapshotStrategy `json:"strategy"`
	Risk     SnapshotRisk     `json:"risk"`
	Exit     SnapshotExit     `json:"exit"`
	Period   SnapshotPeriod   `json:"period"`
}

// SnapshotBot mirrors the bot half of BacktestConfiguration::toSnapshot.
type SnapshotBot struct {
	ID                int    `json:"id"`
	Name              string `json:"name"`
	Exchange          string `json:"exchange"`
	Symbol            string `json:"symbol"`
	StrategyKey       string `json:"strategy_key"`
	CapitalAllocation string `json:"capital_allocation"`
	FeeRate           string `json:"fee_rate"`
}

// SnapshotStrategy mirrors the strategy half of the snapshot.
type SnapshotStrategy struct {
	Key           string         `json:"key"`
	Configuration map[string]any `json:"configuration"`
}

// SnapshotRisk mirrors BacktestRiskLimits::toArray.
type SnapshotRisk struct {
	MaxPositionNotional string `json:"max_position_notional"`
	MaxOpenOrders       *int   `json:"max_open_orders"`
	DailyLossLimit      string `json:"daily_loss_limit"`
}

// SnapshotExit mirrors ExitRules::toArray.
type SnapshotExit struct {
	StopLossPercent   *string `json:"stop_loss_percent"`
	TakeProfitPercent *string `json:"take_profit_percent"`
	RangeLow          *string `json:"range_low"`
	RangeHigh         *string `json:"range_high"`
}

// SnapshotPeriod mirrors the period half of the snapshot.
type SnapshotPeriod struct {
	Timeframe string `json:"timeframe"`
	StartAt   string `json:"start_at"`
	EndAt     string `json:"end_at"`
}

// RiskLimits mirrors App\Domain\Backtest\ValueObjects\BacktestRiskLimits.
type RiskLimits struct {
	MaxPositionNotional decimal.Decimal
	MaxOpenOrders       int
	DailyLossLimit      decimal.Decimal
}

// Configuration mirrors BacktestConfiguration.
type Configuration struct {
	BotID                 int
	BotName               string
	Exchange              string
	Symbol                string
	StrategyKey           string
	Timeframe             Timeframe
	StartAt               time.Time
	EndAt                 time.Time
	CapitalAllocation     decimal.Decimal
	FeeRate               decimal.Decimal
	StrategyConfiguration map[string]any
	RiskLimits            RiskLimits
	ExitRules             risk.ExitRules
}

// DecodeSnapshot parses config_snapshot with PHP-compatible defaults.
func DecodeSnapshot(raw []byte) (Configuration, Snapshot, error) {
	var snapshot Snapshot
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(&snapshot); err != nil {
		return Configuration{}, Snapshot{}, err
	}

	configuration, err := configurationFromSnapshot(snapshot)
	if err != nil {
		return Configuration{}, Snapshot{}, err
	}

	return configuration, snapshot, nil
}

func configurationFromSnapshot(snapshot Snapshot) (Configuration, error) {
	bot := snapshot.Bot
	if bot.Name == "" {
		bot.Name = "Deleted bot"
	}
	if bot.Exchange == "" {
		bot.Exchange = "binance"
	}
	if bot.Symbol == "" {
		bot.Symbol = "BTC/USDT"
	}

	strategyKey := snapshot.Strategy.Key
	if strategyKey == "" {
		strategyKey = bot.StrategyKey
	}
	if strategyKey == "" {
		strategyKey = "simple-grid"
	}

	timeframeValue := snapshot.Period.Timeframe
	if timeframeValue == "" {
		timeframeValue = string(DefaultTimeframe)
	}
	timeframe, err := ParseTimeframe(timeframeValue)
	if err != nil {
		return Configuration{}, err
	}

	startAt, err := snapshotTime(snapshot.Period.StartAt)
	if err != nil {
		return Configuration{}, err
	}
	endAt, err := snapshotTime(snapshot.Period.EndAt)
	if err != nil {
		return Configuration{}, err
	}

	capital, err := snapshotDecimal(bot.CapitalAllocation, "0")
	if err != nil {
		return Configuration{}, err
	}
	feeRate, err := snapshotDecimal(bot.FeeRate, "0")
	if err != nil {
		return Configuration{}, err
	}

	maxPosition, err := snapshotDecimal(snapshot.Risk.MaxPositionNotional, "1000")
	if err != nil {
		return Configuration{}, err
	}
	dailyLoss, err := snapshotDecimal(snapshot.Risk.DailyLossLimit, "100")
	if err != nil {
		return Configuration{}, err
	}
	maxOpenOrders := 20
	if snapshot.Risk.MaxOpenOrders != nil {
		maxOpenOrders = *snapshot.Risk.MaxOpenOrders
	}

	configuration := snapshot.Strategy.Configuration
	if configuration == nil {
		configuration = map[string]any{}
	}

	return Configuration{
		BotID:                 bot.ID,
		BotName:               bot.Name,
		Exchange:              bot.Exchange,
		Symbol:                bot.Symbol,
		StrategyKey:           strategyKey,
		Timeframe:             timeframe,
		StartAt:               startAt,
		EndAt:                 endAt,
		CapitalAllocation:     capital,
		FeeRate:               feeRate,
		StrategyConfiguration: configuration,
		RiskLimits: RiskLimits{
			MaxPositionNotional: maxPosition,
			MaxOpenOrders:       maxOpenOrders,
			DailyLossLimit:      dailyLoss,
		},
		ExitRules: risk.NewExitRules(
			snapshot.Exit.StopLossPercent,
			snapshot.Exit.TakeProfitPercent,
			snapshot.Exit.RangeLow,
			snapshot.Exit.RangeHigh,
		),
	}, nil
}

func snapshotDecimal(value, fallback string) (decimal.Decimal, error) {
	if strings.TrimSpace(value) == "" {
		value = fallback
	}

	return decimal.Of(value)
}

func snapshotTime(value string) (time.Time, error) {
	value = strings.TrimSpace(value)
	if value == "" || value == "now" {
		return time.Now().UTC(), nil
	}

	parsed, err := time.Parse(time.RFC3339Nano, value)
	if err != nil {
		return time.Time{}, err
	}

	return parsed.UTC(), nil
}
