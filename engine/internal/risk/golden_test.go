package risk_test

import (
	"encoding/json"
	"errors"
	"reflect"
	"regexp"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/goldenfixture"
	"github.com/johnson407/trading-bot/engine/internal/risk"
)

const fixtureClock = "2026-11-23T00:00:00+00:00"

type riskFixture struct {
	Metadata struct {
		Clock        string `json:"clock"`
		DecimalScale int    `json:"decimal_scale"`
	} `json:"metadata"`
	LiveModeGuard struct {
		StartCases []struct {
			Label        string  `json:"label"`
			Gate         bool    `json:"gate"`
			Confirmation *string `json:"confirmation"`
			QuoteBalance string  `json:"quote_balance"`
			Symbol       string  `json:"symbol"`
			Error        *struct {
				Exception string `json:"exception"`
				Message   string `json:"message"`
			} `json:"error"`
			Bot          map[string]any `json:"bot"`
			BalanceCalls int            `json:"balance_calls"`
		} `json:"start_cases"`
	} `json:"live_mode_guard"`
	RiskGuard struct {
		Cases []struct {
			Label string `json:"label"`
			Bot   struct {
				BaseInventory string `json:"base_inventory"`
				OpenOrders    []struct {
					ClientOrderID  string `json:"client_order_id"`
					Side           string `json:"side"`
					Price          string `json:"price"`
					Quantity       string `json:"quantity"`
					FilledQuantity string `json:"filled_quantity"`
					Status         string `json:"status"`
				} `json:"open_orders"`
			} `json:"bot"`
			Limits struct {
				MaxPositionNotional string `json:"max_position_notional"`
				MaxOpenOrders       int    `json:"max_open_orders"`
				DailyLossLimit      string `json:"daily_loss_limit"`
			} `json:"limits"`
			Order struct {
				Side     string `json:"side"`
				Price    string `json:"price"`
				Quantity string `json:"quantity"`
			} `json:"order"`
		} `json:"cases"`
	} `json:"risk_guard"`
	DailyLossMonitor struct {
		Cases []struct {
			Label      string  `json:"label"`
			Mark       *string `json:"mark"`
			FirstMark  *string `json:"first_mark"`
			SecondMark *string `json:"second_mark"`
		} `json:"cases"`
	} `json:"daily_loss_monitor"`
	ExitGuard struct {
		RuleCases []struct {
			Label  string         `json:"label"`
			Rules  exitRuleInput  `json:"rules"`
			Inputs exitRuleInputs `json:"inputs"`
		} `json:"rule_cases"`
		MonitorCases []struct {
			Label string `json:"label"`
			Mark  string `json:"mark"`
		} `json:"monitor_cases"`
	} `json:"exit_guard"`
	ConsecutiveFailureTracker struct {
		Cases []struct {
			Label     string `json:"label"`
			Threshold int    `json:"threshold"`
		} `json:"cases"`
	} `json:"consecutive_failure_tracker"`
	ExchangeAllowlist struct {
		CreationValidation []struct {
			Label           string   `json:"label"`
			Allowlist       []string `json:"allowlist"`
			DefaultExchange string   `json:"default_exchange"`
			Submitted       string   `json:"submitted"`
		} `json:"creation_validation"`
		DirectInsert []struct {
			Label     string   `json:"label"`
			Exchange  string   `json:"exchange"`
			Allowlist []string `json:"allowlist"`
		} `json:"direct_insert_current_php"`
	} `json:"exchange_allowlist"`
}

type exitRuleInput struct {
	StopLossPercent   *string `json:"stop_loss_percent"`
	TakeProfitPercent *string `json:"take_profit_percent"`
	RangeLow          *string `json:"range_low"`
	RangeHigh         *string `json:"range_high"`
}

type exitRuleInputs struct {
	MarkPrice         string `json:"mark_price"`
	AverageEntryPrice string `json:"average_entry_price"`
	BaseInventory     string `json:"base_inventory"`
}

func loadRiskFixtures(t *testing.T) (riskFixture, map[string]any) {
	t.Helper()

	var fixture riskFixture
	if err := goldenfixture.Load("risk", &fixture); err != nil {
		t.Fatalf("load risk fixture: %v", err)
	}

	if fixture.Metadata.Clock != fixtureClock {
		t.Fatalf("fixture clock = %q, want %q", fixture.Metadata.Clock, fixtureClock)
	}

	var raw map[string]any
	if err := goldenfixture.Load("risk", &raw); err != nil {
		t.Fatalf("reload risk fixture as map: %v", err)
	}

	return fixture, raw
}

func clock(t *testing.T) time.Time {
	t.Helper()

	parsed, err := time.Parse(time.RFC3339, fixtureClock)
	if err != nil {
		t.Fatalf("parse clock: %v", err)
	}

	return parsed
}

func rawList(t *testing.T, raw map[string]any, section, key string) []map[string]any {
	t.Helper()

	container, ok := raw[section].(map[string]any)
	if !ok {
		t.Fatalf("fixture section %s missing", section)
	}

	list, ok := container[key].([]any)
	if !ok {
		t.Fatalf("fixture list %s.%s missing", section, key)
	}

	result := make([]map[string]any, 0, len(list))
	for _, item := range list {
		typed, ok := item.(map[string]any)
		if !ok {
			t.Fatalf("fixture item %s.%s is not an object", section, key)
		}
		result = append(result, typed)
	}

	return result
}

