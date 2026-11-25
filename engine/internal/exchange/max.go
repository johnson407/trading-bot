package exchange

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/credentials"
	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

const maxProductionURL = "https://max-api.maicoin.com"

// MAX is the MAX spot REST adapter. This change proves it against fakes only;
// no MAX live or testnet credentials are used. The signing scheme follows the
// MAX private API header style (access key, timestamp, HMAC-SHA256 signature)
// and must be re-verified against MAX documentation before any real account is
// connected.
type MAX struct {
	baseURL    string
	apiKey     string
	apiSecret  string
	httpClient *http.Client
	now        func() time.Time
}

// MAXOptions controls transport. BaseURL is the existing proxy/relay setting
// used as transport only.
type MAXOptions struct {
	BaseURL    string
	HTTPClient *http.Client
	Now        func() time.Time
}

// NewMAX builds a MAX REST client. It holds credentials only in memory.
func NewMAX(credential Credential, options MAXOptions) *MAX {
	baseURL := strings.TrimRight(strings.TrimSpace(options.BaseURL), "/")
	if baseURL == "" {
		baseURL = maxProductionURL
	}

	client := options.HTTPClient
	if client == nil {
		client = &http.Client{
			Timeout:   10 * time.Second,
			Transport: &http.Transport{Proxy: http.ProxyFromEnvironment},
		}
	}

	now := options.Now
	if now == nil {
		now = time.Now
	}

	return &MAX{baseURL: baseURL, apiKey: credential.APIKey, apiSecret: credential.APISecret, httpClient: client, now: now}
}

func (m *MAX) Exchange() string { return "max" }

func (m *MAX) PlaceLimit(ctx context.Context, request PlaceRequest) (Order, error) {
	payload := map[string]any{
		"market":     maxMarket(request.Symbol),
		"side":       string(request.Side),
		"volume":     request.Quantity.String(),
		"price":      request.Price.String(),
		"ord_type":   "limit",
		"client_oid": request.ClientOrderID,
	}

	body, err := m.signed(ctx, http.MethodPost, "/api/v3/orders", url.Values{}, payload)
	if err != nil {
		return Order{}, err
	}

	return parseMAXOrder(body)
}

func (m *MAX) Cancel(ctx context.Context, symbol, exchangeOrderID, clientOrderID string) (Order, error) {
	params := url.Values{}
	params.Set("market", maxMarket(symbol))

	switch {
	case clientOrderID != "":
		params.Set("client_oid", clientOrderID)
	case exchangeOrderID != "":
		params.Set("id", exchangeOrderID)
	default:
		return Order{}, &Error{Type: ErrorRejected, Exchange: m.Exchange(), Operation: "cancelOrder", Message: "missing order identifier"}
	}

	body, err := m.signed(ctx, http.MethodDelete, "/api/v3/order", params, nil)
	if err != nil {
		return Order{}, err
	}

	return parseMAXOrder(body)
}

func (m *MAX) Order(ctx context.Context, symbol, exchangeOrderID string) (Order, error) {
	params := url.Values{}
	params.Set("market", maxMarket(symbol))
	params.Set("id", exchangeOrderID)

	body, err := m.signed(ctx, http.MethodGet, "/api/v3/order", params, nil)
	if err != nil {
		return Order{}, err
	}

	return parseMAXOrder(body)
}

func (m *MAX) OrderByClientID(ctx context.Context, symbol, clientOrderID string) (Order, error) {
	params := url.Values{}
	params.Set("market", maxMarket(symbol))
	params.Set("client_oid", clientOrderID)

	body, err := m.signed(ctx, http.MethodGet, "/api/v3/order", params, nil)
	if err != nil {
		return Order{}, err
	}

	return parseMAXOrder(body)
}

func (m *MAX) OpenOrders(ctx context.Context, symbol string) ([]Order, error) {
	params := url.Values{}
	params.Set("market", maxMarket(symbol))
	params.Set("state", "wait")

	body, err := m.signed(ctx, http.MethodGet, "/api/v3/orders", params, nil)
	if err != nil {
		return nil, err
	}

	var raw []map[string]any
	if err := decodeJSON(body, &raw); err != nil {
		return nil, m.invalidResponse("openOrders", err)
	}

	orders := make([]Order, 0, len(raw))
	for _, entry := range raw {
		order, err := orderFromMAXMap(entry)
		if err != nil {
			return nil, m.invalidResponse("openOrders", err)
		}
		orders = append(orders, order)
	}

	return orders, nil
}

