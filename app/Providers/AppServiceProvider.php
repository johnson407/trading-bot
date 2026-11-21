<?php

namespace App\Providers;

use App\Domain\Strategy\StrategyRegistry;
use App\Services\Exchange\ExchangeClientFactory;
use App\Strategies\SimpleGridStrategy;
use App\Strategies\TrailingGridStrategy;
use App\Strategies\MartingaleGridStrategy;
use App\Strategies\DynamicGridStrategy;
use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Policies\BotPolicy;
use App\Policies\ExchangeCredentialPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StrategyRegistry::class);
        $this->app->singleton(ExchangeClientFactory::class, fn () => new ExchangeClientFactory);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        RateLimiter::for('bot-control', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('kill-switch', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('exchange-credentials', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        $logger = Log::getLogger();

        if ($logger instanceof \Monolog\Logger) {
            $logger->pushProcessor(new \App\Logging\RedactSecrets);
        }

        Gate::policy(Bot::class, BotPolicy::class);
        Gate::policy(ExchangeCredential::class, ExchangeCredentialPolicy::class);

        $this->app->make(StrategyRegistry::class)->register(new SimpleGridStrategy);
        $this->app->make(StrategyRegistry::class)->register(new TrailingGridStrategy);
        $this->app->make(StrategyRegistry::class)->register(new MartingaleGridStrategy);
        $this->app->make(StrategyRegistry::class)->register(new DynamicGridStrategy);
    }
}
