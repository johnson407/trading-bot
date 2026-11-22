<?php

namespace App\Services\Backtest;

use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Exit\ExitRules;
use App\Domain\Risk\RiskLimitService;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

class BacktestSnapshotBuilder
{
    public function __construct(
        private readonly RiskLimitService $riskLimits,
        private readonly StrategyRegistry $strategies,
    ) {}

    /**
     * Build an immutable snapshot of everything the replay needs.
     *
     * @return array<string, mixed>
     */
    public function build(
        Bot $bot,
        Timeframe $timeframe,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
    ): array {
        $config = $bot->strategyConfig()->first();

        if ($config === null) {
            throw new RuntimeException('The bot has no strategy configuration to replay.');
        }

        if (! $this->strategies->has($bot->strategy_key)) {
            throw new RuntimeException("The bot strategy [{$bot->strategy_key}] is not available for replay.");
        }

        $limits = $this->riskLimits->effectiveForBot($bot);
        $exitRules = new ExitRules(
            $bot->stop_loss_percent === null ? null : Decimal::of($bot->stop_loss_percent)->toString(),
            $bot->take_profit_percent === null ? null : Decimal::of($bot->take_profit_percent)->toString(),
            $bot->range_low === null ? null : Decimal::of($bot->range_low)->toString(),
            $bot->range_high === null ? null : Decimal::of($bot->range_high)->toString(),
        );
        $utc = new DateTimeZone('UTC');

        return [
            'bot' => [
                'id' => $bot->id,
                'name' => $bot->name,
                'exchange' => $bot->exchange,
                'symbol' => $bot->symbol,
                'strategy_key' => $bot->strategy_key,
                'capital_allocation' => Decimal::of($bot->capital_allocation)->toString(),
                'fee_rate' => Decimal::of($bot->fee_rate ?? '0')->toString(),
            ],
            'strategy' => [
                'key' => $bot->strategy_key,
                'configuration' => $config->configuration ?? [],
            ],
            'risk' => [
                'max_position_notional' => $limits['max_position_notional']->toString(),
                'max_open_orders' => $limits['max_open_orders'],
                'daily_loss_limit' => $limits['daily_loss_limit']->toString(),
            ],
            'exit' => $exitRules->toArray(),
            'period' => [
                'timeframe' => $timeframe->value,
                'start_at' => $startAt->setTimezone($utc)->format(DATE_ATOM),
                'end_at' => $endAt->setTimezone($utc)->format(DATE_ATOM),
            ],
        ];
    }
}
