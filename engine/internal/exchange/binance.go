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

const (
	binanceProductionURL = "https://api.binance.com"
	binanceTestnetURL    = "https://testnet.binance.vision"
	binanceTestnetHost   = "testnet.binance.vision"
	maxResponseBytes     = 1 << 20
)

// Binance is the Binance spot REST adapter. Credentials stay in this struct's
// unexported fields and are never logged or serialized.
type Binance struct {
	baseURL    string
	apiKey     string
	apiSecret  string
	httpClient *http.Client
	now        func() time.Time
	recvWindow time.Duration
	testnet    bool
}

// BinanceOptions controls transport and testnet selection.
type BinanceOptions struct {
	// Testnet can also be enabled by Credential.Testnet. Testnet requests use
	// only the official Binance testnet host; a custom/relay BaseURL is
	// rejected so a testnet key can never be rewritten to production.
	Testnet    bool
	BaseURL    string
	HTTPClient *http.Client
	Now        func() time.Time
	RecvWindow time.Duration
}

// NewBinance builds a Binance REST client. It fails closed when testnet mode is
// combined with a non-testnet host.
func NewBinance(credential Credential, options BinanceOptions) (*Binance, error) {
	testnet := credential.Testnet || options.Testnet

	baseURL, err := resolveBinanceBaseURL(testnet, options.BaseURL)
	if err != nil {
		return nil, err
	}

	client := options.HTTPClient
	if client == nil {
		client = &http.Client{
			Timeout: 10 * time.Second,
			Transport: &http.Transport{
				Proxy: http.ProxyFromEnvironment,
			},
		}
	}

	now := options.Now
	if now == nil {
		now = time.Now
	}

	recvWindow := options.RecvWindow
	if recvWindow <= 0 {
		recvWindow = 5 * time.Second
	}

	return &Binance{
		baseURL:    strings.TrimRight(baseURL, "/"),
		apiKey:     credential.APIKey,
		apiSecret:  credential.APISecret,
		httpClient: client,
		now:        now,
		recvWindow: recvWindow,
		testnet:    testnet,
	}, nil
}

func resolveBinanceBaseURL(testnet bool, custom string) (string, error) {
	custom = strings.TrimSpace(custom)

	if !testnet {
		if custom == "" {
			return binanceProductionURL, nil
		}

		return custom, nil
	}

	if custom == "" {
		return binanceTestnetURL, nil
	}

	parsed, err := url.Parse(custom)
	if err != nil || parsed.Host == "" {
		return "", fmt.Errorf("Binance testnet base URL must be an absolute URL")
	}

	if parsed.Scheme != "https" || !strings.EqualFold(parsed.Host, binanceTestnetHost) {
		return "", fmt.Errorf("Binance testnet must use %s; refusing %s", binanceTestnetURL, parsed.Host)
	}

	return binanceTestnetURL, nil
}

func (b *Binance) Exchange() string { return "binance" }

func (b *Binance) PlaceLimit(ctx context.Context, request PlaceRequest) (Order, error) {
	params := url.Values{}
	params.Set("symbol", binanceSymbol(request.Symbol))
	params.Set("side", strings.ToUpper(string(request.Side)))
	params.Set("type", "LIMIT")
	params.Set("timeInForce", "GTC")
	params.Set("quantity", request.Quantity.String())
	params.Set("price", request.Price.String())
	params.Set("newClientOrderId", request.ClientOrderID)

	body, err := b.signed(ctx, http.MethodPost, "/api/v3/order", params)
	if err != nil {
		return Order{}, err
	}

	return parseBinanceOrder(body)
}

func (b *Binance) Cancel(ctx context.Context, symbol, exchangeOrderID, clientOrderID string) (Order, error) {
	params := url.Values{}
	params.Set("symbol", binanceSymbol(symbol))

	switch {
	case clientOrderID != "":
		params.Set("origClientOrderId", clientOrderID)
	case exchangeOrderID != "":
		params.Set("orderId", exchangeOrderID)
	default:
		return Order{}, &Error{Type: ErrorRejected, Exchange: b.Exchange(), Operation: "cancelOrder", Message: "missing order identifier"}
	}

	body, err := b.signed(ctx, http.MethodDelete, "/api/v3/order", params)
	if err != nil {
		return Order{}, err
	}

	// Binance's cancel response is the final order snapshot and carries the
	// executed quantity, so a cancel must not discard a partial fill.
	return parseBinanceOrder(body)
}

