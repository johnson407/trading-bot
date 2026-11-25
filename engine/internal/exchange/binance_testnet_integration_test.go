//go:build binance_testnet

package exchange

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

// TestBinanceTestnetPlaceCancelReconcile is the opt-in private proof. It is
// skipped unless BINANCE_TESTNET=1 and testnet keys are present. It refuses a
// relay/custom base URL so a request cannot be rewritten to production, and it
// hard-fails before any private call when the guards are not satisfied.
func TestBinanceTestnetPlaceCancelReconcile(t *testing.T) {
	if os.Getenv("BINANCE_TESTNET") != "1" {
		t.Skip("set BINANCE_TESTNET=1 and testnet keys to run the private Binance testnet proof")
	}

	if custom := strings.TrimSpace(os.Getenv("ENGINE_EXCHANGE_BASE_URL")); custom != "" {
		t.Fatalf("refusing to run the testnet proof with ENGINE_EXCHANGE_BASE_URL=%s; a relay must not rewrite testnet requests", custom)
	}

	apiKey := strings.TrimSpace(os.Getenv("BINANCE_TESTNET_API_KEY"))
	apiSecret := strings.TrimSpace(os.Getenv("BINANCE_TESTNET_API_SECRET"))
	if apiKey == "" || apiSecret == "" {
		t.Fatal("BINANCE_TESTNET=1 requires BINANCE_TESTNET_API_KEY and BINANCE_TESTNET_API_SECRET")
	}

	client, err := NewBinance(Credential{APIKey: apiKey, APISecret: apiSecret, Testnet: true}, BinanceOptions{Testnet: true})
	if err != nil {
		t.Fatalf("testnet client: %v", err)
	}
	if client.baseURL != binanceTestnetURL {
		t.Fatalf("base URL = %q, want the official testnet URL", client.baseURL)
	}

	symbol := os.Getenv("BINANCE_TESTNET_SYMBOL")
	if symbol == "" {
		symbol = "BTC/USDT"
	}

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	rules, err := client.Rules(ctx, symbol)
	if err != nil {
		t.Fatalf("testnet rules: %v", err)
	}

	price := decimal.MustParse("1")
	if ticker, tickerErr := publicTestnetPrice(ctx, symbol); tickerErr == nil && ticker.IsPositive() {
		if half, divErr := ticker.Div(decimal.FromInt(2)); divErr == nil {
			price = half
		}
	}
	price = truncateDecimal(price, rules.PricePrecision)

	quantity := rules.MinAmount
	if !quantity.IsPositive() {
		quantity = decimal.MustParse("0.00001")
	}
	if rules.MinNotional.IsPositive() {
		if needed, divErr := rules.MinNotional.Div(price); divErr == nil && needed.GreaterThan(quantity) {
			quantity = needed
		}
	}
	quantity = truncateDecimal(quantity, rules.AmountPrecision)

	clientOrderID := fmt.Sprintf("engine-testnet-%d", time.Now().UnixNano())
	order, err := client.PlaceLimit(ctx, PlaceRequest{
		Symbol: symbol, Side: SideBuy, Price: price, Quantity: quantity, ClientOrderID: clientOrderID,
	})
	if err != nil {
		t.Fatalf("testnet place: %v", err)
	}

	t.Cleanup(func() {
		cleanupCtx, cleanupCancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cleanupCancel()
		_ = client.Cancel(cleanupCtx, symbol, order.ExchangeOrderID, clientOrderID)
	})

	lookedUp, err := client.OrderByClientID(ctx, symbol, clientOrderID)
	if err != nil {
		t.Fatalf("testnet lookup: %v", err)
	}
	if lookedUp.ClientOrderID != clientOrderID {
		t.Fatalf("lookup client id = %q, want %q", lookedUp.ClientOrderID, clientOrderID)
	}

	if _, err := client.OpenOrders(ctx, symbol); err != nil {
		t.Fatalf("testnet reconcile open orders: %v", err)
	}

	if err := client.Cancel(ctx, symbol, order.ExchangeOrderID, clientOrderID); err != nil {
		t.Fatalf("testnet cancel: %v", err)
	}

	cancelled, err := client.OrderByClientID(ctx, symbol, clientOrderID)
	if err != nil {
		t.Fatalf("testnet lookup after cancel: %v", err)
	}
	if cancelled.Status != StatusCanceled && cancelled.Status != StatusFilled {
		t.Fatalf("status after cancel = %s, want canceled (or filled if the market crossed)", cancelled.Status)
	}
}

func publicTestnetPrice(ctx context.Context, symbol string) (decimal.Decimal, error) {
	target := binanceTestnetURL + "/api/v3/ticker/price?symbol=" + binanceSymbol(symbol)
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, target, nil)
	if err != nil {
		return decimal.Zero(), err
	}

	response, err := http.DefaultClient.Do(request)
	if err != nil {
		return decimal.Zero(), err
	}
	defer func() { _ = response.Body.Close() }()

	body, err := io.ReadAll(io.LimitReader(response.Body, 4096))
	if err != nil {
		return decimal.Zero(), err
	}

	var payload struct {
		Price string `json:"price"`
	}
	if err := json.Unmarshal(body, &payload); err != nil {
		return decimal.Zero(), err
	}

	return decimal.Parse(payload.Price)
}

func truncateDecimal(value decimal.Decimal, precision int) decimal.Decimal {
	if precision < 0 {
		precision = 0
	}
	rounded, err := value.Round(precision, decimal.RoundDown)
	if err != nil {
		return value
	}

	return rounded
}