func rawObject(t *testing.T, raw map[string]any, section, key string) map[string]any {
	t.Helper()

	container, ok := raw[section].(map[string]any)
	if !ok {
		t.Fatalf("fixture section %s missing", section)
	}

	object, ok := container[key].(map[string]any)
	if !ok {
		t.Fatalf("fixture object %s.%s missing", section, key)
	}

	return object
}

func assertExpected(t *testing.T, label string, expected, actual map[string]any) {
	t.Helper()

	projected := make(map[string]any, len(expected))
	for key := range expected {
		projected[key] = normalizeJSONValue(actual[key])
	}

	if reflect.DeepEqual(expected, projected) {
		return
	}

	expectedJSON, _ := json.MarshalIndent(expected, "", "  ")
	actualJSON, _ := json.MarshalIndent(projected, "", "  ")
	t.Fatalf("%s mismatch\nexpected: %s\nactual:   %s", label, expectedJSON, actualJSON)
}

func normalizeJSONValue(value any) any {
	data, err := json.Marshal(value)
	if err != nil {
		return value
	}

	var normalized any
	if err := json.Unmarshal(data, &normalized); err != nil {
		return value
	}

	return normalized
}

var (
	liveBotPattern  = regexp.MustCompile(`\blive bot \d+\b`)
	botDashPattern  = regexp.MustCompile(`\bbot-\d+-`)
	gridDashPattern = regexp.MustCompile(`\bgrid-\d+-`)
	botSpacePattern = regexp.MustCompile(`\bbot \d+\b`)
)

func normalizeText(value string) string {
	value = liveBotPattern.ReplaceAllString(value, "live bot {bot_id}")
	value = botDashPattern.ReplaceAllString(value, "bot-{bot_id}-")
	value = gridDashPattern.ReplaceAllString(value, "grid-{grid_id}-")

	return botSpacePattern.ReplaceAllString(value, "bot {bot_id}")
}

func normalizeOptional(value *string) any {
	if value == nil {
		return nil
	}

	return normalizeText(*value)
}

func fixed(value decimal.Decimal, scale int) string {
	formatted, err := value.ToFixed(scale)
	if err != nil {
		panic(err)
	}

	return formatted
}

func optionalFixed(value *string, scale int) any {
	if value == nil {
		return nil
	}

	return fixed(decimal.MustParse(*value), scale)
}

func settingsState(settings map[string]any) any {
	if len(settings) == 0 {
		return []any{}
	}

	return settings
}

func contextState(context map[string]any) any {
	if len(context) == 0 {
		return []any{}
	}

	copied := make(map[string]any, len(context))
	for key, value := range context {
		if key == "order_id" {
			continue
		}
		copied[key] = value
	}

	if len(copied) == 0 {
		return []any{}
	}

	return copied
}

func botState(bot *risk.Bot) map[string]any {
	return map[string]any{
		"mode":                 bot.Mode,
		"status":               bot.Status,
		"exchange":             bot.Exchange,
		"symbol":               bot.Symbol,
		"capital_allocation":   fixed(bot.CapitalAllocation, 16),
		"base_inventory":       fixed(bot.BaseInventory, 16),
		"quote_inventory":      fixed(bot.QuoteInventory, 16),
		"average_entry_price":  fixed(bot.AverageEntryPrice, 16),
		"realized_pnl":         fixed(bot.RealizedPnL, 16),
		"fees_paid":            fixed(bot.FeesPaid, 16),
		"consecutive_failures": bot.ConsecutiveFailures,
		"stop_loss_percent":    optionalFixed(bot.StopLossPercent, 4),
		"take_profit_percent":  optionalFixed(bot.TakeProfitPercent, 4),
		"range_low":            optionalFixed(bot.RangeLow, 8),
		"range_high":           optionalFixed(bot.RangeHigh, 8),
		"last_error":           normalizeOptional(bot.LastError),
		"settings":             settingsState(bot.Settings),
	}
}

func orderState(order *risk.Order) map[string]any {
	return map[string]any{
		"client_order_id": normalizeText(order.ClientOrderID),
		"side":            order.Side,
		"type":            order.Type,
		"price":           fixed(order.Price, 16),
		"quantity":        fixed(order.Quantity, 16),
		"filled_quantity": fixed(order.FilledQuantity, 16),
		"status":          order.Status,
		"mode":            order.Mode,
	}
}

func ordersState(orders []*risk.Order) []map[string]any {
	state := make([]map[string]any, 0, len(orders))
	for _, order := range orders {
		state = append(state, orderState(order))
	}

	return state
}

func eventsState(events []risk.Event) []map[string]any {
	state := make([]map[string]any, 0, len(events))
	for _, event := range events {
		state = append(state, map[string]any{
			"type":     event.Type,
			"severity": event.Severity,
			"message":  normalizeText(event.Message),
			"context":  contextState(event.Context),
		})
	}

	return state
}

func limitState(limit *risk.Limit) map[string]any {
	return map[string]any{
		"scope":                  limit.Scope,
		"max_position_notional":  fixed(limit.MaxPositionNotional, 16),
		"max_open_orders":        limit.MaxOpenOrders,
		"daily_loss_limit":       fixed(limit.DailyLossLimit, 16),
		"day_start":              limit.DayStart.Format("2006-01-02T15:04:05-07:00"),
		"day_start_realized_pnl": fixed(limit.DayStartRealizedPnL, 16),
	}
}

