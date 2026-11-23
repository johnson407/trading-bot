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

// Config is the full engine configuration.
type Config struct {
	Addr        string
	AppEnv      string
	Database    Database
	Redis       Redis
	LiveTrading bool
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
