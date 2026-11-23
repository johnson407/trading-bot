// Command engine is the trading engine process. Phase 1 is a skeleton: it
// serves liveness/readiness endpoints only. It does not consume commands,
// subscribe to market data, touch credentials, or place orders.
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
	"github.com/johnson407/trading-bot/engine/internal/health"
	"github.com/johnson407/trading-bot/engine/internal/healthcheck"
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
	logger.Info("starting trading engine",
		"addr", cfg.Addr,
		"app_env", cfg.AppEnv,
		"live_trading_enabled", cfg.LiveTrading,
		"trading_paths", "none (phase 1 skeleton)",
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

	checks := []health.Check{
		{Name: "postgres", Ping: pool.Ping},
		{Name: "redis", Ping: func(ctx context.Context) error {
			return redisClient.Ping(ctx).Err()
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