func (b *Binance) Order(ctx context.Context, symbol, exchangeOrderID string) (Order, error) {
	params := url.Values{}
	params.Set("symbol", binanceSymbol(symbol))
	params.Set("orderId", exchangeOrderID)

	body, err := b.signed(ctx, http.MethodGet, "/api/v3/order", params)
	if err != nil {
		return Order{}, err
	}

	return parseBinanceOrder(body)
}

func (b *Binance) OrderByClientID(ctx context.Context, symbol, clientOrderID string) (Order, error) {
	params := url.Values{}
	params.Set("symbol", binanceSymbol(symbol))
	params.Set("origClientOrderId", clientOrderID)

	body, err := b.signed(ctx, http.MethodGet, "/api/v3/order", params)
	if err != nil {
		return Order{}, err
	}

	return parseBinanceOrder(body)
}

func (b *Binance) OpenOrders(ctx context.Context, symbol string) ([]Order, error) {
	params := url.Values{}
	params.Set("symbol", binanceSymbol(symbol))

	body, err := b.signed(ctx, http.MethodGet, "/api/v3/openOrders", params)
	if err != nil {
		return nil, err
	}

	var raw []map[string]any
	if err := decodeJSON(body, &raw); err != nil {
		return nil, b.invalidResponse("openOrders", err)
	}

	orders := make([]Order, 0, len(raw))
	for _, entry := range raw {
		order, err := orderFromMap(entry)
		if err != nil {
			return nil, b.invalidResponse("openOrders", err)
		}
		orders = append(orders, order)
	}

	return orders, nil
}

func (b *Binance) Rules(ctx context.Context, symbol string) (Rules, error) {
	params := url.Values{}
	params.Set("symbol", binanceSymbol(symbol))

	body, err := b.public(ctx, http.MethodGet, "/api/v3/exchangeInfo", params)
	if err != nil {
		return Rules{}, err
	}

	var response struct {
		Symbols []struct {
			Symbol  string `json:"symbol"`
			Filters []struct {
				Type        string `json:"filterType"`
				TickSize    string `json:"tickSize"`
				StepSize    string `json:"stepSize"`
				MinQty      string `json:"minQty"`
				MinNotional string `json:"minNotional"`
			} `json:"filters"`
		} `json:"symbols"`
	}
	if err := json.Unmarshal(body, &response); err != nil {
		return Rules{}, b.invalidResponse("exchangeInfo", err)
	}

	wanted := binanceSymbol(symbol)
	for _, entry := range response.Symbols {
		if entry.Symbol != wanted {
			continue
		}

		rules := Rules{Symbol: symbol, PricePrecision: 8, AmountPrecision: 8}
		for _, filter := range entry.Filters {
			switch filter.Type {
			case "PRICE_FILTER":
				rules.PricePrecision = decimalPlaces(filter.TickSize)
				if parsed, parseErr := decimal.Parse(filter.TickSize); parseErr == nil {
					rules.PriceStep = parsed
				}
			case "LOT_SIZE":
				rules.AmountPrecision = decimalPlaces(filter.StepSize)
				if parsed, parseErr := decimal.Parse(filter.StepSize); parseErr == nil {
					rules.AmountStep = parsed
				}
				if filter.MinQty != "" {
					if parsed, parseErr := decimal.Parse(filter.MinQty); parseErr == nil {
						rules.MinAmount = parsed
					}
				}
			case "MIN_NOTIONAL", "NOTIONAL":
				if filter.MinNotional != "" {
					if parsed, parseErr := decimal.Parse(filter.MinNotional); parseErr == nil {
						rules.MinNotional = parsed
					}
				}
			}
		}

		return rules, nil
	}

	return Rules{}, &Error{Type: ErrorUnsupported, Exchange: b.Exchange(), Operation: "exchangeInfo", Message: "symbol rules are unavailable"}
}

func (b *Binance) Balances(ctx context.Context) ([]Balance, error) {
	body, err := b.signed(ctx, http.MethodGet, "/api/v3/account", url.Values{})
	if err != nil {
		return nil, err
	}

	var response struct {
		Balances []struct {
			Asset  string `json:"asset"`
			Free   string `json:"free"`
			Locked string `json:"locked"`
		} `json:"balances"`
	}
	if err := json.Unmarshal(body, &response); err != nil {
		return nil, b.invalidResponse("account", err)
	}

	balances := make([]Balance, 0, len(response.Balances))
	for _, entry := range response.Balances {
		free, freeErr := decimal.Parse(entry.Free)
		used, usedErr := decimal.Parse(entry.Locked)
		if freeErr != nil || usedErr != nil {
			return nil, b.invalidResponse("account", fmt.Errorf("invalid balance for %s", entry.Asset))
		}

		total := free.Add(used)
		if total.IsZero() {
			continue
		}

		balances = append(balances, Balance{Asset: entry.Asset, Free: free, Used: used, Total: total})
	}

	return balances, nil
}