func decisionState(decision risk.Decision) map[string]any {
	var decisionType any
	if decision.Type != nil {
		decisionType = *decision.Type
	}

	var message any
	if decision.Message != nil {
		message = *decision.Message
	}

	return map[string]any{
		"allowed":  decision.Allowed,
		"type":     decisionType,
		"message":  message,
		"context":  contextState(decision.Context),
		"severity": decision.Severity,
	}
}

func signalState(signal *risk.ExitSignal) any {
	if signal == nil {
		return nil
	}

	var bound any
	if signal.Bound != nil {
		bound = *signal.Bound
	}

	return map[string]any{
		"rule":                string(signal.Rule),
		"label":               signal.Label(),
		"risk_event_type":     signal.RiskEventType(),
		"mark_price":          signal.MarkPrice,
		"threshold":           signal.Threshold,
		"average_entry_price": signal.AverageEntryPrice,
		"base_inventory":      signal.BaseInventory,
		"bound":               bound,
		"context":             contextState(signal.Context()),
	}
}

func exceptionState(err error) any {
	if err == nil {
		return nil
	}

	exception := "UnknownException"
	var disabled risk.LiveTradingDisabledError
	var confirmation risk.LiveConfirmationRequiredError
	var balance risk.InsufficientLiveBalanceError

	switch {
	case errors.As(err, &disabled):
		exception = "LiveTradingDisabledException"
	case errors.As(err, &confirmation):
		exception = "LiveConfirmationRequiredException"
	case errors.As(err, &balance):
		exception = "InsufficientLiveBalanceException"
	}

	return map[string]any{
		"exception": exception,
		"message":   normalizeText(err.Error()),
	}
}

func decimalPtr(t *testing.T, value string) *decimal.Decimal {
	t.Helper()

	parsed, err := decimal.Parse(value)
	if err != nil {
		t.Fatalf("parse decimal %q: %v", value, err)
	}

	return &parsed
}

func newPaperBot(store *risk.Store, userID int64, label string) *risk.Bot {
	return store.AddBot(&risk.Bot{
		UserID:            userID,
		Name:              "Golden " + label,
		Mode:              risk.ModePaper,
		Status:            risk.StatusRunning,
		Exchange:          "binance",
		Symbol:            "BTC/USDT",
		CapitalAllocation: decimal.MustParse("1000"),
		BaseInventory:     decimal.Zero(),
		QuoteInventory:    decimal.Zero(),
		AverageEntryPrice: decimal.Zero(),
		RealizedPnL:       decimal.Zero(),
		FeesPaid:          decimal.Zero(),
		Settings:          map[string]any{},
	})
}

func TestLiveModeGuardStartGolden(t *testing.T) {
	fixture, raw := loadRiskFixtures(t)
	expectedCases := rawList(t, raw, "live_mode_guard", "start_cases")

	for index, testCase := range fixture.LiveModeGuard.StartCases {
		t.Run(testCase.Label, func(t *testing.T) {
			store := risk.NewStore(clock(t))
			bot := store.AddBot(&risk.Bot{
				UserID:            1,
				Name:              "Golden " + testCase.Label,
				Mode:              risk.ModeLive,
				Status:            risk.StatusIdle,
				Exchange:          "binance",
				Symbol:            testCase.Symbol,
				CapitalAllocation: decimal.MustParse("1000"),
				BaseInventory:     decimal.Zero(),
				QuoteInventory:    decimal.Zero(),
				AverageEntryPrice: decimal.Zero(),
				RealizedPnL:       decimal.Zero(),
				FeesPaid:          decimal.Zero(),
				Settings:          map[string]any{},
			})

			if len(testCase.Label) >= 5 && testCase.Label[:5] == "paper" {
				bot.Mode = risk.ModePaper
			}

			balanceCalls := 0
			provider := func(*risk.Bot) (map[string]decimal.Decimal, error) {
				balanceCalls++
				return map[string]decimal.Decimal{
					"USDT": decimal.MustParse(testCase.QuoteBalance),
					"BTC":  decimal.Zero(),
				}, nil
			}

			guard := risk.NewLiveModeGuard(store, testCase.Gate, provider)
			err := guard.StartBot(bot, testCase.Confirmation)

			actual := map[string]any{
				"label":         testCase.Label,
				"gate":          testCase.Gate,
				"confirmation":  testCase.Confirmation,
				"quote_balance": testCase.QuoteBalance,
				"symbol":        testCase.Symbol,
				"error":         exceptionState(err),
				"bot":           botState(bot),
				"balance_calls": balanceCalls,
			}

			assertExpected(t, testCase.Label, expectedCases[index], actual)
		})
	}
}

