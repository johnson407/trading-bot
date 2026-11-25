<?php

namespace App\Domain\Grid\ValueObjects;

use App\Domain\Grid\Enums\GridPlanProblemSeverity;
use App\Domain\Order\Enums\OrderSide;
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

    /**
     * Hydrate a plan returned by the Go evaluator. The engine owns the ladder
     * and problem calculation; Laravel only maps the contract back to value
     * objects for previews and validation.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $levels = [];

        foreach ((array) ($data['levels'] ?? []) as $level) {
            $levels[] = new GridPlanLevel(
                sequence: (int) $level['sequence'],
                side: OrderSide::from((string) $level['side']),
                price: Decimal::of($level['price']),
                quantity: Decimal::of($level['quantity']),
                notional: Decimal::of($level['notional']),
            );
        }

        $problems = [];

        foreach ([...(array) ($data['errors'] ?? []), ...(array) ($data['warnings'] ?? [])] as $problem) {
            $problems[] = new GridPlanProblem(
                code: (string) $problem['code'],
                field: isset($problem['field']) ? (string) $problem['field'] : null,
                message: (string) $problem['message'],
                severity: GridPlanProblemSeverity::from((string) ($problem['severity'] ?? 'error')),
            );
        }

        return new self(
            strategyKey: (string) $data['strategy_key'],
            levels: $levels,
            problems: $problems,
            centerPrice: Decimal::of($data['center_price'] ?? '0'),
            orderQuantity: Decimal::of($data['order_quantity'] ?? '0'),
            spacingType: (string) ($data['spacing_type'] ?? 'arithmetic'),
            spacingValue: Decimal::of($data['spacing_value'] ?? '0'),
            levelsPerSide: (int) ($data['levels_per_side'] ?? 0),
            orderCount: (int) ($data['order_count'] ?? 0),
            buyNotional: Decimal::of($data['buy_notional'] ?? '0'),
            sellNotional: Decimal::of($data['sell_notional'] ?? '0'),
        );
    }

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
