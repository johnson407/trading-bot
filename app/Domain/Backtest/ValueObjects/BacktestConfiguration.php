<?php

namespace App\Domain\Backtest\ValueObjects;

use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Exit\ExitRules;
use App\Domain\Support\Decimal;
use DateTimeImmutable;
use DateTimeZone;

final readonly class BacktestConfiguration
{
    public ExitRules $exitRules;

    /** @param array<string, mixed> $strategyConfiguration */
    public function __construct(
        public int $botId,
        public string $botName,
        public string $exchange,
        public string $symbol,
        public string $strategyKey,
        public Timeframe $timeframe,
        public DateTimeImmutable $startAt,
        public DateTimeImmutable $endAt,
        public Decimal $capitalAllocation,
        public Decimal $feeRate,
        public array $strategyConfiguration,
        public BacktestRiskLimits $riskLimits,
        ?ExitRules $exitRules = null,
    ) {
        $this->exitRules = $exitRules ?? new ExitRules();
    }

    /**
     * Rebuild a run configuration from its immutable JSON snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $bot = is_array($snapshot['bot'] ?? null) ? $snapshot['bot'] : [];
        $strategy = is_array($snapshot['strategy'] ?? null) ? $snapshot['strategy'] : [];
        $period = is_array($snapshot['period'] ?? null) ? $snapshot['period'] : [];
        $risk = is_array($snapshot['risk'] ?? null) ? $snapshot['risk'] : [];
        $exit = is_array($snapshot['exit'] ?? null) ? $snapshot['exit'] : [];

        return new self(
            botId: (int) ($bot['id'] ?? 0),
            botName: (string) ($bot['name'] ?? 'Deleted bot'),
            exchange: (string) ($bot['exchange'] ?? 'binance'),
            symbol: (string) ($bot['symbol'] ?? 'BTC/USDT'),
            strategyKey: (string) ($strategy['key'] ?? $bot['strategy_key'] ?? 'simple-grid'),
            timeframe: Timeframe::fromConfig((string) ($period['timeframe'] ?? config('backtest.default_timeframe', '15m'))),
            startAt: self::time((string) ($period['start_at'] ?? 'now')),
            endAt: self::time((string) ($period['end_at'] ?? 'now')),
            capitalAllocation: Decimal::of($bot['capital_allocation'] ?? '0'),
            feeRate: Decimal::of($bot['fee_rate'] ?? '0'),
            strategyConfiguration: is_array($strategy['configuration'] ?? null) ? $strategy['configuration'] : [],
            riskLimits: BacktestRiskLimits::fromArray($risk),
            exitRules: new ExitRules(
                $exit['stop_loss_percent'] ?? null,
                $exit['take_profit_percent'] ?? null,
                $exit['range_low'] ?? null,
                $exit['range_high'] ?? null,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function toSnapshot(): array
    {
        return [
            'bot' => [
                'id' => $this->botId,
                'name' => $this->botName,
                'exchange' => $this->exchange,
                'symbol' => $this->symbol,
                'strategy_key' => $this->strategyKey,
                'capital_allocation' => $this->capitalAllocation->toString(),
                'fee_rate' => $this->feeRate->toString(),
            ],
            'strategy' => [
                'key' => $this->strategyKey,
                'configuration' => $this->strategyConfiguration,
            ],
            'risk' => $this->riskLimits->toArray(),
            'exit' => $this->exitRules->toArray(),
            'period' => [
                'timeframe' => $this->timeframe->value,
                'start_at' => $this->startAt->format(DATE_ATOM),
                'end_at' => $this->endAt->format(DATE_ATOM),
            ],
        ];
    }

    private static function time(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}
