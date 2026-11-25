<?php

namespace App\Providers;

use App\Domain\Strategy\StrategyRegistry;
use App\Logging\RedactSecrets;
use App\Models\BacktestRun;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Policies\BacktestRunPolicy;
use App\Policies\BotPolicy;
use App\Policies\ExchangeCredentialPolicy;
use App\Services\Engine\CommandPublisher;
use App\Services\Engine\EngineHeartbeat;
use App\Services\Engine\RedisCommandPublisher;
use App\Services\Exchange\ExchangeClientFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StrategyRegistry::class);
        $this->app->singleton(ExchangeClientFactory::class, fn () => new ExchangeClientFactory);
        $this->app->bind(CommandPublisher::class, RedisCommandPublisher::class);
        $this->app->singleton(EngineHeartbeat::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        RateLimiter::for('bot-control', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        // Preview traffic is a convenience loop and must not consume the quota
        // that pause/stop rely on.
        RateLimiter::for('bot-preview', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('kill-switch', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('exchange-credentials', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('backtests', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        $logger = Log::getLogger();

        if ($logger instanceof Logger) {
            $logger->pushProcessor(new RedactSecrets);
        }

        Gate::policy(Bot::class, BotPolicy::class);
        Gate::policy(ExchangeCredential::class, ExchangeCredentialPolicy::class);
        Gate::policy(BacktestRun::class, BacktestRunPolicy::class);
    }
}
