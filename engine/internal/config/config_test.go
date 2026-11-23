package config_test

import (
	"net/url"
	"testing"

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
		"port":    {"DB_PORT": "not-a-port"},
		"range":   {"REDIS_PORT": "70000"},
		"address": {"ENGINE_LISTEN_ADDR": "8080"},
		"bool":    {"TRADING_LIVE_ENABLED": "maybe"},
		"redisDB": {"REDIS_DB": "-1"},
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
