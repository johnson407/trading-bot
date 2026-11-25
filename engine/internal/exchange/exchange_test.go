package exchange

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/decimal"
)

func binanceTestCredential() Credential {
	return Credential{APIKey: "test-key", APISecret: "test-secret"}
}

func TestBinanceBaseURLSelectionFailsClosedForTestnet(t *testing.T) {
	production, err := resolveBinanceBaseURL(false, "")
	if err != nil || production != binanceProductionURL {
		t.Fatalf("production = %q, %v", production, err)
	}

	relay, err := resolveBinanceBaseURL(false, "http://relay.internal:8080")
	if err != nil || relay != "http://relay.internal:8080" {
		t.Fatalf("relay = %q, %v", relay, err)
	}

	testnet, err := resolveBinanceBaseURL(true, "")
	if err != nil || testnet != binanceTestnetURL {
		t.Fatalf("testnet = %q, %v", testnet, err)
	}

	testnet, err = resolveBinanceBaseURL(true, "https://testnet.binance.vision")
	if err != nil || testnet != binanceTestnetURL {
		t.Fatalf("official testnet = %q, %v", testnet, err)
	}

	if _, err := resolveBinanceBaseURL(true, "https://api.binance.com"); err == nil {
		t.Fatal("testnet with the production host was accepted")
	}

	if _, err := resolveBinanceBaseURL(true, "https://relay.internal"); err == nil {
		t.Fatal("testnet with a relay host was accepted")
	}
}

func TestFactoryHonorsSandboxAndRejectsMAXSandbox(t *testing.T) {
	client, err := (Factory{Sandbox: true}).Client("binance", binanceTestCredential())
	if err != nil {
		t.Fatalf("binance sandbox: %v", err)
	}
	if binance, ok := client.(*Binance); !ok || binance.baseURL != binanceTestnetURL {
		t.Errorf("client = %#v, want the Binance testnet URL", client)
	}

	if _, err := (Factory{Sandbox: true}).Client("max", Credential{}); err == nil {
		t.Error("MAX sandbox mode was accepted without a testnet proof")
	}

	if _, err := (Factory{}).Client("max", Credential{}); err == nil {
		t.Error("MAX live was accepted without the explicit unverified-adapter opt-in")
	}

	if _, err := (Factory{AllowUnverifiedMAX: true}).Client("max", Credential{Testnet: true}); err == nil {
		t.Error("MAX accepted a testnet-marked credential without a testnet endpoint")
	}

	if _, err := (Factory{AllowUnverifiedMAX: true}).Client("max", Credential{}); err != nil {
		t.Errorf("explicitly allowed MAX fake client failed: %v", err)
	}

	credential := binanceTestCredential()
	credential.Testnet = true
	client, err = (Factory{}).Client("binance", credential)
	if err != nil {
		t.Fatalf("credential testnet: %v", err)
	}
	if binance, ok := client.(*Binance); !ok || binance.baseURL != binanceTestnetURL {
		t.Errorf("credential testnet did not select the testnet URL")
	}
}