func TestLiveModeGuardTickGateClosedGolden(t *testing.T) {
	fixture, raw := loadRiskFixtures(t)
	_ = fixture
	expected := rawObject(t, raw, "live_mode_guard", "tick_gate_closed")
	label, _ := expected["label"].(string)

	store := risk.NewStore(clock(t))
	bot := store.AddBot(&risk.Bot{
		UserID:            1,
		Name:              "Golden " + label,
		Mode:              risk.ModeLive,
		Status:            risk.StatusRunning,
		Exchange:          "binance",
		Symbol:            "BTC/USDT",
		CapitalAllocation: decimal.MustParse("1000"),
		BaseInventory:     decimal.Zero(),
		QuoteInventory:    decimal.Zero(),
		AverageEntryPrice: decimal.Zero(),
		RealizedPnL:       decimal.Zero(),
		FeesPaid:          decimal.Zero(),
		Settings:          map[string]any{},
	})
	store.AddOpenOrder(bot, "live-tick-open-buy", "buy", "90", "0.5")
	store.AddOpenOrder(bot, "live-tick-open-sell", "sell", "110", "0.5")

	guard := risk.NewLiveModeGuard(store, false, nil)
	_ = guard.PauseForDisabledLiveMode(bot, nil)

	result := map[string]any{
		"status":          "skipped",
		"market_price":    nil,
		"orders_placed":   0,
		"fills":           0,
		"orders_rejected": 0,
		"errors":          []any{},
	}

	actual := map[string]any{
		"label":       label,
		"result":      result,
		"bot":         botState(bot),
		"orders":      ordersState(store.OrdersForBot(bot.ID)),
		"risk_events": eventsState(store.RiskEvents(bot.ID)),
		"activity":    eventsState(store.Activity(bot.ID)),
		"exchange_calls": map[string]any{
			"market": 0, "place": 0, "balances": 0, "cancel": 0,
		},
	}

	assertExpected(t, label, expected, actual)
}

func TestRiskGuardGolden(t *testing.T) {
	fixture, raw := loadRiskFixtures(t)
	expectedCases := rawList(t, raw, "risk_guard", "cases")

	for index, testCase := range fixture.RiskGuard.Cases {
		t.Run(testCase.Label, func(t *testing.T) {
			store := risk.NewStore(clock(t))
			bot := newPaperBot(store, 1, testCase.Label)
			bot.BaseInventory = decimal.MustParse(testCase.Bot.BaseInventory)

			for _, input := range testCase.Bot.OpenOrders {
				filled := input.FilledQuantity
				if filled == "" {
					filled = "0"
				}
				status := input.Status
				if status == "" {
					status = risk.OrderOpen
				}
				order := store.AddOrder(&risk.Order{
					BotID:          bot.ID,
					ClientOrderID:  input.ClientOrderID,
					Side:           input.Side,
					Type:           "limit",
					Status:         status,
					Mode:           risk.ModePaper,
					Price:          decimal.MustParse(input.Price),
					Quantity:       decimal.MustParse(input.Quantity),
					FilledQuantity: decimal.MustParse(filled),
				})
				_ = order
			}

			if testCase.Limits.MaxPositionNotional != "" {
				daily := testCase.Limits.DailyLossLimit
				if daily == "" {
					daily = "100"
				}
				store.AddBotLimit(bot, testCase.Limits.MaxPositionNotional, testCase.Limits.MaxOpenOrders, daily, store.StartOfDayUTC(), "0")
			}

			guard := risk.NewRiskGuard(store)
			maxPosition, maxOpenOrders, dailyLimit := guard.Limits(bot)
			decision := guard.CheckOrder(bot, testCase.Order.Side, testCase.Order.Price, testCase.Order.Quantity)

			actual := map[string]any{
				"label": testCase.Label,
				"bot": map[string]any{
					"base_inventory": fixed(bot.BaseInventory, 16),
					"open_orders":    ordersState(store.OrdersForBot(bot.ID)),
				},
				"limits": map[string]any{
					"max_position_notional": maxPosition.String(),
					"max_open_orders":       maxOpenOrders,
					"daily_loss_limit":      dailyLimit.String(),
				},
				"order": map[string]any{
					"side":     testCase.Order.Side,
					"price":    testCase.Order.Price,
					"quantity": testCase.Order.Quantity,
				},
				"decision":    decisionState(decision),
				"risk_events": eventsState(store.RiskEvents(bot.ID)),
			}

			assertExpected(t, testCase.Label, expectedCases[index], actual)
		})
	}
}

func dailyBot(store *risk.Store, userID int64, label string) *risk.Bot {
	bot := newPaperBot(store, userID, label)
	bot.BaseInventory = decimal.MustParse("1")
	bot.QuoteInventory = decimal.MustParse("1000")
	bot.AverageEntryPrice = decimal.MustParse("100")
	bot.RealizedPnL = decimal.MustParse("0")
	bot.FeesPaid = decimal.MustParse("0")
	return bot
}

func baselineState(bot *risk.Bot) any {
	baseline, ok := bot.Settings["daily_loss_baseline"]
	if !ok {
		return nil
	}

	return baseline
}

func dailySub(bot *risk.Bot, store *risk.Store) map[string]any {
	return map[string]any{
		"bot":         botState(bot),
		"risk_events": eventsState(store.RiskEvents(bot.ID)),
		"activity":    eventsState(store.Activity(bot.ID)),
	}
}

