// Package config loads the engine's runtime configuration from environment
// variables. Values are read into memory only; nothing here is logged and no
// secret is persisted.
package config

import (
	"fmt"
	"net"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// DefaultListenAddr is the documented address the engine serves. Compose and
// the in-container healthcheck both rely on it.
const DefaultListenAddr = ":8080"

// Database is the PostgreSQL connection configuration shared with Laravel.
type Database struct {
	Host     string
	Port     int
	Name     string
	User     string
	Password string
	SSLMode  string
}

// DSN builds a libpq connection URL. The password is escaped by net/url.
func (d Database) DSN() string {
	u := &url.URL{
		Scheme: "postgres",
		User:   url.UserPassword(d.User, d.Password),
		Host:   net.JoinHostPort(d.Host, strconv.Itoa(d.Port)),
		Path:   "/" + d.Name,
	}
	query := u.Query()
	query.Set("application_name", "trading-engine")
	query.Set("sslmode", d.SSLMode)
	u.RawQuery = query.Encode()

	return u.String()
}

// Redis is the Redis connection configuration shared with Laravel.
type Redis struct {
	Host     string
	Port     int
	Password string
	DB       int
}

// Addr is the host:port form expected by go-redis.
func (r Redis) Addr() string {
	return net.JoinHostPort(r.Host, strconv.Itoa(r.Port))
}

// MarketData configures the public exchange feed. Both URLs are empty by
// default, which keeps the feed idle: the engine process opens no exchange
// connection until an operator explicitly opts in. Symbols is an optional
// operator-pinned watchlist; running bots' symbols are added dynamically.
type MarketData struct {
	WSURL            string
	RESTURL          string
	Symbols          []string
	QuietAfter       time.Duration
	RESTPollInterval time.Duration
	ReconnectMin     time.Duration
	ReconnectMax     time.Duration
	RequestTimeout   time.Duration
	// StaleAfter is the maximum age of a cached quote a tick may use. The
	// default is 15 seconds or half of TRADING_TICK_INTERVAL, whichever is
	// larger, and ENGINE_MARKET_DATA_STALE_AFTER overrides it.
	StaleAfter time.Duration
}

// Configured reports whether both public endpoints are set.
func (m MarketData) Configured() bool {
	return m.WSURL != "" && m.RESTURL != ""
}

// Config is the full engine configuration.
type Config struct {
	Addr                     string
	AppEnv                   string
	AppKey                   string
	AppPreviousKeys          []string
	Database                 Database
	Redis                    Redis
	LiveTrading              bool
	TradingExchanges         []string
	TradingDefaultExchange   string
	TradingTickInterval      time.Duration
	TradingOrderSync         time.Duration
	TradingExchangeSandbox   bool
	TradingExchangeBaseURL   string
	InternalToken            string
	MarketData               MarketData
	BacktestJobTimeout       time.Duration
	BacktestMaxFills         int
	BacktestEquityPoints     int
	BacktestProgressInterval int
}

// Load reads configuration from the supplied environment lookup (usually
// os.Getenv). It applies the same defaults as the local Compose stack and
// fails fast on malformed values.
func Load(getenv func(string) string) (Config, error) {
	cfg := Config{
		Addr:   envOr(getenv, "ENGINE_LISTEN_ADDR", DefaultListenAddr),
		AppEnv: envOr(getenv, "APP_ENV", "local"),
		AppKey: strings.TrimSpace(getenv("APP_KEY")),
		Database: Database{
			Host:     envOr(getenv, "DB_HOST", "127.0.0.1"),
			Port:     5432,
			Name:     envOr(getenv, "DB_DATABASE", "trading_bot"),
			User:     envOr(getenv, "DB_USERNAME", "trading_bot"),
			Password: getenv("DB_PASSWORD"),
			SSLMode:  envOr(getenv, "DB_SSLMODE", "disable"),
		},
		Redis: Redis{
			Host:     envOr(getenv, "REDIS_HOST", "127.0.0.1"),
			Port:     6379,
			Password: getenv("REDIS_PASSWORD"),
		},
		TradingExchanges:       splitList(envOr(getenv, "TRADING_EXCHANGES", "binance")),
		TradingDefaultExchange: envOr(getenv, "TRADING_DEFAULT_EXCHANGE", "binance"),
		TradingExchangeBaseURL: strings.TrimSpace(getenv("ENGINE_EXCHANGE_BASE_URL")),
		AppPreviousKeys:        splitList(getenv("APP_PREVIOUS_KEYS")),
		InternalToken:          strings.TrimSpace(getenv("ENGINE_INTERNAL_TOKEN")),
		MarketData: MarketData{
			WSURL:   strings.TrimSpace(getenv("ENGINE_MARKET_DATA_WS_URL")),
			RESTURL: strings.TrimSpace(getenv("ENGINE_MARKET_DATA_REST_URL")),
			Symbols: splitSymbols(getenv("ENGINE_MARKET_DATA_SYMBOLS")),
		},
	}

	var err error

	if cfg.Database.Port, err = parsePort("DB_PORT", envOr(getenv, "DB_PORT", "5432")); err != nil {
		return Config{}, err
	}

	if cfg.Redis.Port, err = parsePort("REDIS_PORT", envOr(getenv, "REDIS_PORT", "6379")); err != nil {
		return Config{}, err
	}

	if cfg.Redis.DB, err = parseNonNegativeInt("REDIS_DB", envOr(getenv, "REDIS_DB", "0")); err != nil {
		return Config{}, err
	}

	if _, _, err := net.SplitHostPort(cfg.Addr); err != nil {
		return Config{}, fmt.Errorf("ENGINE_LISTEN_ADDR %q is not a host:port address: %w", cfg.Addr, err)
	}

	if cfg.LiveTrading, err = parseBool("TRADING_LIVE_ENABLED", getenv("TRADING_LIVE_ENABLED")); err != nil {
		return Config{}, err
	}

	if cfg.TradingExchangeSandbox, err = parseBool("TRADING_EXCHANGE_SANDBOX", getenv("TRADING_EXCHANGE_SANDBOX")); err != nil {
		return Config{}, err
	}

	if cfg.MarketData.QuietAfter, err = parseDuration("ENGINE_MARKET_DATA_QUIET_AFTER", getenv("ENGINE_MARKET_DATA_QUIET_AFTER"), 30*time.Second); err != nil {
		return Config{}, err
	}

	if cfg.MarketData.RESTPollInterval, err = parseDuration("ENGINE_MARKET_DATA_REST_POLL_INTERVAL", getenv("ENGINE_MARKET_DATA_REST_POLL_INTERVAL"), 5*time.Second); err != nil {
		return Config{}, err
	}

	if cfg.MarketData.ReconnectMin, err = parseDuration("ENGINE_MARKET_DATA_RECONNECT_MIN", getenv("ENGINE_MARKET_DATA_RECONNECT_MIN"), 500*time.Millisecond); err != nil {
		return Config{}, err
	}

	if cfg.MarketData.ReconnectMax, err = parseDuration("ENGINE_MARKET_DATA_RECONNECT_MAX", getenv("ENGINE_MARKET_DATA_RECONNECT_MAX"), 30*time.Second); err != nil {
		return Config{}, err
	}

	if cfg.MarketData.RequestTimeout, err = parseDuration("ENGINE_MARKET_DATA_REQUEST_TIMEOUT", getenv("ENGINE_MARKET_DATA_REQUEST_TIMEOUT"), 10*time.Second); err != nil {
		return Config{}, err
	}

	tickInterval, err := parsePositiveInt("TRADING_TICK_INTERVAL", envOr(getenv, "TRADING_TICK_INTERVAL", "60"))
	if err != nil {
		return Config{}, err
	}
	cfg.TradingTickInterval = time.Duration(tickInterval) * time.Second

	orderSyncSeconds, err := parsePositiveInt("TRADING_ORDER_SYNC_SECONDS", envOr(getenv, "TRADING_ORDER_SYNC_SECONDS", "5"))
	if err != nil {
		return Config{}, err
	}
	cfg.TradingOrderSync = time.Duration(orderSyncSeconds) * time.Second

	// A tick may read a cached quote no older than half its evaluation
	// interval, with a 15-second floor so a fast bot still has slack for one
	// missed socket update.
	staleAfter := 15 * time.Second
	if half := cfg.TradingTickInterval / 2; half > staleAfter {
		staleAfter = half
	}
	if raw := strings.TrimSpace(getenv("ENGINE_MARKET_DATA_STALE_AFTER")); raw != "" {
		if cfg.MarketData.StaleAfter, err = parseDuration("ENGINE_MARKET_DATA_STALE_AFTER", raw, staleAfter); err != nil {
			return Config{}, err
		}
		if cfg.MarketData.StaleAfter <= 0 {
			return Config{}, fmt.Errorf("ENGINE_MARKET_DATA_STALE_AFTER %q must be a positive duration", raw)
		}
	} else {
		cfg.MarketData.StaleAfter = staleAfter
	}

	if cfg.BacktestJobTimeout, err = parseDuration("BACKTEST_JOB_TIMEOUT", getenv("BACKTEST_JOB_TIMEOUT"), 600*time.Second); err != nil {
		return Config{}, err
	}
	if cfg.BacktestJobTimeout <= 0 {
		return Config{}, fmt.Errorf("BACKTEST_JOB_TIMEOUT must be a positive duration")
	}

	if cfg.BacktestMaxFills, err = parsePositiveInt("BACKTEST_MAX_FILLS", envOr(getenv, "BACKTEST_MAX_FILLS", "25000")); err != nil {
		return Config{}, err
	}
	if cfg.BacktestEquityPoints, err = parsePositiveInt("BACKTEST_EQUITY_POINTS", envOr(getenv, "BACKTEST_EQUITY_POINTS", "1000")); err != nil {
		return Config{}, err
	}
	if cfg.BacktestProgressInterval, err = parsePositiveInt("BACKTEST_PROGRESS_INTERVAL", envOr(getenv, "BACKTEST_PROGRESS_INTERVAL", "500")); err != nil {
		return Config{}, err
	}

	if err := validateEndpoint("ENGINE_MARKET_DATA_WS_URL", cfg.MarketData.WSURL, "ws", "wss"); err != nil {
		return Config{}, err
	}

	if err := validateEndpoint("ENGINE_MARKET_DATA_REST_URL", cfg.MarketData.RESTURL, "http", "https"); err != nil {
		return Config{}, err
	}

	if err := validateEndpoint("ENGINE_EXCHANGE_BASE_URL", cfg.TradingExchangeBaseURL, "http", "https"); err != nil {
		return Config{}, err
	}

	if (cfg.MarketData.WSURL == "") != (cfg.MarketData.RESTURL == "") {
		return Config{}, fmt.Errorf("ENGINE_MARKET_DATA_WS_URL and ENGINE_MARKET_DATA_REST_URL must be set together; leave both empty to keep market data idle")
	}

	if cfg.MarketData.ReconnectMax < cfg.MarketData.ReconnectMin {
		return Config{}, fmt.Errorf("ENGINE_MARKET_DATA_RECONNECT_MAX %s must not be shorter than ENGINE_MARKET_DATA_RECONNECT_MIN %s", cfg.MarketData.ReconnectMax, cfg.MarketData.ReconnectMin)
	}

	return cfg, nil
}

func envOr(getenv func(string) string, key, fallback string) string {
	if value := strings.TrimSpace(getenv(key)); value != "" {
		return value
	}

	return fallback
}

func parsePort(key, value string) (int, error) {
	port, err := strconv.Atoi(value)
	if err != nil || port < 1 || port > 65535 {
		return 0, fmt.Errorf("%s %q must be a TCP port between 1 and 65535", key, value)
	}

	return port, nil
}

func parseNonNegativeInt(key, value string) (int, error) {
	number, err := strconv.Atoi(value)
	if err != nil || number < 0 {
		return 0, fmt.Errorf("%s %q must be a non-negative integer", key, value)
	}

	return number, nil
}

func parsePositiveInt(key, value string) (int, error) {
	number, err := strconv.Atoi(value)
	if err != nil || number < 1 {
		return 0, fmt.Errorf("%s %q must be a positive integer", key, value)
	}

	return number, nil
}

// parseBool treats an unset value as false, which keeps
// TRADING_LIVE_ENABLED=false unless an operator explicitly opts in.
func parseBool(key, value string) (bool, error) {
	if strings.TrimSpace(value) == "" {
		return false, nil
	}

	parsed, err := strconv.ParseBool(strings.TrimSpace(value))
	if err != nil {
		return false, fmt.Errorf("%s %q must be a boolean", key, value)
	}

	return parsed, nil
}

// parseDuration treats an unset value as the documented default and rejects a
// negative value.
func parseDuration(key, value string, fallback time.Duration) (time.Duration, error) {
	trimmed := strings.TrimSpace(value)
	if trimmed == "" {
		return fallback, nil
	}

	parsed, err := time.ParseDuration(trimmed)
	if err != nil || parsed < 0 {
		return 0, fmt.Errorf("%s %q must be a non-negative duration", key, value)
	}

	return parsed, nil
}

// validateEndpoint accepts an empty value (market data stays idle) or an
// absolute URL with one of the allowed schemes.
func validateEndpoint(key, value string, schemes ...string) error {
	if value == "" {
		return nil
	}

	parsed, err := url.Parse(value)
	if err != nil || parsed.Host == "" {
		return fmt.Errorf("%s %q must be an absolute URL with a host", key, value)
	}

	for _, scheme := range schemes {
		if parsed.Scheme == scheme {
			return nil
		}
	}

	return fmt.Errorf("%s %q must use one of the schemes: %s", key, value, strings.Join(schemes, ", "))
}

// splitList parses a comma-separated config list, preserving case and order
// and dropping blank entries, matching Laravel's array_filter(array_map('trim',
// explode(',', ...))) for TRADING_EXCHANGES.
func splitList(value string) []string {
	parts := strings.Split(value, ",")
	result := make([]string, 0, len(parts))

	for _, part := range parts {
		trimmed := strings.TrimSpace(part)
		if trimmed == "" {
			continue
		}

		result = append(result, trimmed)
	}

	return result
}

// splitSymbols parses a comma-separated symbol list, trimming blanks and
// duplicates. An empty value means no subscriptions.
func splitSymbols(value string) []string {
	parts := strings.Split(value, ",")
	symbols := make([]string, 0, len(parts))
	seen := make(map[string]struct{}, len(parts))

	for _, part := range parts {
		symbol := strings.ToUpper(strings.TrimSpace(part))
		if symbol == "" {
			continue
		}

		if _, ok := seen[symbol]; ok {
			continue
		}

		seen[symbol] = struct{}{}
		symbols = append(symbols, symbol)
	}

	return symbols
}
