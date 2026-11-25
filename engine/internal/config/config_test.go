package config_test

import (
	"net/url"
	"testing"
	"time"

	"github.com/johnson407/trading-bot/engine/internal/config"
)

func load(env map[string]string) (config.Config, error) {
	return config.Load(func(key string) string {
		return env[key]
	})
}

func TestLoadDefaults(t *testing.T) {
	t.Parallel()

	cfg, err := load(map[string]string{})
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}

	if cfg.Addr != config.DefaultListenAddr {
		t.Errorf("Addr = %q, want %q", cfg.Addr, config.DefaultListenAddr)
	}

	if cfg.Database.Host != "127.0.0.1" || cfg.Database.Port != 5432 {
		t.Errorf("Database = %+v, want localhost:5432", cfg.Database)
	}

	if cfg.Redis.Host != "127.0.0.1" || cfg.Redis.Port != 6379 {
		t.Errorf("Redis = %+v, want localhost:6379", cfg.Redis)
	}

	if cfg.LiveTrading {
		t.Error("LiveTrading = true, want false by default")
	}

	if len(cfg.TradingExchanges) != 1 || cfg.TradingExchanges[0] != "binance" {
		t.Errorf("TradingExchanges = %v, want [binance]", cfg.TradingExchanges)
	}

	if cfg.TradingDefaultExchange != "binance" {
		t.Errorf("TradingDefaultExchange = %q, want binance", cfg.TradingDefaultExchange)
	}

	if cfg.TradingTickInterval != 60*time.Second {
		t.Errorf("TradingTickInterval = %s, want 60s", cfg.TradingTickInterval)
	}

	if cfg.TradingOrderSync != 5*time.Second {
		t.Errorf("TradingOrderSync = %s, want 5s", cfg.TradingOrderSync)
	}

	if cfg.TradingExchangeSandbox {
		t.Error("TradingExchangeSandbox = true, want false by default")
	}

	if cfg.TradingExchangeBaseURL != "" {
		t.Errorf("TradingExchangeBaseURL = %q, want empty", cfg.TradingExchangeBaseURL)
	}

	if cfg.MarketData.StaleAfter != 30*time.Second {
		t.Errorf("MarketData.StaleAfter = %s, want 30s (half of the 60s tick interval)", cfg.MarketData.StaleAfter)
	}

	if cfg.BacktestJobTimeout != 600*time.Second {
		t.Errorf("BacktestJobTimeout = %s, want 600s", cfg.BacktestJobTimeout)
	}

	if cfg.BacktestMaxFills != 25000 || cfg.BacktestEquityPoints != 1000 || cfg.BacktestProgressInterval != 500 {
		t.Errorf("backtest caps = %d/%d/%d, want 25000/1000/500",
			cfg.BacktestMaxFills, cfg.BacktestEquityPoints, cfg.BacktestProgressInterval)
	}
}

func TestLoadComputesAndOverridesMarketDataStaleBound(t *testing.T) {
	t.Parallel()

	cfg, err := load(map[string]string{"TRADING_TICK_INTERVAL": "120"})
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}
	if cfg.MarketData.StaleAfter != 60*time.Second {
		t.Errorf("StaleAfter = %s, want 60s (half of a 120s tick interval)", cfg.MarketData.StaleAfter)
	}

	cfg, err = load(map[string]string{
		"TRADING_TICK_INTERVAL":          "120",
		"ENGINE_MARKET_DATA_STALE_AFTER": "45s",
	})
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}
	if cfg.MarketData.StaleAfter != 45*time.Second {
		t.Errorf("StaleAfter = %s, want the 45s override", cfg.MarketData.StaleAfter)
	}

	if _, err := load(map[string]string{"ENGINE_MARKET_DATA_STALE_AFTER": "0s"}); err == nil {
		t.Error("Load() error = nil, want a positive stale bound")
	}
}

func TestLoadReadsExchangeSandboxAndTransport(t *testing.T) {
	t.Parallel()

	cfg, err := load(map[string]string{
		"TRADING_EXCHANGE_SANDBOX": "true",
		"ENGINE_EXCHANGE_BASE_URL": "http://relay.internal:8080",
		"APP_KEY":                  "base64:test-only",
		"APP_PREVIOUS_KEYS":        "base64:one,base64:two",
	})
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}

	if !cfg.TradingExchangeSandbox {
		t.Error("TradingExchangeSandbox = false, want true")
	}
	if cfg.TradingExchangeBaseURL != "http://relay.internal:8080" {
		t.Errorf("TradingExchangeBaseURL = %q", cfg.TradingExchangeBaseURL)
	}
	if cfg.AppKey != "base64:test-only" {
		t.Error("APP_KEY was not loaded into memory")
	}
	if len(cfg.AppPreviousKeys) != 2 {
		t.Errorf("AppPreviousKeys = %v, want two", cfg.AppPreviousKeys)
	}

	if _, err := load(map[string]string{"ENGINE_EXCHANGE_BASE_URL": "not a url"}); err == nil {
		t.Error("Load() error = nil, want an invalid base URL error")
	}
}