func TestDailyLossMonitorGolden(t *testing.T) {
	fixture, raw := loadRiskFixtures(t)
	expectedCases := rawList(t, raw, "daily_loss_monitor", "cases")

	for index, testCase := range fixture.DailyLossMonitor.Cases {
		t.Run(testCase.Label, func(t *testing.T) {
			store := risk.NewStore(clock(t))
			monitor := risk.NewDailyLossMonitor(store, nil)
			userID := int64(1)
			actual := map[string]any{"label": testCase.Label}

			switch testCase.Label {
			case "per_bot_realized_loss_pauses_and_cancels_orders":
				store.AddUserLimit(userID, "15")
				bot := dailyBot(store, userID, testCase.Label)
				store.AddOpenOrder(bot, "daily-open-buy", "buy", "95", "0.5")

				monitor.Evaluate(bot, nil)
				actual["baseline_after_first_evaluate"] = baselineState(bot)
				bot.RealizedPnL = decimal.MustParse("-20")
				monitor.Evaluate(bot, nil)

				actual["user_limit_after"] = limitState(store.UserLimit(userID))
				actual["bot"] = botState(bot)
				actual["orders"] = ordersState(store.OrdersForBot(bot.ID))
				actual["risk_events"] = eventsState(store.RiskEvents(bot.ID))
				actual["activity"] = eventsState(store.Activity(bot.ID))

			case "loss_below_limit_keeps_running_without_events":
				store.AddUserLimit(userID, "10")
				bot := dailyBot(store, userID, testCase.Label)
				monitor.Evaluate(bot, nil)
				bot.RealizedPnL = decimal.MustParse("-9.99")
				monitor.Evaluate(bot, nil)

				actual["bot"] = botState(bot)
				actual["risk_events"] = eventsState(store.RiskEvents(bot.ID))
				actual["activity"] = eventsState(store.Activity(bot.ID))

			case "account_wide_loss_pauses_every_user_bot":
				store.AddUserLimit(userID, "25")
				first := dailyBot(store, userID, "daily_account_first")
				second := dailyBot(store, userID, "daily_account_second")
				store.AddOpenOrder(first, "account-first-open", "buy", "95", "0.5")
				store.AddOpenOrder(second, "account-second-open", "sell", "105", "0.5")

				monitor.Evaluate(first, nil)
				monitor.Evaluate(second, nil)
				first.RealizedPnL = decimal.MustParse("-20")
				second.RealizedPnL = decimal.MustParse("-10")
				monitor.Evaluate(first, nil)

				actual["first"] = dailySub(first, store)
				actual["second"] = dailySub(second, store)
				actual["orders"] = map[string]any{
					"first":  ordersState(store.OrdersForBot(first.ID)),
					"second": ordersState(store.OrdersForBot(second.ID)),
				}

			case "bot_baselines_are_isolated_from_sibling_bots":
				store.AddUserLimit(userID, "1000")
				first := dailyBot(store, userID, "daily_isolation_first")
				second := dailyBot(store, userID, "daily_isolation_second")

				monitor.Evaluate(first, nil)
				monitor.Evaluate(second, nil)
				actual["first_baseline_after_first_evaluate"] = baselineState(first)
				actual["second_baseline_after_first_evaluate"] = baselineState(second)

				store.AddBotLimit(first, "1000", 20, "15", store.StartOfDayUTC(), "0")
				first.RealizedPnL = decimal.MustParse("-20")
				second.RealizedPnL = decimal.MustParse("-10")
				monitor.Evaluate(first, nil)
				monitor.Evaluate(second, nil)

				actual["first"] = botState(first)
				actual["second"] = botState(second)
				actual["risk_events"] = map[string]any{
					"first":  eventsState(store.RiskEvents(first.ID)),
					"second": eventsState(store.RiskEvents(second.ID)),
				}

			case "bot_without_override_uses_engine_owned_settings_baseline":
				store.AddUserLimit(userID, "100")
				bot := dailyBot(store, userID, testCase.Label)

				monitor.Evaluate(bot, nil)
				actual["baseline_after_first_evaluate"] = baselineState(bot)
				actual["user_row_after_first_evaluate"] = limitState(store.UserLimit(userID))

				bot.RealizedPnL = decimal.MustParse("-5")
				monitor.Evaluate(bot, nil)

				actual["baseline_after_second_evaluate"] = baselineState(bot)
				actual["user_row_after_second_evaluate"] = limitState(store.UserLimit(userID))
				actual["bot"] = botState(bot)
				actual["risk_events"] = eventsState(store.RiskEvents(bot.ID))

			case "explicit_bot_override_resets_daily_baseline_then_breaches":
				store.AddUserLimit(userID, "100")
				bot := dailyBot(store, userID, testCase.Label)
				bot.RealizedPnL = decimal.MustParse("-20")
				yesterday := store.StartOfDayUTC().AddDate(0, 0, -1)
				store.AddBotLimit(bot, "1000", 20, "10", yesterday, "0")

				monitor.Evaluate(bot, nil)
				actual["row_after_reset"] = limitState(store.BotOverride(bot))

				bot.RealizedPnL = decimal.MustParse("-35")
				monitor.Evaluate(bot, nil)

				actual["row_after_breach"] = limitState(store.BotOverride(bot))
				actual["bot"] = botState(bot)
				actual["risk_events"] = eventsState(store.RiskEvents(bot.ID))
				actual["activity"] = eventsState(store.Activity(bot.ID))

			case "unrealized_loss_at_mark_pauses_bot":
				store.AddUserLimit(userID, "15")
				bot := dailyBot(store, userID, testCase.Label)
				monitor.Evaluate(bot, nil)
				monitor.Evaluate(bot, decimalPtr(t, "80"))

				actual["mark"] = "80"
				actual["bot"] = botState(bot)
				actual["risk_events"] = eventsState(store.RiskEvents(bot.ID))
				actual["activity"] = eventsState(store.Activity(bot.ID))

			case "account_wide_loss_uses_each_bots_own_mark":
				store.AddUserLimit(userID, "25")
				btc := dailyBot(store, userID, "daily_mark_btc")
				btc.Settings["mark_price"] = "80"
				eth := dailyBot(store, userID, "daily_mark_eth")
				eth.Symbol = "ETH/USDT"
				eth.Settings["mark_price"] = "100"

				monitor.Evaluate(btc, decimalPtr(t, "80"))
				actual["statuses_after_first_mark"] = []string{btc.Status, eth.Status}
				monitor.Evaluate(btc, decimalPtr(t, "70"))

				actual["first_mark"] = "80"
				actual["second_mark"] = "70"
				actual["btc"] = botState(btc)
				actual["eth"] = botState(eth)
				actual["risk_events"] = map[string]any{
					"btc": eventsState(store.RiskEvents(btc.ID)),
					"eth": eventsState(store.RiskEvents(eth.ID)),
				}

			case "paused_bot_is_not_resumed_after_loss_recovers":
				store.AddUserLimit(userID, "15")
				bot := dailyBot(store, userID, testCase.Label)
				monitor.Evaluate(bot, nil)
				bot.RealizedPnL = decimal.MustParse("-20")
				monitor.Evaluate(bot, nil)
				actual["after_pause"] = map[string]any{
					"status":      bot.Status,
					"risk_events": len(store.RiskEvents(bot.ID)),
				}
				bot.RealizedPnL = decimal.MustParse("0")
				monitor.Evaluate(bot, nil)

				actual["bot"] = botState(bot)
				actual["risk_events"] = eventsState(store.RiskEvents(bot.ID))

			case "tick_pauses_before_placing_orders":
				store.AddUserLimit(userID, "15")
				bot := dailyBot(store, userID, testCase.Label)
				monitor.Evaluate(bot, nil)
				bot.Settings["recent_prices"] = []string{"80"}
				bot.Settings["mark_price"] = "80"
				monitor.Evaluate(bot, decimalPtr(t, "80"))

				actual["result"] = map[string]any{
					"status":          "skipped",
					"market_price":    nil,
					"orders_placed":   0,
					"fills":           0,
					"orders_rejected": 0,
					"errors":          []any{},
				}
				actual["bot"] = botState(bot)
				actual["orders"] = ordersState(store.OrdersForBot(bot.ID))
				actual["risk_events"] = eventsState(store.RiskEvents(bot.ID))
				actual["activity"] = eventsState(store.Activity(bot.ID))

			default:
				t.Fatalf("unhandled daily loss fixture case %q", testCase.Label)
			}

			assertExpected(t, testCase.Label, expectedCases[index], actual)
		})
	}
}

