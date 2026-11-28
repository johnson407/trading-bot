// Package strategy implements the runtime strategy contract and the
// simple, dynamic, martingale, and trailing grid implementations. Strategies
// never touch a database or an exchange: they receive an immutable Input and
// return a Decision.
package strategy

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// Side mirrors App\Domain\Order\Enums\OrderSide.
type Side string

const (
	SideBuy  Side = "buy"
	SideSell Side = "sell"
)

// SpacingType mirrors App\Domain\Grid\Enums\GridSpacingType.
type SpacingType string

const (
	SpacingArithmetic SpacingType = "arithmetic"
	SpacingGeometric  SpacingType = "geometric"
	SpacingDynamic    SpacingType = "dynamic"
)

// Strategy is the Go equivalent of App\Domain\Strategy\Contracts\
// StrategyInterface. The PHP contract is idempotent initialize, tick, fill,
// cancel, stop, and a level plan query.
type Strategy interface {
	Key() string
	Initialize(input Input) (Decision, error)
	OnTick(input Input) (Decision, error)
	OnFill(input Input, fill FillEvent) (Decision, error)
	OnCancel(input Input, cancel CancelEvent) (Decision, error)
	ShouldStop(input Input) bool
	GetGridLevels(input Input) ([]GridLevelPlan, error)
}

// MarketSnapshot mirrors App\Domain\Strategy\ValueObjects\MarketSnapshot.
type MarketSnapshot struct {
	Symbol       string
	Price        decimal.Decimal
	Bid          *decimal.Decimal
	Ask          *decimal.Decimal
	RecentPrices []decimal.Decimal
	Timestamp    *time.Time
}

// OpenOrderSnapshot mirrors App\Domain\Strategy\ValueObjects\
// OpenOrderSnapshot.
type OpenOrderSnapshot struct {
	ClientOrderID     string
	Side              Side
	Price             decimal.Decimal
	Quantity          decimal.Decimal
	GridLevelSequence *int
}

// BotSnapshot mirrors App\Domain\Strategy\ValueObjects\BotSnapshot.
type BotSnapshot struct {
	BotID             int
	BaseInventory     decimal.Decimal
	QuoteInventory    decimal.Decimal
	AverageEntryPrice decimal.Decimal
	RealizedPnL       decimal.Decimal
	CapitalAllocation decimal.Decimal
	OpenOrders        []OpenOrderSnapshot
}

// HasOpenOrderAt mirrors BotSnapshot::hasOpenOrderAt.
func (b BotSnapshot) HasOpenOrderAt(price decimal.Decimal) bool {
	for _, order := range b.OpenOrders {
		if order.Price.Equals(price) {
			return true
		}
	}

	return false
}

// WithOpenOrders mirrors BotSnapshot::withOpenOrders.
func (b BotSnapshot) WithOpenOrders(openOrders []OpenOrderSnapshot) BotSnapshot {
	b.OpenOrders = openOrders

	return b
}

// Config mirrors App\Domain\Strategy\ValueObjects\StrategyConfiguration.
type Config struct {
	Key    string
	Values map[string]any
}

// Get mirrors StrategyConfiguration::get (`values[$key] ?? $default`).
func (c Config) Get(key string, defaultValue any) any {
	if value, ok := c.Values[key]; ok && value != nil {
		return value
	}

	return defaultValue
}

// Has mirrors StrategyConfiguration::has (array_key_exists).
func (c Config) Has(key string) bool {
	_, ok := c.Values[key]

	return ok
}

// Decimal mirrors StrategyConfiguration::decimal.
func (c Config) Decimal(key string, defaultValue any) (decimal.Decimal, error) {
	value := c.Get(key, defaultValue)

	if value == nil {
		return decimal.Decimal{}, invalidConfig(c.Key, "["+key+"] is required")
	}

	// A JSON integer is the equivalent of PHP's int. A non-integer JSON number
	// would decode as a PHP float, which Decimal::of rejects.
	if number, ok := value.(json.Number); ok {
		if !strings.ContainsAny(number.String(), ".eE") {
			parsed, err := strconv.Atoi(number.String())
			if err == nil {
				return decimal.Of(parsed)
			}
		}

		return decimal.Decimal{}, decimal.ErrOnlyDecimalValues
	}

	return decimal.Of(value)
}

// Int mirrors StrategyConfiguration::int (int or ctype_digit string).
func (c Config) Int(key string, defaultValue any) (int, error) {
	if value, ok := configInt(c.Get(key, defaultValue)); ok {
		return value, nil
	}

	return 0, invalidConfig(c.Key, "["+key+"] must be an integer")
}

