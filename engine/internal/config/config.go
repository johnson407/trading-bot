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
// connection until an operator explicitly opts in. Phase 3 has no running-bot
// tick loop, so Symbols is also empty by default.
type MarketData struct {
	WSURL            string
	RESTURL          string
	Symbols          []string
	QuietAfter       time.Duration
	RESTPollInterval time.Duration
	ReconnectMin     time.Duration
	ReconnectMax     time.Duration
	RequestTimeout   time.Duration
}

// Configured reports whether both public endpoints are set.
func (m MarketData) Configured() bool {
	return m.WSURL != "" && m.RESTURL != ""
}

// Config is the full engine configuration.
type Config struct {
	Addr        string
	AppEnv      string
	Database    Database
	Redis       Redis
	LiveTrading bool
	MarketData  MarketData
}

// Load reads configuration from the supplied environment lookup (usually
// os.Getenv). It applies the same defaults as the local Compose stack and
// fails fast on malformed values.
func Load(getenv func(string) string) (Config, error) {
	cfg := Config{
		Addr:   envOr(getenv, "ENGINE_LISTEN_ADDR", DefaultListenAddr),
		AppEnv: envOr(getenv, "APP_ENV", "local"),
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

	if err := validateEndpoint("ENGINE_MARKET_DATA_WS_URL", cfg.MarketData.WSURL, "ws", "wss"); err != nil {
		return Config{}, err
	}

	if err := validateEndpoint("ENGINE_MARKET_DATA_REST_URL", cfg.MarketData.RESTURL, "http", "https"); err != nil {
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
