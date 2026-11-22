<?php

namespace App\Domain\Grid\ValueObjects;

use App\Domain\Grid\Enums\GridPlanProblemSeverity;
use App\Domain\Support\Decimal;

final readonly class GridPlan
{
    /**
     * @param  list<GridPlanLevel>  $levels
     * @param  list<GridPlanProblem>  $problems
     */
    public function __construct(
        public string $strategyKey,
        public array $levels,
        public array $problems,
        public Decimal $centerPrice,
        public Decimal $orderQuantity,
        public string $spacingType,
        public Decimal $spacingValue,
        public int $levelsPerSide,
        public int $orderCount,
        public Decimal $buyNotional,
        public Decimal $sellNotional,
    ) {}

    /** @return list<GridPlanProblem> */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->problems,
            fn (GridPlanProblem $problem) => $problem->severity === GridPlanProblemSeverity::Error,
        ));
    }

    /** @return list<GridPlanProblem> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->problems,
            fn (GridPlanProblem $problem) => $problem->severity === GridPlanProblemSeverity::Warning,
        ));
    }

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strategy_key' => $this->strategyKey,
            'center_price' => $this->centerPrice->toString(),
            'order_quantity' => $this->orderQuantity->toString(),
            'spacing_type' => $this->spacingType,
            'spacing_value' => $this->spacingValue->toString(),
            'levels_per_side' => $this->levelsPerSide,
            'order_count' => $this->orderCount,
            'buy_notional' => $this->buyNotional->toString(),
            'sell_notional' => $this->sellNotional->toString(),
            'levels' => array_map(fn (GridPlanLevel $level) => $level->toArray(), $this->levels),
            'errors' => array_map(fn (GridPlanProblem $problem) => $problem->toArray(), $this->errors()),
            'warnings' => array_map(fn (GridPlanProblem $problem) => $problem->toArray(), $this->warnings()),
        ];
    }
}