func (m *MAX) Rules(ctx context.Context, symbol string) (Rules, error) {
	body, err := m.public(ctx, http.MethodGet, "/api/v3/info/markets", url.Values{})
	if err != nil {
		return Rules{}, err
	}

	var markets []struct {
		ID              string `json:"id"`
		MinAmount       string `json:"min_amount"`
		MinTotal        string `json:"min_total"`
		AmountPrecision int    `json:"amount_precision"`
		PricePrecision  int    `json:"price_precision"`
	}
	if err := json.Unmarshal(body, &markets); err != nil {
		return Rules{}, m.invalidResponse("markets", err)
	}

	wanted := maxMarket(symbol)
	for _, market := range markets {
		if market.ID != wanted {
			continue
		}

		rules := Rules{
			Symbol:          symbol,
			PricePrecision:  market.PricePrecision,
			AmountPrecision: market.AmountPrecision,
		}
		rules.MinAmount, _ = decimal.Parse(market.MinAmount)
		rules.MinNotional, _ = decimal.Parse(market.MinTotal)

		return rules, nil
	}

	return Rules{}, &Error{Type: ErrorUnsupported, Exchange: m.Exchange(), Operation: "markets", Message: "symbol rules are unavailable"}
}

func (m *MAX) Balances(ctx context.Context) ([]Balance, error) {
	body, err := m.signed(ctx, http.MethodGet, "/api/v3/wallet/accounts", url.Values{}, nil)
	if err != nil {
		return nil, err
	}

	var accounts []struct {
		Currency string `json:"currency"`
		Balance  string `json:"balance"`
		Locked   string `json:"locked"`
	}
	if err := json.Unmarshal(body, &accounts); err != nil {
		return nil, m.invalidResponse("accounts", err)
	}

	balances := make([]Balance, 0, len(accounts))
	for _, account := range accounts {
		free, freeErr := decimal.Parse(account.Balance)
		used, usedErr := decimal.Parse(account.Locked)
		if freeErr != nil || usedErr != nil {
			return nil, m.invalidResponse("accounts", fmt.Errorf("invalid balance for %s", account.Currency))
		}

		total := free.Add(used)
		if total.IsZero() {
			continue
		}

		balances = append(balances, Balance{Asset: account.Currency, Free: free, Used: used, Total: total})
	}

	return balances, nil
}

func (m *MAX) signed(ctx context.Context, method, path string, params url.Values, payload any) ([]byte, error) {
	timestamp := strconv.FormatInt(m.now().UTC().UnixMilli(), 10)
	query := params.Encode()

	bodyBytes, err := json.Marshal(payload)
	if err != nil {
		return nil, &Error{Type: ErrorUnknown, Exchange: m.Exchange(), Operation: strings.TrimPrefix(path, "/"), Message: "request could not be built"}
	}

	signaturePayload := timestamp + method + path + query
	if payload != nil {
		signaturePayload += string(bodyBytes)
	}

	mac := hmac.New(sha256.New, []byte(m.apiSecret))
	mac.Write([]byte(signaturePayload))
	signature := hex.EncodeToString(mac.Sum(nil))

	target := m.baseURL + path
	if query != "" {
		target += "?" + query
	}

	var body io.Reader
	if payload != nil {
		body = strings.NewReader(string(bodyBytes))
	}

	request, err := http.NewRequestWithContext(ctx, method, target, body)
	if err != nil {
		return nil, &Error{Type: ErrorUnknown, Exchange: m.Exchange(), Operation: strings.TrimPrefix(path, "/"), Message: "request could not be built"}
	}

	request.Header.Set("X-MAX-ACCESSKEY", m.apiKey)
	request.Header.Set("X-MAX-TS", timestamp)
	request.Header.Set("X-MAX-SIGNATURE", signature)
	request.Header.Set("Accept", "application/json")
	if payload != nil {
		request.Header.Set("Content-Type", "application/json")
	}

	return m.execute(request, strings.TrimPrefix(path, "/api/v3/"))
}

func (m *MAX) public(ctx context.Context, method, path string, params url.Values) ([]byte, error) {
	target := m.baseURL + path
	if encoded := params.Encode(); encoded != "" {
		target += "?" + encoded
	}

	request, err := http.NewRequestWithContext(ctx, method, target, nil)
	if err != nil {
		return nil, &Error{Type: ErrorUnknown, Exchange: m.Exchange(), Operation: strings.TrimPrefix(path, "/"), Message: "request could not be built"}
	}
	request.Header.Set("Accept", "application/json")

	return m.execute(request, strings.TrimPrefix(path, "/api/v3/"))
}

