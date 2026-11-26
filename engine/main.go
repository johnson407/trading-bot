// Command engine is the trading engine process. It runs the paper and gated
// live trading runtime (bot commands, due-check ticks, grids, paper/live fills,
// risk/exit guards), deterministic backtests, the heartbeat, and the internal
// setup-plan evaluator. Live placement stays closed unless TRADING_LIVE_ENABLED
// is true; a closed gate pauses live bots before any private placement call.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
	goredis "github.com/redis/go-redis/v9"

	"github.com/johnson407/trading-bot/engine/internal/backtest"
	"github.com/johnson407/trading-bot/engine/internal/config"
	"github.com/johnson407/trading-bot/engine/internal/consumer"
	"github.com/johnson407/trading-bot/engine/internal/exchange"
	"github.com/johnson407/trading-bot/engine/internal/health"
	"github.com/johnson407/trading-bot/engine/internal/healthcheck"
	"github.com/johnson407/trading-bot/engine/internal/heartbeat"
	"github.com/johnson407/trading-bot/engine/internal/idempotency"
	"github.com/johnson407/trading-bot/engine/internal/marketdata"
	"github.com/johnson407/trading-bot/engine/internal/plan"
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

	if len(os.Args) > 1 && os.Args[1] == "plan-server" {
		if err := runPlanServer(); err != nil {
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
		"exchange_sandbox", cfg.TradingExchangeSandbox,
		"trading_paths", "paper trading, backtests, and gated live execution",
	)

	if cfg.LiveTrading {
		logger.Warn("TRADING_LIVE_ENABLED is true; live bots still require a venue client and per-order risk checks")
	}

	pool, err := pgxpool.New(context.Background(), cfg.Database.DSN())
	if err != nil {
		return fmt.Errorf("configure postgres pool: %w", err)
	}
	defer pool.Close()

	schemaContext, cancelSchemaWait := context.WithTimeout(context.Background(), 60*time.Second)
	err = runtime.WaitForPauseProvenance(schemaContext, pool, logger)
	cancelSchemaWait()
	if err != nil {
		return fmt.Errorf("pause provenance schema: %w", err)
	}

	redisClient := goredis.NewClient(&goredis.Options{
		Addr:     cfg.Redis.Addr(),
		Password: cfg.Redis.Password,
		DB:       cfg.Redis.DB,
	})
	defer func() { _ = redisClient.Close() }()

	engineContext, stopEngine := context.WithCancel(context.Background())
	var workers sync.WaitGroup
	var drained bool
	startWorker := func(run func()) {
		workers.Add(1)
		go func() {
			defer workers.Done()
			run()
		}()
	}

	// Every exit path stops the engine before the database and Redis pools
	// close. The bounded wait keeps a wedged worker from holding the process
	// past Docker's stop grace period.
	defer func() {
		stopEngine()
		if drained {
			return
		}
		drainContext, cancel := context.WithTimeout(context.Background(), shutdownDrainTimeout)
		defer cancel()
		if !drainWait(drainContext, &workers) {
			logger.Warn("engine drain timed out", "timeout", shutdownDrainTimeout.String())
		}
	}()

	publicREST := newPublicREST(cfg.MarketData.RESTURL, cfg.MarketData.RequestTimeout)

	// The feed is the tick price source. It stays idle until a running bot (or
	// the optional ENGINE_MARKET_DATA_SYMBOLS watchlist) needs a symbol, and it
	// never uses credentials.
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

	prices := marketdata.NewPriceSource(
		marketFeed,
		publicREST,
		"binance",
		cfg.MarketData.StaleAfter,
		logger,
	)

	credentialLoader := exchange.CredentialLoader{
		Pool:         pool,
		Key:          cfg.AppKey,
		PreviousKeys: cfg.AppPreviousKeys,
	}
	exchangeFactory := exchange.Factory{
		BaseURL: cfg.TradingExchangeBaseURL,
		Sandbox: cfg.TradingExchangeSandbox,
	}

	tradingRuntime := runtime.New(
		pool,
		prices,
		logger,
		runtime.WithLiveTrading(cfg.LiveTrading),
		runtime.WithOrderSyncInterval(cfg.TradingOrderSync),
		runtime.WithLiveClients(func(ctx context.Context, bot *runtime.Bot) (exchange.Client, error) {
			credential, err := credentialLoader.Load(ctx, bot.ID)
			if err != nil {
				return nil, err
			}

			client, err := exchangeFactory.Client(bot.Exchange, credential)
			if err != nil {
				return nil, err
			}

			return exchange.GuardedClient{Enabled: cfg.LiveTrading, Client: client}, nil
		}),
	)

	heartbeatWriter := heartbeat.NewWriter(redisHeartbeatSetter{client: redisClient})

	// Write once before serving so readiness does not race the first refresh.
	if err := heartbeatWriter.Write(engineContext, tradingRuntime.RunningCount(engineContext)); err != nil {
		logger.Warn("initial heartbeat write failed", "error_type", fmt.Sprintf("%T", err))
	}

	startWorker(func() {
		heartbeatWriter.Run(engineContext, heartbeat.RefreshInterval, func() int {
			return tradingRuntime.RunningCount(engineContext)
		})
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

	startWorker(func() {
		if err := commandConsumer.Run(engineContext); err != nil {
			logger.Error("command consumer stopped", "error_type", fmt.Sprintf("%T", err))
		}
	})

	startWorker(func() {
		if err := backtestConsumer.Run(engineContext); err != nil {
			logger.Error("backtest consumer stopped", "error_type", fmt.Sprintf("%T", err))
		}
	})

	startWorker(func() { runStaleBacktestSweeper(engineContext, backtestStore, cfg.BacktestJobTimeout, logger) })
	startWorker(func() { tradingRuntime.RunScheduler(engineContext) })

	// The feed subscribes to the union of running/starting Binance bots'
	// symbols, plus any operator-pinned ENGINE_MARKET_DATA_SYMBOLS. It stays
	// idle unless both public endpoints are configured; ticks then fall back to
	// one public REST snapshot per evaluation.
	if cfg.MarketData.Configured() {
		startWorker(func() {
			if err := marketFeed.Run(engineContext); err != nil {
				logger.Error("market data feed stopped", "error_type", fmt.Sprintf("%T", err))
			}
		})
		startWorker(func() {
			runMarketSymbols(engineContext, tradingRuntime, marketFeed, cfg.MarketData.Symbols, logger)
		})
		logger.Info("market data enabled for running bots",
			"pinned_symbols", len(cfg.MarketData.Symbols),
			"stale_after", cfg.MarketData.StaleAfter.String(),
		)
	} else {
		logger.Info("market data feed idle: public endpoints are not configured; ticks use one public REST snapshot")
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

	mux := http.NewServeMux()
	mux.Handle("/", health.NewRouter(checks, logger))
	mux.Handle("POST /v1/plan/evaluate", plan.NewHandler(cfg.InternalToken, logger))
	warnPlanEndpointExposure(logger, cfg.Addr, cfg.InternalToken)

	server := &http.Server{
		Addr:              cfg.Addr,
		Handler:           mux,
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

	// Stop starting new work first, then drain HTTP and tracked background
	// workers together within one bounded window.
	stopEngine()

	// HTTP shutdown and the worker drain share one absolute budget, so a slow
	// HTTP shutdown cannot leave a second full timeout for the deferred path.
	shutdownContext, cancel := context.WithTimeout(context.Background(), shutdownDrainTimeout)
	defer cancel()

	if err := server.Shutdown(shutdownContext); err != nil {
		logger.Warn("http server shutdown did not complete", "error", err)
	}
	if !drainWait(shutdownContext, &workers) {
		logger.Warn("engine drain timed out", "timeout", shutdownDrainTimeout.String())
	}
	drained = true

	return nil
}

// shutdownDrainTimeout bounds how long the engine waits for in-flight ticks,
// consumers, heartbeat writes, market-data work, and the backtest sweeper. The
// Compose stop grace period is longer so Docker does not kill the drain.
const shutdownDrainTimeout = 20 * time.Second

// drainWait waits for the workers to finish or ctx to expire. It reports
// whether the drain completed before the deadline.
func drainWait(ctx context.Context, workers *sync.WaitGroup) bool {
	done := make(chan struct{})
	go func() {
		workers.Wait()
		close(done)
	}()

	select {
	case <-done:
		return true
	case <-ctx.Done():
		return false
	}
}

// runPlanServer serves only the internal setup-plan endpoint. It opens no
// database, Redis, or exchange connection, which makes it suitable for Laravel
// contract tests that need the real Go evaluator without a full stack.
func runPlanServer() error {
	cfg, err := config.Load(os.Getenv)
	if err != nil {
		return fmt.Errorf("load config: %w", err)
	}

	logger := slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))

	mux := http.NewServeMux()
	mux.Handle("POST /v1/plan/evaluate", plan.NewHandler(cfg.InternalToken, logger))
	mux.HandleFunc("GET /livez", health.Live)

	server := &http.Server{
		Addr:              cfg.Addr,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}

	logger.Info("starting plan server", "addr", cfg.Addr, "token_required", cfg.InternalToken != "")
	warnPlanEndpointExposure(logger, cfg.Addr, cfg.InternalToken)

	serverErrors := make(chan error, 1)
	go func() { serverErrors <- server.ListenAndServe() }()

	signals := make(chan os.Signal, 1)
	signal.Notify(signals, syscall.SIGINT, syscall.SIGTERM)

	select {
	case err := <-serverErrors:
		if !errors.Is(err, http.ErrServerClosed) {
			return fmt.Errorf("serve: %w", err)
		}
	case <-signals:
	}

	shutdownContext, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	return server.Shutdown(shutdownContext)
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

// commandProcessor routes v1 commands. Backtests run in the dedicated consumer;
// bot control commands go to the runtime; unknown commands are rejected
// definitively so Redis does not retry a handler this process does not have.
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

// newPublicREST returns the public ticker client used for cache misses and
// venues without a WebSocket. It never uses credentials.
func newPublicREST(baseURL string, timeout time.Duration) *marketdata.RESTClient {
	if baseURL == "" {
		baseURL = "https://api.binance.com"
	}
	if timeout <= 0 {
		timeout = 10 * time.Second
	}

	return &marketdata.RESTClient{
		BaseURL:    baseURL,
		HTTPClient: &http.Client{Timeout: timeout},
	}
}

// marketSymbolRefresh is how often the feed's subscription set is reconciled
// with running/starting bots. Start and stop commands also become visible
// through the runtime's normal desired-status reconciliation.
const marketSymbolRefresh = 2 * time.Second

// runMarketSymbols keeps the public feed subscribed to exactly the symbols of
// running/starting Binance bots, plus an optional operator-pinned list.
func runMarketSymbols(ctx context.Context, trading *runtime.Runtime, feed *marketdata.Feed, pinned []string, logger *slog.Logger) {
	sync := func() {
		symbols, err := trading.FeedSymbols(ctx)
		if err != nil {
			logger.Error("refresh market-data symbols failed", "error_type", fmt.Sprintf("%T", err))
			return
		}

		feed.SetSymbols(append(symbols, pinned...))
	}

	sync()

	ticker := time.NewTicker(marketSymbolRefresh)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			sync()
		}
	}
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

// warnPlanEndpointExposure flags the one deployment mistake the engine cannot
// detect directly: publishing its HTTP port beyond the internal network while
// leaving the shared plan token empty. Compose keeps the port internal, so this
// stays a warning rather than breaking the default development stack.
func warnPlanEndpointExposure(logger *slog.Logger, addr, token string) {
	if token != "" {
		return
	}

	host, _, err := net.SplitHostPort(addr)
	if err != nil {
		host = addr
	}

	switch host {
	case "", "0.0.0.0", "::", "[::]":
		logger.Warn(
			"engine HTTP port is bound beyond loopback without ENGINE_INTERNAL_TOKEN; publish it only on a trusted internal network",
			"addr", addr,
		)
	case "127.0.0.1", "localhost", "::1":
		return
	}
}
