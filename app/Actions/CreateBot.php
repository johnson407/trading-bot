<?php

namespace App\Actions;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Risk\RiskLimitService;
use App\Models\Bot;
use App\Models\StrategyConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBot
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly RiskLimitService $riskLimits,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): Bot
    {
        return DB::transaction(function () use ($user, $data) {
            $mode = (string) ($data['mode'] ?? 'paper');
            $exchange = (string) ($data['exchange'] ?? config('trading.default_exchange', 'binance'));
            $credential = $user->exchangeCredentials()->where('exchange', $exchange)->first();

            $bot = Bot::create([
                'user_id' => $user->id,
                'exchange_credential_id' => $credential?->id,
                'name' => (string) $data['name'],
                'exchange' => $exchange,
                'symbol' => (string) $data['symbol'],
                'strategy_key' => (string) $data['strategy_key'],
                'mode' => $mode,
                'capital_allocation' => (string) $data['capital_allocation'],
                'fee_rate' => (string) ($data['fee_rate'] ?? config('trading.paper.fee_rate', '0')),
                'tick_interval_seconds' => max(1, (int) config('trading.tick.interval_seconds')),
                'settings' => [],
            ]);

            // Inventory, P&L, status, and failure counters are engine-owned
            // after creation; initialize them explicitly without making them
            // mass-assignable from request data.
            $bot->forceFill([
                'quote_inventory' => (string) $data['capital_allocation'],
                'base_inventory' => '0',
                'average_entry_price' => '0',
                'realized_pnl' => '0',
                'fees_paid' => '0',
            ])->save();

            StrategyConfig::create([
                'bot_id' => $bot->id,
                'strategy_key' => $bot->strategy_key,
                'configuration' => $data['configuration'],
                'state' => null,
                'state_version' => 1,
            ]);

            $this->riskLimits->forUser($user);
            $this->activity->recordUser('bot.created', "Bot {$bot->name} created", $bot, $user, [
                'mode' => $mode,
                'strategy' => $bot->strategy_key,
                'symbol' => $bot->symbol,
            ]);

            return $bot;
        });
    }
}
