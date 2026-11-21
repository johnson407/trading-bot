<?php

namespace App\Domain\Backtest\Engine;

use App\Domain\Backtest\ValueObjects\BacktestMetrics;
use App\Domain\Backtest\ValueObjects\EquityPoint;
use App\Domain\Backtest\ValueObjects\SimulatedFill;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Support\Decimal;

final class MetricsCalculator
{
    /**
     * @param  list<EquityPoint>  $equity
     * @param  list<SimulatedFill>  $fills
     */
    public function calculate(
        InMemoryPortfolio $portfolio,
        array $equity,
        array $fills,
        Decimal $capital,
        Decimal $lastPrice,
        int $candlesProcessed,
        string $endReason,
        int $durationMs = 0,
        ?int $maxPoints = null,
    ): BacktestMetrics {
        $finalEquity = $equity === []
            ? $portfolio->equity($lastPrice)
            : $equity[array_key_last($equity)]->equity;
        $net = $finalEquity->sub($capital);
        $returnPercent = $capital->isZero()
            ? Decimal::zero()
            : $net->div($capital)->mul('100');

        [$drawdown, $drawdownPercent] = $this->drawdown($equity);

        $buyCount = count(array_filter(
            $fills,
            fn (SimulatedFill $fill) => $fill->side === OrderSide::Buy,
        ));

        return new BacktestMetrics(
            endReason: $endReason,
            finalEquity: $finalEquity,
            netPnl: $net,
            realizedPnl: $portfolio->realized,
            unrealizedPnl: $portfolio->unrealized($lastPrice),
            fees: $portfolio->fees,
            returnPercent: $returnPercent,
            maxDrawdown: $drawdown,
            maxDrawdownPercent: $drawdownPercent,
            finalBaseInventory: $portfolio->base,
            finalQuoteInventory: $portfolio->quote,
            finalPrice: $lastPrice,
            fillCount: count($fills),
            buyCount: $buyCount,
            sellCount: count($fills) - $buyCount,
            candlesProcessed: $candlesProcessed,
            durationMs: $durationMs,
        );
    }

    /**
     * Downsample without dropping the first or last observation.
     *
     * @param  list<EquityPoint>  $equity
     * @return list<EquityPoint>
     */
    public function downsample(array $equity, ?int $maxPoints = null): array
    {
        $maxPoints = $maxPoints ?? (int) config('backtest.equity_points', 1_000);
        $count = count($equity);

        if ($maxPoints < 2 || $count <= $maxPoints) {
            return $equity;
        }

        $sampled = [];

        for ($i = 0; $i < $maxPoints; $i++) {
            $index = (int) round($i * ($count - 1) / ($maxPoints - 1));
            $sampled[$index] = $equity[$index];
        }

        ksort($sampled);

        return array_values($sampled);
    }

    /**
     * @param  list<EquityPoint>  $equity
     * @return array{0: Decimal, 1: Decimal}
     */
    private function drawdown(array $equity): array
    {
        if ($equity === []) {
            return [Decimal::zero(), Decimal::zero()];
        }

        $peak = $equity[0]->equity;
        $worst = Decimal::zero();
        $worstPercent = Decimal::zero();

        foreach ($equity as $point) {
            if ($point->equity->greaterThan($peak)) {
                $peak = $point->equity;
            }

            $drawdown = $peak->sub($point->equity);

            if ($drawdown->greaterThan($worst)) {
                $worst = $drawdown;
                $worstPercent = $peak->isPositive()
                    ? $drawdown->div($peak)->mul('100')
                    : Decimal::zero();
            }
        }

        return [$worst, $worstPercent];
    }
}