func TestBinancePlacesSignedLimitOrder(t *testing.T) {
	var seen atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seen.Add(1)
		if r.Method != http.MethodPost || r.URL.Path != "/api/v3/order" {
			t.Errorf("request = %s %s", r.Method, r.URL.Path)
		}
		if r.Header.Get("X-MBX-APIKEY") != "test-key" {
			t.Error("missing API key header")
		}

		query := r.URL.Query()
		if query.Get("symbol") != "BTCUSDT" || query.Get("side") != "BUY" || query.Get("type") != "LIMIT" {
			t.Errorf("query = %v", query)
		}
		if query.Get("newClientOrderId") != "bot-1-level-2" || query.Get("signature") == "" {
			t.Errorf("client id or signature missing: %v", query)
		}

		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"orderId":42,"clientOrderId":"bot-1-level-2","status":"PARTIALLY_FILLED","side":"BUY","price":"100.50","origQty":"0.01","executedQty":"0.004","avgPrice":"100.40","fills":[{"commission":"0.001","commissionAsset":"BNB"}],"updateTime":1700000000000}`))
	}))
	t.Cleanup(server.Close)

	client, err := NewBinance(binanceTestCredential(), BinanceOptions{BaseURL: server.URL, HTTPClient: server.Client()})
	if err != nil {
		t.Fatalf("NewBinance: %v", err)
	}

	order, err := client.PlaceLimit(context.Background(), PlaceRequest{
		Symbol:        "BTC/USDT",
		Side:          SideBuy,
		Price:         decimal.MustParse("100.50"),
		Quantity:      decimal.MustParse("0.01"),
		ClientOrderID: "bot-1-level-2",
	})
	if err != nil {
		t.Fatalf("PlaceLimit: %v", err)
	}

	if seen.Load() != 1 {
		t.Errorf("requests = %d, want 1", seen.Load())
	}
	if order.ExchangeOrderID != "42" || order.ClientOrderID != "bot-1-level-2" || order.Status != StatusPartiallyFilled {
		t.Errorf("order = %+v", order)
	}
	if order.FilledQuantity.String() != "0.004" || order.AveragePrice.String() != "100.4" {
		t.Errorf("fill = %s @ %s", order.FilledQuantity.String(), order.AveragePrice.String())
	}
	if order.Fee == nil || order.FeeCurrency != "BNB" {
		t.Errorf("fee = %+v %s", order.Fee, order.FeeCurrency)
	}
}

func TestBinanceNormalizesErrorsAndRedactsSecrets(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Retry-After", "2")
		w.WriteHeader(http.StatusTooManyRequests)
		_, _ = w.Write([]byte(`{"code":-1003,"msg":"too many requests for test-secret"}`))
	}))
	t.Cleanup(server.Close)

	client, err := NewBinance(binanceTestCredential(), BinanceOptions{BaseURL: server.URL, HTTPClient: server.Client()})
	if err != nil {
		t.Fatalf("NewBinance: %v", err)
	}

	_, err = client.OrderByClientID(context.Background(), "BTC/USDT", "client-1")
	var exchangeError *Error
	if !errors.As(err, &exchangeError) {
		t.Fatalf("error = %T %v, want *Error", err, err)
	}
	if exchangeError.Type != ErrorRateLimit || exchangeError.RetryAfter != 2*time.Second {
		t.Errorf("error = %+v", exchangeError)
	}
	if strings.Contains(err.Error(), "test-secret") {
		t.Errorf("error leaked a secret: %v", err)
	}
}

func TestBinanceInsufficientBalanceAndAuthMapping(t *testing.T) {
	cases := []struct {
		name   string
		status int
		body   string
		want   ErrorType
	}{
		{name: "insufficient", status: http.StatusBadRequest, body: `{"code":-2010,"msg":"Account has insufficient balance."}`, want: ErrorInsufficientFunds},
		{name: "rejected", status: http.StatusBadRequest, body: `{"code":-1013,"msg":"Filter failure."}`, want: ErrorRejected},
		{name: "auth", status: http.StatusUnauthorized, body: `{"code":-2015,"msg":"Invalid API-key."}`, want: ErrorAuthentication},
		{name: "not found", status: http.StatusBadRequest, body: `{"code":-2011,"msg":"Unknown order."}`, want: ErrorNotFound},
	}

	for _, test := range cases {
		t.Run(test.name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(test.status)
				_, _ = w.Write([]byte(test.body))
			}))
			t.Cleanup(server.Close)

			client, err := NewBinance(binanceTestCredential(), BinanceOptions{BaseURL: server.URL, HTTPClient: server.Client()})
			if err != nil {
				t.Fatalf("NewBinance: %v", err)
			}

			_, err = client.Order(context.Background(), "BTC/USDT", "1")
			var exchangeError *Error
			if !errors.As(err, &exchangeError) || exchangeError.Type != test.want {
				t.Errorf("error = %v, want %s", err, test.want)
			}
		})
	}
}

func TestBinanceTestnetUsesOfficialHostEvenWithRewritingTransport(t *testing.T) {
	var host atomic.Value

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		host.Store(r.Host)
		_, _ = w.Write([]byte(`{"symbols":[]}`))
	}))
	t.Cleanup(server.Close)

	transport := &rewriteTransport{target: server.URL}
	client, err := NewBinance(Credential{APIKey: "k", APISecret: "s", Testnet: true}, BinanceOptions{
		HTTPClient: &http.Client{Transport: transport},
	})
	if err != nil {
		t.Fatalf("NewBinance: %v", err)
	}

	if client.baseURL != binanceTestnetURL {
		t.Fatalf("baseURL = %q, want %q", client.baseURL, binanceTestnetURL)
	}

	_, err = client.Rules(context.Background(), "BTC/USDT")
	if err == nil {
		t.Fatal("Rules() error = nil, want unavailable")
	}
	if host.Load() != "testnet.binance.vision" {
		t.Errorf("host = %v, want testnet.binance.vision", host.Load())
	}
}

type rewriteTransport struct {
	target string
}

func (t *rewriteTransport) RoundTrip(request *http.Request) (*http.Response, error) {
	target := t.target
	rewritten, err := http.NewRequest(request.Method, target+request.URL.Path+"?"+request.URL.RawQuery, request.Body)
	if err != nil {
		return nil, err
	}
	rewritten.Header = request.Header.Clone()
	rewritten.Host = request.URL.Host

	return http.DefaultTransport.RoundTrip(rewritten)
}

func TestGuardedClientNeverCallsTheExchangeWhenClosed(t *testing.T) {
	fake := &countingClient{}
	guarded := GuardedClient{Enabled: false, Client: fake}

	_, err := guarded.PlaceLimit(context.Background(), PlaceRequest{Symbol: "BTC/USDT"})
	if !errors.Is(err, ErrLiveDisabled) {
		t.Fatalf("error = %v, want ErrLiveDisabled", err)
	}
	if fake.calls.Load() != 0 {
		t.Errorf("calls = %d, want 0", fake.calls.Load())
	}

	// Cancellation stays available when the gate closes so a pause can remove
	// resting live orders. It is not a placement path.
	if _, err := guarded.Cancel(context.Background(), "BTC/USDT", "", "client-1"); err != nil {
		t.Fatalf("Cancel: %v", err)
	}
	if fake.calls.Load() != 1 {
		t.Errorf("cancel calls = %d, want 1", fake.calls.Load())
	}
}

type countingClient struct {
	calls atomic.Int32
}

func (c *countingClient) Exchange() string { return "fake" }

func (c *countingClient) PlaceLimit(context.Context, PlaceRequest) (Order, error) {
	c.calls.Add(1)

	return Order{}, nil
}

func (c *countingClient) Cancel(context.Context, string, string, string) (Order, error) {
	c.calls.Add(1)

	return Order{}, nil
}

func (c *countingClient) Order(context.Context, string, string) (Order, error) {
	c.calls.Add(1)

	return Order{}, nil
}

func (c *countingClient) OrderByClientID(context.Context, string, string) (Order, error) {
	c.calls.Add(1)

	return Order{}, nil
}

func (c *countingClient) OpenOrders(context.Context, string) ([]Order, error) {
	c.calls.Add(1)

	return nil, nil
}

func (c *countingClient) Rules(context.Context, string) (Rules, error) {
	c.calls.Add(1)

	return Rules{}, nil
}

func (c *countingClient) Balances(context.Context) ([]Balance, error) {
	c.calls.Add(1)

	return nil, nil
}

func TestBackoffWaitsBeforeRetryingRateLimits(t *testing.T) {
	var attempts atomic.Int32
	var sleeps []time.Duration

	backoff := Backoff{
		Base:     10 * time.Millisecond,
		Max:      time.Second,
		Attempts: 3,
		Sleep: func(_ context.Context, duration time.Duration) error {
			sleeps = append(sleeps, duration)

			return nil
		},
	}

	err := backoff.Do(context.Background(), func() error {
		if attempts.Add(1) == 1 {
			return &Error{Type: ErrorRateLimit, RetryAfter: 20 * time.Millisecond}
		}

		return nil
	})
	if err != nil {
		t.Fatalf("Do: %v", err)
	}
	if attempts.Load() != 2 {
		t.Errorf("attempts = %d, want 2", attempts.Load())
	}
	if len(sleeps) != 1 || sleeps[0] < 20*time.Millisecond {
		t.Errorf("sleeps = %v, want at least the Retry-After", sleeps)
	}
}

func TestDecryptCredentialErrorsDoNotLeakTheKey(t *testing.T) {
	key := "base64:" + base64.StdEncoding.EncodeToString([]byte("01234567890123456789012345678901"))

	if _, err := DecryptCredential("not-a-payload", "not-a-payload", "", key); err == nil {
		t.Fatal("DecryptCredential() error = nil")
	} else if strings.Contains(err.Error(), key) || strings.Contains(err.Error(), "01234567890123456789012345678901") {
		t.Errorf("error leaked the key: %v", err)
	}
}

func TestMAXAdapterUsesFakeHTTPForPlaceAndCancel(t *testing.T) {
	var paths []string
	var hasSignature bool

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.Method+" "+r.URL.Path)
		hasSignature = hasSignature || r.Header.Get("X-MAX-SIGNATURE") != ""

		if r.Method == http.MethodPost {
			var body map[string]any
			if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
				t.Errorf("decode body: %v", err)
			}
			if body["client_oid"] != "max-client-1" || body["market"] != "btcusdt" {
				t.Errorf("body = %v", body)
			}
			_, _ = w.Write([]byte(`{"id":9,"client_oid":"max-client-1","state":"wait","side":"buy","price":"100","volume":"0.01","executed_volume":"0"}`))

			return
		}

		w.Write([]byte(`{"id":9,"client_oid":"max-client-1","state":"cancel","side":"buy","price":"100","volume":"0.01","executed_volume":"0"}`))
	}))
	t.Cleanup(server.Close)

	client := NewMAX(Credential{APIKey: "k", APISecret: "s"}, MAXOptions{BaseURL: server.URL, HTTPClient: server.Client()})

	order, err := client.PlaceLimit(context.Background(), PlaceRequest{
		Symbol:        "BTC/USDT",
		Side:          SideBuy,
		Price:         decimal.MustParse("100"),
		Quantity:      decimal.MustParse("0.01"),
		ClientOrderID: "max-client-1",
	})
	if err != nil {
		t.Fatalf("PlaceLimit: %v", err)
	}
	if order.ExchangeOrderID != "9" || order.Status != StatusOpen {
		t.Errorf("order = %+v", order)
	}

	if _, err := client.Cancel(context.Background(), "BTC/USDT", "", "max-client-1"); err != nil {
		t.Fatalf("Cancel: %v", err)
	}
	if !hasSignature {
		t.Error("private requests were not signed")
	}
	if len(paths) != 2 {
		t.Errorf("paths = %v", paths)
	}
}
