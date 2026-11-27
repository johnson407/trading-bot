<?php

namespace App\Http\Resources\Concerns;

use App\Domain\Backtest\Enums\BacktestStatus;
use App\Domain\Strategy\StrategyRegistry;
use App\Models\BacktestRun;
use App\Services\Backtest\BacktestBotPrefill;
use Illuminate\Http\Request;

trait CanCreateBacktestBot
{
    private function canCreateBot(Request $request): bool
    {
        $run = $this->resource;
        $user = $request->user();

        if (! $run instanceof BacktestRun || $user === null) {
            return false;
        }

        if ($run->user_id !== $user->id || $run->status !== BacktestStatus::Completed) {
            return false;
        }

        $strategyKey = $run->strategy_key;

        if (! is_string($strategyKey) || ! app(StrategyRegistry::class)->has($strategyKey)) {
            return false;
        }

        $snapshot = $run->config_snapshot;

        if (! is_array($snapshot) || ! app(BacktestBotPrefill::class)->supports($snapshot)) {
            return false;
        }

        $bot = $snapshot['bot'] ?? null;

        if (! is_array($bot)) {
            return false;
        }

        $exchange = $bot['exchange'] ?? null;

        if (! is_string($exchange)
            || ! in_array($exchange, (array) config('trading.exchanges', []), true)) {
            return false;
        }

        $symbol = $bot['symbol'] ?? null;

        return is_string($symbol)
            && preg_match('/^[A-Z0-9]{2,12}\/[A-Z0-9]{2,12}$/', $symbol) === 1;
    }
}