func TestLoadReadsExchangeAllowlist(t *testing.T) {
	t.Parallel()

	cfg, err := load(map[string]string{
		"TRADING_EXCHANGES":        " binance, kraken ,,coinbase ",
		"TRADING_DEFAULT_EXCHANGE": "kraken",
	})
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}

	want := []string{"binance", "kraken", "coinbase"}
	if len(cfg.TradingExchanges) != len(want) {
		t.Fatalf("TradingExchanges = %v, want %v", cfg.TradingExchanges, want)
	}

	for index, exchange := range want {
		if cfg.TradingExchanges[index] != exchange {
			t.Fatalf("TradingExchanges[%d] = %q, want %q", index, cfg.TradingExchanges[index], exchange)
		}
	}

	if cfg.TradingDefaultExchange != "kraken" {
		t.Fatalf("TradingDefaultExchange = %q, want kraken", cfg.TradingDefaultExchange)
	}
}

func TestLoadReadsComposeEnvironment(t *testing.T) {
	t.Parallel()

	cfg, err := load(map[string]string{
		"ENGINE_LISTEN_ADDR":   ":9090",
		"APP_ENV":              "testing",
		"DB_HOST":              "postgres",
		"DB_PORT":              "5433",
		"DB_DATABASE":          "trading_bot_test",
		"DB_USERNAME":          "engine",
		"DB_PASSWORD":          "secret",
		"REDIS_HOST":           "redis",
		"REDIS_PORT":           "6380",
		"REDIS_DB":             "2",
		"TRADING_LIVE_ENABLED": "false",
	})
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}

	if cfg.Addr != ":9090" || cfg.AppEnv != "testing" {
		t.Errorf("Addr/AppEnv = %q/%q, want :9090/testing", cfg.Addr, cfg.AppEnv)
	}

	if cfg.Database.DSN() == "" {
		t.Error("DSN() is empty")
	}

	if cfg.Database.Port != 5433 || cfg.Database.Name != "trading_bot_test" {
		t.Errorf("Database = %+v, want port 5433 and test database", cfg.Database)
	}

	if cfg.Redis.Addr() != "redis:6380" || cfg.Redis.DB != 2 {
		t.Errorf("Redis = %+v, want redis:6380 db 2", cfg.Redis)
	}
}

func TestLoadParsesLiveGate(t *testing.T) {
	t.Parallel()

	for value, want := range map[string]bool{
		"":      false,
		"false": false,
		"0":     false,
		"true":  true,
		"1":     true,
	} {
		cfg, err := load(map[string]string{"TRADING_LIVE_ENABLED": value})
		if err != nil {
			t.Fatalf("Load(%q) error = %v", value, err)
		}

		if cfg.LiveTrading != want {
			t.Errorf("LiveTrading for %q = %v, want %v", value, cfg.LiveTrading, want)
		}
	}
}

func TestLoadRejectsInvalidValues(t *testing.T) {
	t.Parallel()

	cases := map[string]map[string]string{
		"port":     {"DB_PORT": "not-a-port"},
		"range":    {"REDIS_PORT": "70000"},
		"address":  {"ENGINE_LISTEN_ADDR": "8080"},
		"bool":     {"TRADING_LIVE_ENABLED": "maybe"},
		"redisDB":  {"REDIS_DB": "-1"},
		"timeout":  {"BACKTEST_JOB_TIMEOUT": "abc"},
		"maxFills": {"BACKTEST_MAX_FILLS": "0"},
		"equity":   {"BACKTEST_EQUITY_POINTS": "-1"},
		"progress": {"BACKTEST_PROGRESS_INTERVAL": "nope"},
	}

	for name, env := range cases {
		if _, err := load(env); err == nil {
			t.Errorf("%s: Load(%v) error = nil, want error", name, env)
		}
	}
}

func TestLoadMarketDataDefaultsToIdle(t *testing.T) {
	t.Parallel()

	cfg, err := load(map[string]string{})
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}

	if cfg.MarketData.Configured() {
		t.Error("MarketData.Configured() = true, want idle by default")
	}

	if cfg.MarketData.WSURL != "" || cfg.MarketData.RESTURL != "" {
		t.Errorf("MarketData URLs = %q/%q, want empty", cfg.MarketData.WSURL, cfg.MarketData.RESTURL)
	}

	if len(cfg.MarketData.Symbols) != 0 {
		t.Errorf("MarketData.Symbols = %v, want empty", cfg.MarketData.Symbols)
	}

	if cfg.MarketData.QuietAfter != 30*time.Second {
		t.Errorf("QuietAfter = %s, want 30s", cfg.MarketData.QuietAfter)
	}

	if cfg.MarketData.RESTPollInterval != 5*time.Second {
		t.Errorf("RESTPollInterval = %s, want 5s", cfg.MarketData.RESTPollInterval)
	}
}