func (m *MAX) execute(request *http.Request, operation string) ([]byte, error) {
	response, err := m.httpClient.Do(request)
	if err != nil {
		return nil, &Error{Type: ErrorConnection, Exchange: m.Exchange(), Operation: operation, Message: credentials.SafeError(err, m.apiKey, m.apiSecret)}
	}
	defer func() { _ = response.Body.Close() }()

	body, err := io.ReadAll(io.LimitReader(response.Body, maxResponseBytes))
	if err != nil {
		return nil, &Error{Type: ErrorConnection, Exchange: m.Exchange(), Operation: operation, Message: credentials.SafeError(err, m.apiKey, m.apiSecret)}
	}

	if response.StatusCode < 400 {
		return body, nil
	}

	return nil, maxResponseError(operation, response, body, m.apiKey, m.apiSecret)
}

func maxResponseError(operation string, response *http.Response, body []byte, secrets ...string) *Error {
	var payload struct {
		Message string `json:"message"`
		Error   string `json:"error"`
	}
	_ = json.Unmarshal(body, &payload)

	message := strings.TrimSpace(payload.Message)
	if message == "" {
		message = strings.TrimSpace(payload.Error)
	}
	if message == "" {
		message = http.StatusText(response.StatusCode)
	}
	message = credentials.Redact(message, secrets...)

	exchangeError := &Error{Exchange: "max", Operation: operation, Message: message}
	if retryAfter := parseRetryAfter(response.Header.Get("Retry-After")); retryAfter > 0 {
		exchangeError.RetryAfter = retryAfter
	}

	switch {
	case response.StatusCode == http.StatusTooManyRequests:
		exchangeError.Type = ErrorRateLimit
	case response.StatusCode == http.StatusUnauthorized || response.StatusCode == http.StatusForbidden:
		exchangeError.Type = ErrorAuthentication
	case response.StatusCode == http.StatusNotFound:
		exchangeError.Type = ErrorNotFound
	case response.StatusCode >= 500:
		exchangeError.Type = ErrorConnection
	default:
		exchangeError.Type = ErrorRejected
	}

	return exchangeError
}

func (m *MAX) invalidResponse(operation string, err error) *Error {
	return &Error{Type: ErrorUnknown, Exchange: m.Exchange(), Operation: operation, Message: credentials.SafeError(err, m.apiKey, m.apiSecret)}
}

func parseMAXOrder(body []byte) (Order, error) {
	var raw map[string]any
	if err := decodeJSON(body, &raw); err != nil {
		return Order{}, err
	}

	return orderFromMAXMap(raw)
}

func orderFromMAXMap(raw map[string]any) (Order, error) {
	price, err := number(raw["price"])
	if err != nil {
		return Order{}, err
	}

	quantity, err := number(raw["volume"])
	if err != nil {
		return Order{}, err
	}

	filled, err := numberOptional(raw["executed_volume"])
	if err != nil {
		return Order{}, err
	}

	average, _ := numberOptional(raw["avg_price"])

	order := Order{
		ExchangeOrderID: stringValue(raw["id"]),
		ClientOrderID:   stringValue(raw["client_oid"]),
		Status:          maxStatus(stringValue(raw["state"])),
		Side:            Side(strings.ToLower(stringValue(raw["side"]))),
		Price:           price,
		Quantity:        quantity,
		FilledQuantity:  filled,
		AveragePrice:    average,
		FeeCurrency:     "USDT",
	}

	if fee, feeErr := numberOptional(raw["fee"]); feeErr == nil && !fee.IsZero() {
		order.Fee = &fee
	}

	if updated := int64Value(raw["updated_at"]); updated > 0 {
		if updated > 1_000_000_000_000 {
			order.UpdatedAt = time.UnixMilli(updated).UTC()
		} else {
			order.UpdatedAt = time.Unix(updated, 0).UTC()
		}
	}

	return order, nil
}

func maxStatus(state string) Status {
	switch state {
	case "done":
		return StatusFilled
	case "cancel":
		return StatusCanceled
	case "wait":
		return StatusOpen
	default:
		return StatusOpen
	}
}

func maxMarket(symbol string) string {
	return strings.ToLower(strings.ReplaceAll(strings.TrimSpace(symbol), "/", ""))
}
