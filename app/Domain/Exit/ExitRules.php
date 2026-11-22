<?php

namespace App\Domain\Exit;

use App\Domain\Support\Decimal;

final class ExitRules
{
    private readonly ?string $stopLossPercent;

    private readonly ?string $takeProfitPercent;

    private readonly ?string $rangeLow;

    private readonly ?string $rangeHigh;

    public function __construct(
        ?string $stopLossPercent = null,
        ?string $takeProfitPercent = null,
        ?string $rangeLow = null,
        ?string $rangeHigh = null,
    ) {
        $this->stopLossPercent = self::normalize($stopLossPercent);
        $this->takeProfitPercent = self::normalize($takeProfitPercent);
        $this->rangeLow = self::normalize($rangeLow);
        $this->rangeHigh = self::normalize($rangeHigh);
    }

    /**
     * Evaluate in deterministic precedence: stop-loss, take-profit, range.
     */
    public function evaluate(string $markPrice, string $averageEntryPrice, string $baseInventory): ?ExitSignal
    {
        $mark = Decimal::of($markPrice);
        $base = Decimal::of($baseInventory);
        $average = Decimal::of($averageEntryPrice);

        if ($base->isPositive() && $average->isPositive()) {
            if ($this->stopLossPercent !== null && Decimal::of($this->stopLossPercent)->isPositive()) {
                $threshold = $this->threshold($average, $this->stopLossPercent, subtract: true);

                if ($mark->lessThanOrEqual($threshold)) {
                    return new ExitSignal(
                        rule: ExitRule::StopLoss,
                        markPrice: $mark->toString(),
                        threshold: $threshold->toString(),
                        averageEntryPrice: $average->toString(),
                        baseInventory: $base->toString(),
                    );
                }
            }

            if ($this->takeProfitPercent !== null && Decimal::of($this->takeProfitPercent)->isPositive()) {
                $threshold = $this->threshold($average, $this->takeProfitPercent, subtract: false);

                if ($mark->greaterThanOrEqual($threshold)) {
                    return new ExitSignal(
                        rule: ExitRule::TakeProfit,
                        markPrice: $mark->toString(),
                        threshold: $threshold->toString(),
                        averageEntryPrice: $average->toString(),
                        baseInventory: $base->toString(),
                    );
                }
            }
        }

        $low = $this->rangeLow === null ? null : Decimal::of($this->rangeLow);
        $high = $this->rangeHigh === null ? null : Decimal::of($this->rangeHigh);

        if ($low !== null && $low->isPositive() && $mark->lessThanOrEqual($low)) {
            return new ExitSignal(
                rule: ExitRule::RangeExit,
                markPrice: $mark->toString(),
                threshold: $low->toString(),
                averageEntryPrice: $average->toString(),
                baseInventory: $base->toString(),
                bound: 'low',
            );
        }

        if ($high !== null && $high->isPositive() && $mark->greaterThanOrEqual($high)) {
            return new ExitSignal(
                rule: ExitRule::RangeExit,
                markPrice: $mark->toString(),
                threshold: $high->toString(),
                averageEntryPrice: $average->toString(),
                baseInventory: $base->toString(),
                bound: 'high',
            );
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->stopLossPercent === null
            && $this->takeProfitPercent === null
            && $this->rangeLow === null
            && $this->rangeHigh === null;
    }

    /**
     * @return array{stop_loss_percent: ?string, take_profit_percent: ?string, range_low: ?string, range_high: ?string}
     */
    public function toArray(): array
    {
        return [
            'stop_loss_percent' => $this->stopLossPercent,
            'take_profit_percent' => $this->takeProfitPercent,
            'range_low' => $this->rangeLow,
            'range_high' => $this->rangeHigh,
        ];
    }

    private function threshold(Decimal $average, string $percent, bool $subtract): Decimal
    {
        $ratio = Decimal::of($percent)->div('100');

        return $average->mul(
            $subtract ? Decimal::of('1')->sub($ratio) : Decimal::of('1')->add($ratio),
        );
    }

    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
