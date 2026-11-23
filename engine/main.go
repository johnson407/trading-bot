// Command engine is the trading engine process. Phase 3 adds the public
// market-data feed hook; it does not consume trading commands, run strategies,
// touch credentials, or place orders.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
	goredis "github.com/redis/go-redis/v9"

	"github.com/johnson407/trading-bot/engine/internal/config"
	"github.com/johnson407/trading-bot/engine/internal/consumer"
	"github.com/johnson407/trading-bot/engine/internal/health"
	"github.com/johnson407/trading-bot/engine/internal/healthcheck"
	"github.com/johnson407/trading-bot/engine/internal/heartbeat"
	"github.com/johnson407/trading-bot/engine/internal/idempotency"
	"github.com/johnson407/trading-bot/engine/internal/marketdata"
	"github.com/johnson407/trading-bot/engine/internal/protocol"
)

func main() {
	if len(os.Args) > 1 && os.Args[1] == "healthcheck" {
		if err := runHealthcheck(); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}

		return
	}

	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func run() error {
	cfg, err := config.Load(os.Getenv)
	if err != nil {
		return fmt.Errorf("load config: %w", err)
	}

	logger := slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))
	slog.SetDefault(logger)

	logger.Info("starting trading engine",
		"addr", cfg.Addr,
		"app_env", cfg.AppEnv,
		"live_trading_enabled", cfg.LiveTrading,
		"trading_paths", "none (phase 3 market data only)",
	)

	if cfg.LiveTrading {
		logger.Warn("TRADING_LIVE_ENABLED is true but this build has no trading paths; no exchange calls are made")
	}

	pool, err := pgxpool.New(context.Background(), cfg.Database.DSN())
	if err != nil {
		return fmt.Errorf("configure postgres pool: %w", err)
	}
	defer pool.Close()

	redisClient := goredis.NewClient(&goredis.Options{
		Addr:     cfg.Redis.Addr(),
		Password: cfg.Redis.Password,
		DB:       cfg.Redis.DB,
	})
	defer func() { _ = redisClient.Close() }()

	engineContext, stopEngine := context.WithCancel(context.Background())
	defer stopEngine()

	heartbeatWriter := heartbeat.NewStub(redisHeartbeatSetter{client: redisClient})

	// Write once before serving so readiness does not race the first refresh.
	if err := heartbeatWriter.Write(engineContext, 0); err != nil {
		logger.Warn("initial heartbeat write failed", "error_type", fmt.Sprintf("%T", err))
	}

	go heartbeatWriter.Run(engineContext, heartbeat.RefreshInterval, func() int { return 0 })

	commandConsumer := consumer.New(
		redisClient,
		contractProcessor{logger: logger},
		idempotency.New(redisClient),
		logger,
		consumer.DefaultConfig(),
	)

	go func() {
		if err := commandConsumer.Run(engineContext); err != nil {
			logger.Error("command consumer stopped", "error_type", fmt.Sprintf("%T", err))
		}
	}()

	// Phase 3 market-data hook. It stays idle unless an operator sets both
	// public endpoints and a symbol list; the shipped Compose service sets
	// none of them, so the engine opens no exchange connection. The future
	// tick loop will pass the running bots' symbols here.
	marketFeed := marketdata.New(
		marketdata.Config{
			WSURL:            cfg.MarketData.WSURL,
			RESTURL:          cfg.MarketData.RESTURL,
			QuietAfter:       cfg.MarketData.QuietAfter,
			RESTPollInterval: cfg.MarketData.RESTPollInterval,
			ReconnectMin:     cfg.MarketData.ReconnectMin,
			ReconnectMax:     cfg.MarketData.ReconnectMax,
			RequestTimeout:   cfg.MarketData.RequestTimeout,
		},
		logger,
		nil,
	)

	switch {
	case !cfg.MarketData.Configured():
		logger.Info("market data idle: public endpoints are not configured; no exchange connection will be opened")
	case len(cfg.MarketData.Symbols) == 0:
		logger.Info("market data idle: ENGINE_MARKET_DATA_SYMBOLS is empty; no exchange connection will be opened")
	default:
		logger.Info("market data enabled for explicit symbols", "symbols", len(cfg.MarketData.Symbols))
		go func() {
			if err := marketFeed.Run(engineContext, cfg.MarketData.Symbols); err != nil {
				logger.Error("market data feed stopped", "error_type", fmt.Sprintf("%T", err))
			}
		}()
	}

	checks := []health.Check{
		{Name: "postgres", Ping: pool.Ping},
		{Name: "redis", Ping: func(ctx context.Context) error {
			return redisClient.Ping(ctx).Err()
		}},
		{Name: "heartbeat", Ping: func(ctx context.Context) error {
			return heartbeat.CheckFresh(ctx, redisHeartbeatReader{client: redisClient}, time.Now())
		}},
	}

	server := &http.Server{
		Addr:              cfg.Addr,
		Handler:           health.NewRouter(checks, logger),
		ReadHeaderTimeout: 5 * time.Second,
	}

	serverErrors := make(chan error, 1)

	go func() {
		serverErrors <- server.ListenAndServe()
	}()

	signals := make(chan os.Signal, 1)
	signal.Notify(signals, syscall.SIGINT, syscall.SIGTERM)

	select {
	case err := <-serverErrors:
		if !errors.Is(err, http.ErrServerClosed) {
			return fmt.Errorf("serve: %w", err)
		}
	case received := <-signals:
		logger.Info("shutdown requested", "signal", received.String())
	}

	shutdownContext, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	if err := server.Shutdown(shutdownContext); err != nil {
		return fmt.Errorf("shutdown: %w", err)
	}

	return nil
}

// runHealthcheck probes the engine's own /healthz endpoint. It is the Compose
// healthcheck entrypoint and performs no exchange calls.
func runHealthcheck() error {
	cfg, err := config.Load(os.Getenv)
	if err != nil {
		return fmt.Errorf("load config: %w", err)
	}

	probeURL, err := healthcheck.URL(cfg.Addr)
	if err != nil {
		return err
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	client := &http.Client{Timeout: 4 * time.Second}

	return healthcheck.Run(ctx, probeURL, client)
}

// redisHeartbeatSetter adapts go-redis to heartbeat.Setter.
type redisHeartbeatSetter struct {
	client *goredis.Client
}

func (s redisHeartbeatSetter) Set(ctx context.Context, key string, value []byte, ttl time.Duration) error {
	return s.client.Set(ctx, key, value, ttl).Err()
}

// redisHeartbeatReader adapts go-redis to heartbeat.Reader.
type redisHeartbeatReader struct {
	client *goredis.Client
}

func (r redisHeartbeatReader) Get(ctx context.Context, key string) ([]byte, error) {
	return r.client.Get(ctx, key).Bytes()
}

// contractProcessor is the phase-2 command processor. It validates and
// acknowledges the contract but has no trading handlers yet, so it rejects
// every command definitively instead of pretending to apply it. Phase 4/7
// replace this with the real bot/order/backtest handlers.
type contractProcessor struct {
	logger *slog.Logger
}

func (p contractProcessor) Process(_ context.Context, command protocol.Envelope) error {
	p.logger.Info("command received but no trading handler exists in this build",
		"command_id", command.ID,
		"type", command.Type,
	)

	return consumer.Rejection{Reason: "handler_not_available"}
}