func TestLoadReadsMarketDataEnvironment(t *testing.T) {
	t.Parallel()

	cfg, err := load(map[string]string{
		"ENGINE_MARKET_DATA_WS_URL":             "wss://stream.example.test:9443/ws",
		"ENGINE_MARKET_DATA_REST_URL":           "https://api.example.test",
		"ENGINE_MARKET_DATA_SYMBOLS":            " btcusdt, ETHUSDT ,btcusdt,",
		"ENGINE_MARKET_DATA_QUIET_AFTER":        "45s",
		"ENGINE_MARKET_DATA_REST_POLL_INTERVAL": "3s",
		"ENGINE_MARKET_DATA_RECONNECT_MIN":      "250ms",
		"ENGINE_MARKET_DATA_RECONNECT_MAX":      "20s",
		"ENGINE_MARKET_DATA_REQUEST_TIMEOUT":    "4s",
	})
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}

	if !cfg.MarketData.Configured() {
		t.Error("MarketData.Configured() = false, want true")
	}

	if cfg.MarketData.QuietAfter != 45*time.Second {
		t.Errorf("QuietAfter = %s, want 45s", cfg.MarketData.QuietAfter)
	}

	if cfg.MarketData.ReconnectMin != 250*time.Millisecond || cfg.MarketData.ReconnectMax != 20*time.Second {
		t.Errorf("ReconnectMin/Max = %s/%s, want 250ms/20s", cfg.MarketData.ReconnectMin, cfg.MarketData.ReconnectMax)
	}

	joined := ""
	for _, symbol := range cfg.MarketData.Symbols {
		joined += symbol + ","
	}

	if joined != "BTCUSDT,ETHUSDT," {
		t.Errorf("Symbols = %v, want normalized unique BTCUSDT/ETHUSDT", cfg.MarketData.Symbols)
	}
}

func TestLoadRejectsUnpairedMarketDataURLs(t *testing.T) {
	t.Parallel()

	if _, err := load(map[string]string{
		"ENGINE_MARKET_DATA_WS_URL": "wss://stream.example.test/ws",
	}); err == nil {
		t.Error("Load() error = nil, want a paired-URL error")
	}

	if _, err := load(map[string]string{
		"ENGINE_MARKET_DATA_REST_URL": "https://api.example.test",
	}); err == nil {
		t.Error("Load() error = nil, want a paired-URL error")
	}
}

func TestLoadRejectsInvalidMarketDataValues(t *testing.T) {
	t.Parallel()

	cases := map[string]map[string]string{
		"ws scheme": {
			"ENGINE_MARKET_DATA_WS_URL":   "https://stream.example.test/ws",
			"ENGINE_MARKET_DATA_REST_URL": "https://api.example.test",
		},
		"rest scheme": {
			"ENGINE_MARKET_DATA_WS_URL":   "wss://stream.example.test/ws",
			"ENGINE_MARKET_DATA_REST_URL": "wss://api.example.test",
		},
		"duration": {
			"ENGINE_MARKET_DATA_QUIET_AFTER": "soon",
		},
		"negative duration": {
			"ENGINE_MARKET_DATA_RECONNECT_MIN": "-1s",
		},
		"backoff order": {
			"ENGINE_MARKET_DATA_RECONNECT_MIN": "20s",
			"ENGINE_MARKET_DATA_RECONNECT_MAX": "1s",
		},
	}

	for name, env := range cases {
		if _, err := load(env); err == nil {
			t.Errorf("%s: Load(%v) error = nil, want error", name, env)
		}
	}
}

func TestDatabaseDSNIsValidURLWithEscapedPassword(t *testing.T) {
	t.Parallel()

	database := config.Database{
		Host:     "postgres",
		Port:     5432,
		Name:     "trading_bot",
		User:     "trading_bot",
		Password: "p@ss word/with:specials",
		SSLMode:  "disable",
	}

	parsed, err := url.Parse(database.DSN())
	if err != nil {
		t.Fatalf("url.Parse(DSN()) error = %v", err)
	}

	password, present := parsed.User.Password()
	if !present || password != database.Password {
		t.Errorf("DSN password = %q (present %v), want %q", password, present, database.Password)
	}

	if parsed.Host != "postgres:5432" {
		t.Errorf("DSN host = %q, want postgres:5432", parsed.Host)
	}

	if parsed.Query().Get("sslmode") != "disable" {
		t.Errorf("DSN sslmode = %q, want disable", parsed.Query().Get("sslmode"))
	}

	if parsed.Query().Get("application_name") != "trading-engine" {
		t.Errorf("DSN application_name = %q, want trading-engine", parsed.Query().Get("application_name"))
	}
}