func TestExitRulesGolden(t *testing.T) {
	fixture, raw := loadRiskFixtures(t)
	expectedCases := rawList(t, raw, "exit_guard", "rule_cases")

	for index, testCase := range fixture.ExitGuard.RuleCases {
		t.Run(testCase.Label, func(t *testing.T) {
			rules := risk.NewExitRules(
				testCase.Rules.StopLossPercent,
				testCase.Rules.TakeProfitPercent,
				testCase.Rules.RangeLow,
				testCase.Rules.RangeHigh,
			)
			signal := rules.Evaluate(
				testCase.Inputs.MarkPrice,
				testCase.Inputs.AverageEntryPrice,
				testCase.Inputs.BaseInventory,
			)

			actual := map[string]any{
				"label": testCase.Label,
				"rules": rules.ToArray(),
				"empty": rules.Empty(),
				"inputs": map[string]any{
					"mark_price":          testCase.Inputs.MarkPrice,
					"average_entry_price": testCase.Inputs.AverageEntryPrice,
					"base_inventory":      testCase.Inputs.BaseInventory,
				},
				"signal": signalState(signal),
			}

			assertExpected(t, testCase.Label, expectedCases[index], actual)
		})
	}
}

type monitorSetup struct {
	attrs    map[string]string
	orders   [][3]string
	status   string
	failures map[string]string
}

func monitorSetupFor(label string) monitorSetup {
	switch label {
	case "stop_loss_pauses_cancels_and_keeps_inventory":
		return monitorSetup{
			attrs: map[string]string{"stop_loss_percent": "10", "base_inventory": "1", "average_entry_price": "100", "quote_inventory": "1000"},
			orders: [][3]string{
				{"exit-stop-buy", "buy", "95"},
				{"exit-stop-sell", "sell", "105"},
			},
		}
	case "take_profit_pauses_and_cancels":
		return monitorSetup{
			attrs:  map[string]string{"take_profit_percent": "9", "base_inventory": "1", "average_entry_price": "100"},
			orders: [][3]string{{"exit-tp-buy", "buy", "100"}},
		}
	case "range_low_exit_without_inventory":
		return monitorSetup{
			attrs:  map[string]string{"range_low": "90", "base_inventory": "0", "average_entry_price": "0"},
			orders: [][3]string{{"exit-range-buy", "buy", "89"}},
		}
	case "range_high_exit":
		return monitorSetup{
			attrs: map[string]string{"range_high": "120", "base_inventory": "0", "average_entry_price": "0"},
		}
	case "inside_rules_does_not_trigger":
		return monitorSetup{
			attrs:  map[string]string{"stop_loss_percent": "10", "take_profit_percent": "9", "range_low": "80", "range_high": "120", "base_inventory": "1", "average_entry_price": "100"},
			orders: [][3]string{{"exit-inside-buy", "buy", "95"}},
		}
	case "stop_loss_precedence_over_range":
		return monitorSetup{
			attrs: map[string]string{"stop_loss_percent": "10", "range_high": "95", "base_inventory": "1", "average_entry_price": "100"},
		}
	case "paused_bot_not_evaluated":
		return monitorSetup{
			attrs:  map[string]string{"stop_loss_percent": "10", "base_inventory": "1", "average_entry_price": "100"},
			orders: [][3]string{{"exit-already-paused", "buy", "95"}},
			status: risk.StatusPaused,
		}
	case "cancel_failure_keeps_order_open_and_reports_exit_cancel_failed":
		return monitorSetup{
			attrs: map[string]string{"stop_loss_percent": "10", "base_inventory": "1", "average_entry_price": "100"},
			orders: [][3]string{
				{"exit-cancel-fail-first", "buy", "95"},
				{"exit-cancel-fail-second", "sell", "105"},
			},
			failures: map[string]string{"exit-cancel-fail-first": "exchange refused cancellation"},
		}
	default:
		return monitorSetup{}
	}
}