func (b *Binance) signed(ctx context.Context, method, path string, params url.Values) ([]byte, error) {
	params.Set("timestamp", strconv.FormatInt(b.now().UTC().UnixMilli(), 10))
	params.Set("recvWindow", strconv.FormatInt(b.recvWindow.Milliseconds(), 10))

	query := params.Encode()
	mac := hmac.New(sha256.New, []byte(b.apiSecret))
	mac.Write([]byte(query))
	query += "&signature=" + hex.EncodeToString(mac.Sum(nil))

	request, err := http.NewRequestWithContext(ctx, method, b.baseURL+path+"?"+query, nil)
	if err != nil {
		return nil, &Error{Type: ErrorUnknown, Exchange: b.Exchange(), Operation: strings.ToLower(path), Message: "request could not be built"}
	}

	request.Header.Set("X-MBX-APIKEY", b.apiKey)
	request.Header.Set("Accept", "application/json")

	return b.execute(request, strings.TrimPrefix(path, "/api/v3/"))
}

func (b *Binance) public(ctx context.Context, method, path string, params url.Values) ([]byte, error) {
	target := b.baseURL + path
	if encoded := params.Encode(); encoded != "" {
		target += "?" + encoded
	}

	request, err := http.NewRequestWithContext(ctx, method, target, nil)
	if err != nil {
		return nil, &Error{Type: ErrorUnknown, Exchange: b.Exchange(), Operation: strings.ToLower(path), Message: "request could not be built"}
	}

	request.Header.Set("Accept", "application/json")

	return b.execute(request, strings.TrimPrefix(path, "/api/v3/"))
}

func (b *Binance) execute(request *http.Request, operation string) ([]byte, error) {
	response, err := b.httpClient.Do(request)
	if err != nil {
		return nil, &Error{
			Type:      ErrorConnection,
			Exchange:  b.Exchange(),
			Operation: operation,
			Message:   credentials.SafeError(err, b.apiKey, b.apiSecret),
		}
	}
	defer func() { _ = response.Body.Close() }()

	body, err := io.ReadAll(io.LimitReader(response.Body, maxResponseBytes))
	if err != nil {
		return nil, &Error{
			Type:      ErrorConnection,
			Exchange:  b.Exchange(),
			Operation: operation,
			Message:   credentials.SafeError(err, b.apiKey, b.apiSecret),
		}
	}

	if response.StatusCode < 400 {
		return body, nil
	}

	return nil, binanceResponseError(operation, response, body, b.apiKey, b.apiSecret)
}

func (b *Binance) invalidResponse(operation string, err error) *Error {
	return &Error{
		Type:      ErrorUnknown,
		Exchange:  b.Exchange(),
		Operation: operation,
		Message:   credentials.SafeError(err, b.apiKey, b.apiSecret),
	}
}

func binanceResponseError(operation string, response *http.Response, body []byte, secrets ...string) *Error {
	var payload struct {
		Code int    `json:"code"`
		Msg  string `json:"msg"`
	}
	_ = json.Unmarshal(body, &payload)

	message := strings.TrimSpace(payload.Msg)
	if message == "" {
		message = http.StatusText(response.StatusCode)
	}
	message = credentials.Redact(message, secrets...)

	exchangeError := &Error{
		Exchange:  "binance",
		Operation: operation,
		Message:   message,
	}

	if retryAfter := parseRetryAfter(response.Header.Get("Retry-After")); retryAfter > 0 {
		exchangeError.RetryAfter = retryAfter
	}

	switch {
	case response.StatusCode == http.StatusTooManyRequests || response.StatusCode == 418:
		exchangeError.Type = ErrorRateLimit
	case response.StatusCode == http.StatusUnauthorized || response.StatusCode == http.StatusForbidden:
		exchangeError.Type = ErrorAuthentication
	case response.StatusCode == http.StatusNotFound:
		exchangeError.Type = ErrorNotFound
	case payload.Code == -1003:
		exchangeError.Type = ErrorRateLimit
	case payload.Code == -2011 || payload.Code == -2013:
		exchangeError.Type = ErrorNotFound
	case payload.Code == -2010 && strings.Contains(strings.ToLower(message), "insufficient"):
		exchangeError.Type = ErrorInsufficientFunds
	case payload.Code == -2010 || (response.StatusCode >= 400 && response.StatusCode < 500):
		exchangeError.Type = ErrorRejected
	case response.StatusCode >= 500:
		exchangeError.Type = ErrorConnection
	default:
		exchangeError.Type = ErrorUnknown
	}

	return exchangeError
}