func configInt(value any) (int, bool) {
	switch typed := value.(type) {
	case int:
		return typed, true
	case int64:
		return int(typed), true
	case json.Number:
		if strings.ContainsAny(typed.String(), ".eE") {
			return 0, false
		}

		parsed, err := strconv.Atoi(typed.String())

		return parsed, err == nil
	case string:
		if !digitsOnly(typed) {
			return 0, false
		}

		parsed, err := strconv.Atoi(typed)

		return parsed, err == nil
	default:
		return 0, false
	}
}

func digitsOnly(value string) bool {
	if value == "" {
		return false
	}

	for index := 0; index < len(value); index++ {
		if value[index] < '0' || value[index] > '9' {
			return false
		}
	}

	return true
}

func asInt(value any) int {
	switch typed := value.(type) {
	case int:
		return typed
	case int64:
		return int(typed)
	case float64:
		return int(typed)
	case json.Number:
		parsed, _ := typed.Int64()

		return int(parsed)
	case string:
		parsed, _ := strconv.Atoi(typed)

		return parsed
	default:
		return 0
	}
}

// State mirrors App\Domain\Strategy\ValueObjects\StrategyState. Data is the
// PHP mixed array; decimal values are stored as normalized strings.
type State struct {
	Version int
	Data    map[string]any
}

// NewState returns an empty version-1 state.
func NewState() State {
	return State{Version: 1, Data: map[string]any{}}
}

// IsNew mirrors StrategyState::isNew (an empty data array).
func (s State) IsNew() bool {
	return len(s.Data) == 0
}

// Get mirrors StrategyState::get (`data[$key] ?? $default`).
func (s State) Get(key string, defaultValue any) any {
	if value, ok := s.Data[key]; ok && value != nil {
		return value
	}

	return defaultValue
}

// Merge mirrors StrategyState::merge (array_replace, version preserved).
func (s State) Merge(values map[string]any) State {
	data := make(map[string]any, len(s.Data)+len(values))
	for key, value := range s.Data {
		data[key] = value
	}

	for key, value := range values {
		data[key] = value
	}

	return State{Version: s.Version, Data: data}
}

// GridLevelPlan mirrors App\Domain\Strategy\ValueObjects\GridLevelPlan.
type GridLevelPlan struct {
	Sequence int
	Price    decimal.Decimal
	Side     Side
}

// OrderIntent mirrors App\Domain\Strategy\ValueObjects\OrderIntent.
type OrderIntent struct {
	Side              Side
	Price             decimal.Decimal
	Quantity          decimal.Decimal
	GridLevelSequence *int
	Reason            string
}

// FillEvent mirrors App\Domain\Strategy\ValueObjects\FillEvent.
//
// Price is the execution price, which may differ from the order's limit price
// when the market gaps through it. OrderPrice identifies the filled level and
// is used for disarm bookkeeping; it falls back to Price when zero.
type FillEvent struct {
	ExchangeFillID    string
	Side              Side
	Price             decimal.Decimal
	OrderPrice        decimal.Decimal
	Quantity          decimal.Decimal
	Fee               decimal.Decimal
	ClientOrderID     *string
	GridLevelSequence *int
}

// LevelPrice returns the price that identifies the filled grid level.
func (f FillEvent) LevelPrice() decimal.Decimal {
	if f.OrderPrice.IsPositive() {
		return f.OrderPrice
	}

	return f.Price
}

// CancelEvent mirrors App\Domain\Strategy\ValueObjects\CancelEvent.
type CancelEvent struct {
	ClientOrderID     string
	GridLevelSequence *int
	Reason            string
}

// Decision mirrors App\Domain\Strategy\ValueObjects\StrategyDecision.
type Decision struct {
	State                State
	Levels               []GridLevelPlan
	Orders               []OrderIntent
	Stop                 bool
	Events               []any
	CancelClientOrderIDs []string
}

// Input mirrors App\Domain\Strategy\ValueObjects\StrategyInput.
type Input struct {
	Market MarketSnapshot
	Bot    BotSnapshot
	Config Config
	State  State
}

// WithState mirrors StrategyInput::withState.
func (i Input) WithState(state State) Input {
	i.State = state

	return i
}

// WithBot mirrors StrategyInput::withBot.
func (i Input) WithBot(bot BotSnapshot) Input {
	i.Bot = bot

	return i
}

// InvalidConfigurationError mirrors InvalidStrategyConfigurationException.
type InvalidConfigurationError struct {
	Strategy string
	Reason   string
}

func (e *InvalidConfigurationError) Error() string {
	return fmt.Sprintf("Invalid configuration for strategy [%s]: %s", e.Strategy, e.Reason)
}

func invalidConfig(strategy, reason string) error {
	return &InvalidConfigurationError{Strategy: strategy, Reason: reason}
}

func intPointer(value int) *int {
	return &value
}

func idle(state State) Decision {
	return Decision{State: state}
}