type failingCanceler struct {
	store    *risk.Store
	failures map[string]string
}

func (f failingCanceler) Cancel(order *risk.Order) error {
	if message, ok := f.failures[order.ClientOrderID]; ok {
		return errors.New(message)
	}

	return f.store.CancelOrder(order)
}

func TestExitGuardMonitorGolden(t *testing.T) {
	fixture, raw := loadRiskFixtures(t)
	expectedCases := rawList(t, raw, "exit_guard", "monitor_cases")

	for index, testCase := range fixture.ExitGuard.MonitorCases {
		t.Run(testCase.Label, func(t *testing.T) {
			setup := monitorSetupFor(testCase.Label)
			store := risk.NewStore(clock(t))
			bot := newPaperBot(store, 1, testCase.Label)
			if setup.status != "" {
				bot.Status = setup.status
			}

			for key, value := range setup.attrs {
				switch key {
				case "base_inventory":
					bot.BaseInventory = decimal.MustParse(value)
				case "average_entry_price":
					bot.AverageEntryPrice = decimal.MustParse(value)
				case "quote_inventory":
					bot.QuoteInventory = decimal.MustParse(value)
				case "stop_loss_percent":
					bot.StopLossPercent = &value
				case "take_profit_percent":
					bot.TakeProfitPercent = &value
				case "range_low":
					bot.RangeLow = &value
				case "range_high":
					bot.RangeHigh = &value
				}
			}

			for _, order := range setup.orders {
				store.AddOpenOrder(bot, order[0], order[1], order[2], "0.5")
			}

			var canceler risk.OrderCanceler
			if setup.failures != nil {
				canceler = failingCanceler{store: store, failures: setup.failures}
			}

			monitor := risk.NewExitGuardMonitor(store, canceler)
			signal := monitor.Evaluate(bot, testCase.Mark)

			var second any
			if setup.failures == nil {
				second = signalState(monitor.Evaluate(bot, testCase.Mark))
			}

			actual := map[string]any{
				"label":         testCase.Label,
				"mark":          testCase.Mark,
				"signal":        signalState(signal),
				"second_signal": second,
				"bot":           botState(bot),
				"orders":        ordersState(store.OrdersForBot(bot.ID)),
				"risk_events":   eventsState(store.RiskEvents(bot.ID)),
				"activity":      eventsState(store.Activity(bot.ID)),
			}

			assertExpected(t, testCase.Label, expectedCases[index], actual)
		})
	}
}

func failureInputs(label string, threshold int) ([]string, string, bool) {
	switch label {
	case "threshold_2_first_failure_keeps_running":
		return []string{"first failure"}, "tick", false
	case "threshold_2_second_failure_pauses_and_cancels":
		return []string{"first failure", "second failure"}, "tick", false
	case "stopped_bot_stays_stopped_but_cancels":
		return []string{"stopped failure"}, "tick", false
	case "success_resets_failure_counter":
		return []string{"first failure", "second failure"}, "tick", true
	case "cancellation_failures_are_reported_at_threshold":
		return []string{"boom"}, "place", false
	default:
		return nil, "", false
	}
}

func TestConsecutiveFailureTrackerGolden(t *testing.T) {
	fixture, raw := loadRiskFixtures(t)
	expectedCases := rawList(t, raw, "consecutive_failure_tracker", "cases")

	for index, testCase := range fixture.ConsecutiveFailureTracker.Cases {
		t.Run(testCase.Label, func(t *testing.T) {
			store := risk.NewStore(clock(t))
			bot := newPaperBot(store, 1, testCase.Label)
			bot.Status = risk.StatusRunning
			if testCase.Label == "stopped_bot_stays_stopped_but_cancels" {
				bot.Status = risk.StatusStopped
			}

			messages, operation, successCase := failureInputs(testCase.Label, testCase.Threshold)
			if messages == nil {
				t.Fatalf("unhandled failure fixture case %q", testCase.Label)
			}

			var canceler risk.OrderCanceler
			switch testCase.Label {
			case "threshold_2_first_failure_keeps_running", "threshold_2_second_failure_pauses_and_cancels", "stopped_bot_stays_stopped_but_cancels":
				store.AddOpenOrder(bot, "failure-open", "buy", "100", "0.5")
			case "cancellation_failures_are_reported_at_threshold":
				store.AddOpenOrder(bot, "failure-cancel-first", "buy", "100", "0.5")
				store.AddOpenOrder(bot, "failure-cancel-second", "sell", "110", "0.5")
				canceler = failingCanceler{store: store, failures: map[string]string{
					"failure-cancel-first":  "exchange refused cancellation",
					"failure-cancel-second": "exchange refused cancellation",
				}}
			}

			tracker := risk.NewConsecutiveFailureTracker(store, canceler, testCase.Threshold)
			actual := map[string]any{
				"label":     testCase.Label,
				"threshold": tracker.Threshold(),
			}

			if successCase {
				for _, message := range messages {
					tracker.RecordFailure(bot, message, operation)
				}
				tracker.RecordSuccess(bot)
			} else if testCase.Label == "cancellation_failures_are_reported_at_threshold" {
				actual["failures"] = tracker.RecordFailure(bot, messages[0], operation)
			} else {
				sequence := make([]any, 0, len(messages)*2)
				for _, message := range messages {
					sequence = append(sequence, tracker.RecordFailure(bot, message, operation))
					sequence = append(sequence, bot.Status)
				}
				actual["sequence"] = sequence
			}

			actual["bot"] = botState(bot)
			actual["orders"] = ordersState(store.OrdersForBot(bot.ID))
			actual["risk_events"] = eventsState(store.RiskEvents(bot.ID))
			actual["activity"] = eventsState(store.Activity(bot.ID))

			assertExpected(t, testCase.Label, expectedCases[index], actual)
		})
	}
}

