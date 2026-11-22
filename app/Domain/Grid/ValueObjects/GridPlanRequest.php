<?php

namespace App\Domain\Grid\ValueObjects;

use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Support\Decimal;

final readonly class GridPlanRequest
{
    /**
     * @param  array<string, mixed>  $configuration
     * @param  array{max_position_notional?: Decimal|string|int, max_open_orders?: int|string}|null  $limits
     */
    public function __construct(
        public string $strategyKey,
        public array $configuration,
        public string $symbol = 'PREVIEW/USDT',
        public Decimal|string $capitalAllocation = '0',
        public Decimal|string $baseInventory = '0',
        public Decimal|string|null $marketPrice = null,
        public ?array $limits = null,
        public ?MarketData $market = null,
    ) {}
}
