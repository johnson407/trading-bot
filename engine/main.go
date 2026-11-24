// Command engine is the trading engine process. It runs the paper trading
// runtime (bot commands, due-check ticks, grids, paper fills, risk/exit guards),
// deterministic backtests, and the heartbeat. Live execution is not implemented;
// live bots are paused before any private exchange call.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
	goredis "github.com/redis/go-redis/v9"

	"github.com/johnson407/trading-bot/engine/internal/backtest"
	"github.com/johnson407/trading-bot/engine/internal/config"
	"github.com/johnson407/trading-bot/engine/internal/consumer"
	"github.com/johnson407/trading-bot/engine/internal/decimal"
	"github.com/johnson407/trading-bot/engine/internal/health"
	"github.com/johnson407/trading-bot/engine/internal/healthcheck"
	"github.com/johnson407/trading-bot/engine/internal/heartbeat"
	"github.com/johnson407/trading-bot/engine/internal/idempotency"
	"github.com/johnson407/trading-bot/engine/internal/marketdata"
	"github.com/johnson407/trading-bot/engine/internal/protocol"
	"github.com/johnson407/trading-bot/engine/internal/runtime"
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
		"trading_paths", "paper trading and backtests; live execution disabled",
	)

	if cfg.LiveTrading {
		logger.Warn("TRADING_LIVE_ENABLED is true but live execution is not implemented; live bots are paused and no private exchange calls are made")
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

	tradingRuntime := runtime.New(pool, newPublicMarket(cfg.MarketData.RESTURL, cfg.MarketData.RequestTimeout), logger)

	heartbeatWriter := heartbeat.NewStub(redisHeartbeatSetter{client: redisClient})

	// Write once before serving so readiness does not race the first refresh.
	if err := heartbeatWriter.Write(engineContext, tradingRuntime.RunningCount(engineContext)); err != nil {
		logger.Warn("initial heartbeat write failed", "error_type", fmt.Sprintf("%T", err))
	}

	go heartbeatWriter.Run(engineContext, heartbeat.RefreshInterval, func() int {
		return tradingRuntime.RunningCount(engineContext)
	})

	backtestStore := backtest.NewStore(
		pool,
		backtest.NewEngine(
			backtest.WithMaxFills(cfg.BacktestMaxFills),
			backtest.WithEquityPoints(cfg.BacktestEquityPoints),
			backtest.WithProgressInterval(cfg.BacktestProgressInterval),
		),
		cfg.BacktestJobTimeout,
		logger,
	).WithCandleFetcher(backtest.NewBinanceCandleFetcher(cfg.MarketData.RESTURL, cfg.MarketData.RequestTimeout))

	commandConsumer := consumer.New(
		redisClient,
		commandProcessor{backtests: backtestStore, runtime: tradingRuntime, logger: logger},
		idempotency.New(redisClient),
		logger,
		consumer.DefaultConfig(),
	)

	backtestConfig := consumer.DefaultConfig()
	backtestConfig.Stream = protocol.StreamBacktestCommands
	backtestConfig.Group = protocol.BacktestCommandsGroup
	backtestConfig.Consumer = "engine-backtests-1"
	backtestConfig.DLQ = protocol.StreamBacktestCommandsDLQ
	backtestConsumer := consumer.New(
		redisClient,
		commandProcessor{backtests: backtestStore, runtime: tradingRuntime, logger: logger},
		idempotency.New(redisClient),
		logger,
		backtestConfig,
	)

	go func() {
		if err := commandConsumer.Run(engineContext); err != nil {
			logger.Error("command consumer stopped", "error_type", fmt.Sprintf("%T", err))
		}
	}()

	go func() {
		if err := backtestConsumer.Run(engineContext); err != nil {
			logger.Error("backtest consumer stopped", "error_type", fmt.Sprintf("%T", err))
		}
	}()

	go runStaleBacktestSweeper(engineContext, backtestStore, cfg.BacktestJobTimeout, logger)
	go tradingRuntime.RunScheduler(engineContext)

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

// commandProcessor routes v1 commands. Backtest replay is implemented in this
// build; every other command is rejected definitively so Redis does not retry
// a handler this process does not have.
type commandProcessor struct {
	backtests *backtest.Store
	runtime   *runtime.Runtime
	logger    *slog.Logger
}

func (p commandProcessor) Process(ctx context.Context, command protocol.Envelope) error {
	switch command.Type {
	case protocol.CommandBacktestRun:
		id, ok := command.PayloadInt("backtest_run_id")
		if !ok || id <= 0 {
			return consumer.Rejection{Reason: "invalid_backtest_run_id"}
		}
		if err := p.backtests.ExecuteRun(ctx, id); err != nil {
			return err
		}
		return nil
	case protocol.CommandBacktestSweep:
		id, ok := command.PayloadInt("backtest_sweep_id")
		if !ok || id <= 0 {
			return consumer.Rejection{Reason: "invalid_backtest_sweep_id"}
		}
		if err := p.backtests.ExecuteSweep(ctx, id); err != nil {
			return err
		}
		return nil
	}

	if p.runtime != nil {
		switch command.Type {
		case protocol.CommandBotStart, protocol.CommandBotPause, protocol.CommandBotStop,
			protocol.CommandBotConfig, protocol.CommandOrderCancel, protocol.CommandKillSwitch:
			return p.runtime.Process(ctx, command)
		}
	}

	p.logger.Info("command received but no handler exists in this build",
		"command_id", command.ID,
		"type", command.Type,
	)

	return consumer.Rejection{Reason: "handler_not_available"}
}

// publicMarket adapts the public REST ticker client to the runtime market
// interface. It never uses credentials and supports the default Binance public
// endpoint only.
type publicMarket struct {
	client *marketdata.RESTClient
}

func newPublicMarket(baseURL string, timeout time.Duration) publicMarket {
	if baseURL == "" {
		baseURL = "https://api.binance.com"
	}
	if timeout <= 0 {
		timeout = 10 * time.Second
	}

	return publicMarket{client: &marketdata.RESTClient{
		BaseURL:    baseURL,
		HTTPClient: &http.Client{Timeout: timeout},
	}}
}

func (m publicMarket) Last(ctx context.Context, exchange, symbol string) (decimal.Decimal, error) {
	if exchange != "binance" {
		return decimal.Decimal{}, fmt.Errorf("public market data is not available for exchange %s", exchange)
	}

	tickers, err := m.client.Fetch(ctx, []string{symbol})
	if err != nil {
		return decimal.Decimal{}, err
	}

	ticker, ok := tickers[strings.ToUpper(strings.ReplaceAll(symbol, "/", ""))]
	if !ok {
		return decimal.Decimal{}, fmt.Errorf("public market data omitted %s", symbol)
	}

	return decimal.MustParse(ticker.Last), nil
}

// runStaleBacktestSweeper fails runs abandoned by a stopped worker. Laravel
// scheduled backtests:fail-stale every five minutes; the engine keeps that
// cadence and the same timeout + grace cutoff.
func runStaleBacktestSweeper(ctx context.Context, store *backtest.Store, timeout time.Duration, logger *slog.Logger) {
	ticker := time.NewTicker(5 * time.Minute)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			count, err := backtest.FailStaleRuns(ctx, store, timeout)
			if err != nil {
				logger.Error("stale backtest sweep failed", "error_type", fmt.Sprintf("%T", err))
				continue
			}
			if count > 0 {
				logger.Warn("marked stale backtests failed", "count", count)
			}
		}
	}
}