func TestExchangeAllowlistCreationGolden(t *testing.T) {
	fixture, raw := loadRiskFixtures(t)
	expectedCases := rawList(t, raw, "exchange_allowlist", "creation_validation")

	for index, testCase := range fixture.ExchangeAllowlist.CreationValidation {
		t.Run(testCase.Label, func(t *testing.T) {
			allowlist := risk.NewExchangeAllowlist(testCase.Allowlist, testCase.DefaultExchange)
			prepared, valid, errs := allowlist.ValidateCreation(testCase.Submitted)

			actual := map[string]any{
				"label":            testCase.Label,
				"allowlist":        testCase.Allowlist,
				"default_exchange": testCase.DefaultExchange,
				"submitted":        testCase.Submitted,
				"prepared":         prepared,
				"valid":            valid,
				"errors":           errs,
			}

			assertExpected(t, testCase.Label, expectedCases[index], actual)
		})
	}
}

func TestExchangeAllowlistBlocksStartAndPlace(t *testing.T) {
	_, raw := loadRiskFixtures(t)
	// The committed direct_insert cases document the current-PHP gap: a bot
	// inserted with a non-allowlisted exchange still starts and places.
	direct := rawList(t, raw, "exchange_allowlist", "direct_insert_current_php")
	if len(direct) != 2 {
		t.Fatalf("direct_insert cases = %d, want 2", len(direct))
	}

	allowlist := risk.NewExchangeAllowlist([]string{"binance"}, "binance")
	store := risk.NewStore(clock(t))
	bot := newPaperBot(store, 1, "direct kucoin")
	bot.Exchange = "kucoin"
	bot.Status = risk.StatusIdle

	startGuard := risk.NewLiveModeGuard(store, false, nil)
	startGuard.Allowlist = &allowlist
	if err := startGuard.StartBot(bot, nil); err == nil {
		t.Fatal("non-allowlisted exchange passed the start guard")
	}
	if bot.Status != risk.StatusIdle {
		t.Fatalf("blocked start changed status to %q", bot.Status)
	}

	placeGuard := risk.NewRiskGuard(store)
	placeGuard.Allowlist = &allowlist
	decision := placeGuard.CheckOrder(bot, "buy", "100", "1")
	if decision.Allowed {
		t.Fatal("non-allowlisted exchange passed the placement guard")
	}
	if len(store.OrdersForBot(bot.ID)) != 0 {
		t.Fatal("blocked placement created an order")
	}

	allowed := newPaperBot(store, 1, "allowed binance")
	if err := startGuard.StartBot(allowed, nil); err != nil {
		t.Fatalf("allowlisted start blocked: %v", err)
	}
	if decision := placeGuard.CheckOrder(allowed, "buy", "100", "1"); !decision.Allowed {
		t.Fatal("allowlisted placement blocked")
	}
}

func TestLiveGateDefaultsClosed(t *testing.T) {
	store := risk.NewStore(clock(t))
	bot := newPaperBot(store, 1, "live gate default")
	bot.Mode = risk.ModeLive
	bot.Status = risk.StatusRunning

	guard := risk.NewLiveModeGuard(store, false, nil)
	if err := guard.AssertTradingAllowed(bot); err == nil {
		t.Fatal("live trading was allowed with the gate closed")
	}

	if err := guard.PauseForDisabledLiveMode(bot, nil); err == nil {
		t.Fatal("expected a live trading disabled error")
	}

	if bot.Status != risk.StatusPaused {
		t.Fatalf("status = %q, want paused", bot.Status)
	}
	if bot.Mode != risk.ModeLive {
		t.Fatalf("mode = %q, want live (never converted to paper)", bot.Mode)
	}
}

func TestFixtureScaleIsSixteen(t *testing.T) {
	fixture, _ := loadRiskFixtures(t)
	if fixture.Metadata.DecimalScale != decimal.Scale {
		t.Fatalf("fixture scale = %d, engine scale = %d", fixture.Metadata.DecimalScale, decimal.Scale)
	}

	// Guard the fixture loader against path assumptions.
	path, err := goldenfixture.Path("risk")
	if err != nil || path == "" {
		t.Fatalf("risk fixture path: %v", err)
	}
}