func parseBinanceOrder(body []byte) (Order, error) {
	var raw map[string]any
	if err := decodeJSON(body, &raw); err != nil {
		return Order{}, err
	}

	return orderFromMap(raw)
}

func orderFromMap(raw map[string]any) (Order, error) {
	price, err := number(raw["price"])
	if err != nil {
		return Order{}, err
	}

	quantity, err := number(raw["origQty"])
	if err != nil {
		return Order{}, err
	}

	filled, err := numberOptional(raw["executedQty"])
	if err != nil {
		return Order{}, err
	}

	average, _ := numberOptional(raw["avgPrice"])
	if average.IsZero() && filled.IsPositive() {
		if quote, quoteErr := numberOptional(raw["cummulativeQuoteQty"]); quoteErr == nil && quote.IsPositive() {
			if computed, divErr := quote.Div(filled); divErr == nil {
				average = computed
			}
		}
	}

	order := Order{
		ExchangeOrderID: stringValue(raw["orderId"]),
		ClientOrderID:   stringValue(raw["clientOrderId"]),
		Status:          binanceStatus(stringValue(raw["status"])),
		Side:            Side(strings.ToLower(stringValue(raw["side"]))),
		Price:           price,
		Quantity:        quantity,
		FilledQuantity:  filled,
		AveragePrice:    average,
		FeeCurrency:     "USDT",
	}

	if fills, ok := raw["fills"].([]any); ok && len(fills) > 0 {
		if entry, ok := fills[0].(map[string]any); ok {
			if fee, feeErr := numberOptional(entry["commission"]); feeErr == nil {
				order.Fee = &fee
			}
			if asset := stringValue(entry["commissionAsset"]); asset != "" {
				order.FeeCurrency = asset
			}
		}
	}

	if updated := int64Value(raw["updateTime"]); updated > 0 {
		order.UpdatedAt = time.UnixMilli(updated).UTC()
	} else if transacted := int64Value(raw["transactTime"]); transacted > 0 {
		order.UpdatedAt = time.UnixMilli(transacted).UTC()
	}

	return order, nil
}

func binanceStatus(status string) Status {
	switch status {
	case "FILLED":
		return StatusFilled
	case "PARTIALLY_FILLED":
		return StatusPartiallyFilled
	case "CANCELED", "EXPIRED", "EXPIRED_IN_MATCH":
		return StatusCanceled
	case "REJECTED":
		return StatusRejected
	default:
		return StatusOpen
	}
}

func binanceSymbol(symbol string) string {
	return strings.ToUpper(strings.ReplaceAll(strings.TrimSpace(symbol), "/", ""))
}

func decodeJSON(body []byte, target any) error {
	decoder := json.NewDecoder(strings.NewReader(string(body)))
	decoder.UseNumber()

	return decoder.Decode(target)
}

func number(value any) (decimal.Decimal, error) {
	if value == nil {
		return decimal.Zero(), nil
	}

	switch typed := value.(type) {
	case json.Number:
		return decimal.Parse(typed.String())
	case string:
		if typed == "" {
			return decimal.Zero(), nil
		}

		return decimal.Parse(typed)
	default:
		return decimal.Of(typed)
	}
}

func numberOptional(value any) (decimal.Decimal, error) {
	if value == nil {
		return decimal.Zero(), nil
	}

	return number(value)
}

func stringValue(value any) string {
	switch typed := value.(type) {
	case string:
		return typed
	case json.Number:
		return typed.String()
	case nil:
		return ""
	default:
		return fmt.Sprint(typed)
	}
}

func int64Value(value any) int64 {
	switch typed := value.(type) {
	case json.Number:
		parsed, _ := typed.Int64()

		return parsed
	case string:
		parsed, _ := strconv.ParseInt(typed, 10, 64)

		return parsed
	case float64:
		return int64(typed)
	case int64:
		return typed
	default:
		return 0
	}
}

func decimalPlaces(value string) int {
	value = strings.TrimSpace(value)
	index := strings.Index(value, ".")
	if index < 0 {
		return 0
	}

	fraction := strings.TrimRight(value[index+1:], "0")

	return len(fraction)
}

func parseRetryAfter(value string) time.Duration {
	value = strings.TrimSpace(value)
	if value == "" {
		return 0
	}

	if seconds, err := strconv.Atoi(value); err == nil && seconds > 0 {
		return time.Duration(seconds) * time.Second
	}

	if when, err := http.ParseTime(value); err == nil {
		if delay := time.Until(when); delay > 0 {
			return delay
		}
	}

	return 0
}
