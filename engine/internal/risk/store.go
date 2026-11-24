package risk

import (
	"fmt"
	"strings"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// Store is the in-memory persistence boundary for the risk gates. It keeps
// insertion order for bots and orders so iteration is deterministic, mirroring
// the PHP engine's ordered database reads.
type Store struct {
	clock             time.Time
	nextBotID         int64
	nextOrderID       int64
	nextLimitID       int64
	bots              []*Bot
	orders            []*Order
	limits            []*Limit
	riskEvents        map[int64][]Event
	activity          map[int64][]Event
	defaultMaxPos     decimal.Decimal
	defaultDailyLimit decimal.Decimal
	defaultMaxOrders  int
}

// NewStore returns an empty store. clock is the engine's fixed evaluation time
// and is used for UTC day boundaries.
func NewStore(clock time.Time) *Store {
	return &Store{
		clock:             clock.UTC(),
		riskEvents:        make(map[int64][]Event),
		activity:          make(map[int64][]Event),
		defaultMaxPos:     decimal.MustParse("1000"),
		defaultDailyLimit: decimal.MustParse("100"),
		defaultMaxOrders:  20,
	}
}

// Clock returns the store's fixed evaluation time in UTC.
func (s *Store) Clock() time.Time {
	return s.clock
}

// StartOfDayUTC returns the UTC start of the store clock's day.
func (s *Store) StartOfDayUTC() time.Time {
	return startOfDayUTC(s.clock)
}

func startOfDayUTC(t time.Time) time.Time {
	utc := t.UTC()
	return time.Date(utc.Year(), utc.Month(), utc.Day(), 0, 0, 0, 0, time.UTC)
}

// AddBot appends a bot and assigns an ID when one is not set.
func (s *Store) AddBot(bot *Bot) *Bot {
	if bot.ID == 0 {
		s.nextBotID++
		bot.ID = s.nextBotID
	}
	if bot.Settings == nil {
		bot.Settings = map[string]any{}
	}
	s.bots = append(s.bots, bot)
	return bot
}

// AddOrder appends an order and assigns an ID when one is not set.
func (s *Store) AddOrder(order *Order) *Order {
	if order.ID == 0 {
		s.nextOrderID++
		order.ID = s.nextOrderID
	}
	s.orders = append(s.orders, order)
	return order
}

// AddUserLimit creates a user-scope limit row with the supplied daily loss
// limit and the documented defaults for the other fields.
func (s *Store) AddUserLimit(userID int64, dailyLossLimit string) *Limit {
	return s.AddUserLimitValues(userID, "1000", 20, dailyLossLimit, s.StartOfDayUTC(), "0")
}

// AddUserLimitValues creates a user-scope limit row with explicit values.
func (s *Store) AddUserLimitValues(userID int64, maxPositionNotional string, maxOpenOrders int, dailyLossLimit string, dayStart time.Time, dayStartRealized string) *Limit {
	return s.addLimit(&Limit{
		UserID:              userID,
		Scope:               "user",
		MaxPositionNotional: decimal.MustParse(maxPositionNotional),
		MaxOpenOrders:       maxOpenOrders,
		DailyLossLimit:      decimal.MustParse(dailyLossLimit),
		DayStart:            dayStart.UTC(),
		DayStartRealizedPnL: decimal.MustParse(dayStartRealized),
	})
}

// AddBotLimit creates a bot-scope override row with explicit values.
func (s *Store) AddBotLimit(bot *Bot, maxPositionNotional string, maxOpenOrders int, dailyLossLimit string, dayStart time.Time, dayStartRealized string) *Limit {
	botID := bot.ID
	return s.addLimit(&Limit{
		UserID:              bot.UserID,
		BotID:               &botID,
		Scope:               "bot",
		MaxPositionNotional: decimal.MustParse(maxPositionNotional),
		MaxOpenOrders:       maxOpenOrders,
		DailyLossLimit:      decimal.MustParse(dailyLossLimit),
		DayStart:            dayStart.UTC(),
		DayStartRealizedPnL: decimal.MustParse(dayStartRealized),
	})
}

func (s *Store) addLimit(limit *Limit) *Limit {
	s.nextLimitID++
	limit.ID = s.nextLimitID
	s.limits = append(s.limits, limit)
	return limit
}

// AddOpenOrder creates an open limit order for the bot.
func (s *Store) AddOpenOrder(bot *Bot, clientOrderID, side, price, quantity string) *Order {
	return s.AddOrder(&Order{
		BotID:          bot.ID,
		ClientOrderID:  clientOrderID,
		Side:           side,
		Type:           "limit",
		Status:         OrderOpen,
		Mode:           bot.Mode,
		Price:          decimal.MustParse(price),
		Quantity:       decimal.MustParse(quantity),
		FilledQuantity: decimal.Zero(),
	})
}

// Bot returns the bot with the supplied ID, or nil.
func (s *Store) Bot(id int64) *Bot {
	for _, bot := range s.bots {
		if bot.ID == id {
			return bot
		}
	}

	return nil
}

// BotsForUser returns the user's bots in insertion order.
func (s *Store) BotsForUser(userID int64) []*Bot {
	bots := make([]*Bot, 0)

	for _, bot := range s.bots {
		if bot.UserID == userID {
			bots = append(bots, bot)
		}
	}

	return bots
}

// OrdersForBot returns the bot's orders in insertion (ID) order.
func (s *Store) OrdersForBot(botID int64) []*Order {
	orders := make([]*Order, 0)

	for _, order := range s.orders {
		if order.BotID == botID {
			orders = append(orders, order)
		}
	}

	return orders
}

// OpenOrdersForBot returns pending, open, and partially filled orders.
func (s *Store) OpenOrdersForBot(botID int64) []*Order {
	orders := make([]*Order, 0)

	for _, order := range s.orders {
		if order.BotID == botID && order.IsOpen() {
			orders = append(orders, order)
		}
	}

	return orders
}

// BotOverride returns the bot-scope limit row, or nil.
func (s *Store) BotOverride(bot *Bot) *Limit {
	for _, limit := range s.limits {
		if limit.Scope == "bot" && limit.BotID != nil && *limit.BotID == bot.ID && limit.UserID == bot.UserID {
			return limit
		}
	}

	return nil
}

// UserLimit returns the user-scope limit row without creating one.
func (s *Store) UserLimit(userID int64) *Limit {
	for _, limit := range s.limits {
		if limit.Scope == "user" && limit.BotID == nil && limit.UserID == userID {
			return limit
		}
	}

	return nil
}

// EnsureUserLimit returns the user-scope row, creating the documented defaults
// when it does not exist. This mirrors RiskLimitService::forUser.
func (s *Store) EnsureUserLimit(userID int64) *Limit {
	if limit := s.UserLimit(userID); limit != nil {
		return limit
	}

	return s.AddUserLimit(userID, "100")
}

// EffectiveLimit returns the bot override, otherwise the user row, otherwise
// the configured defaults. When createUser is true a missing user row is
// created, matching RiskLimitService::forBot.
func (s *Store) EffectiveLimit(bot *Bot, createUser bool) *Limit {
	if override := s.BotOverride(bot); override != nil {
		return override
	}

	if user := s.UserLimit(bot.UserID); user != nil {
		return user
	}

	if !createUser {
		return nil
	}

	return s.EnsureUserLimit(bot.UserID)
}

// EffectiveRiskLimits resolves the three values RiskGuard reads, using config
// defaults when no row exists and never creating one.
func (s *Store) EffectiveRiskLimits(bot *Bot) (maxPositionNotional decimal.Decimal, maxOpenOrders int, dailyLossLimit decimal.Decimal) {
	limit := s.EffectiveLimit(bot, false)
	if limit == nil {
		return s.defaultMaxPos, s.defaultMaxOrders, s.defaultDailyLimit
	}

	return limit.MaxPositionNotional, limit.MaxOpenOrders, limit.DailyLossLimit
}

// RecordRisk appends a risk event for the bot.
func (s *Store) RecordRisk(bot *Bot, eventType, severity, message string, context map[string]any) {
	s.riskEvents[bot.ID] = append(s.riskEvents[bot.ID], Event{
		Type:     eventType,
		Severity: severity,
		Message:  message,
		Context:  context,
	})
}

// RecordActivity appends an activity log entry for the bot.
func (s *Store) RecordActivity(bot *Bot, eventType, severity, message string, context map[string]any) {
	s.activity[bot.ID] = append(s.activity[bot.ID], Event{
		Type:     eventType,
		Severity: severity,
		Message:  message,
		Context:  context,
	})
}

// RiskEvents returns the bot's risk events in insertion order.
func (s *Store) RiskEvents(botID int64) []Event {
	return s.riskEvents[botID]
}

// Activity returns the bot's activity log in insertion order.
func (s *Store) Activity(botID int64) []Event {
	return s.activity[botID]
}

// OrderCanceler cancels one order. Guards accept an injected implementation so
// tests (and exchange failure paths) can reject a cancellation.
type OrderCanceler interface {
	Cancel(order *Order) error
}

// CancelOrder is the default paper canceler: it marks the order canceled and
// records the order.canceled activity entry. It never calls an exchange.
func (s *Store) CancelOrder(order *Order) error {
	switch order.Status {
	case OrderCanceled, OrderFilled, OrderRejected:
		return nil
	}

	order.Status = OrderCanceled
	bot := s.Bot(order.BotID)
	if bot == nil {
		return nil
	}

	s.RecordActivity(bot, "order.canceled", "info", fmt.Sprintf("Order %s canceled", order.ClientOrderID), map[string]any{
		"order_id":          order.ID,
		"client_order_id":   order.ClientOrderID,
		"exchange_order_id": order.ExchangeOrderID,
		"price":             fixed16(order.Price),
		"quantity":          fixed16(order.Quantity),
	})

	return nil
}

// CancelFailure reports one order that could not be canceled.
type CancelFailure struct {
	Order *Order
	Err   error
}

// CancelOpenOrders cancels each open order, returning the failures. A nil
// canceler uses the store's paper canceler.
func (s *Store) CancelOpenOrders(botID int64, canceler OrderCanceler) []CancelFailure {
	failures := make([]CancelFailure, 0)

	for _, order := range s.OpenOrdersForBot(botID) {
		var err error
		if canceler == nil {
			err = s.CancelOrder(order)
		} else {
			err = canceler.Cancel(order)
		}

		if err != nil {
			failures = append(failures, CancelFailure{Order: order, Err: err})
		}
	}

	return failures
}

func fixed16(value decimal.Decimal) string {
	fixed, err := value.ToFixed(decimal.Scale)
	if err != nil {
		panic(err)
	}

	return fixed
}

func trimmedOptional(value *string) *string {
	if value == nil {
		return nil
	}

	trimmed := strings.TrimSpace(*value)
	if trimmed == "" {
		return nil
	}

	return &trimmed
}

func maxDecimal(a, b decimal.Decimal) decimal.Decimal {
	if a.GreaterThan(b) {
		return a
	}

	return b
}
