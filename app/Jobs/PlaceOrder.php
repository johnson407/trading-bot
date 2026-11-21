<?php

namespace App\Jobs;

use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\OrderPlacementService;
use App\Models\Bot;
use App\Models\Grid;
use App\Models\GridLevel;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PlaceOrder implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 30;

    public function __construct(
        public readonly int $botId,
        public readonly int $gridId,
        public readonly int $gridLevelId,
        public readonly string $side,
        public readonly string $price,
        public readonly string $quantity,
        public readonly int $cycle = 1,
    ) {}

    public function uniqueId(): string
    {
        return 'place-order:'.$this->botId.':'.$this->gridLevelId.':'.$this->cycle;
    }

    public function handle(OrderPlacementService $placement): void
    {
        $bot = Bot::query()->find($this->botId);
        $grid = Grid::query()->find($this->gridId);
        $level = GridLevel::query()->find($this->gridLevelId);

        if (! $bot instanceof Bot || ! $grid instanceof Grid || ! $level instanceof GridLevel) {
            return;
        }

        $placement->request(
            $bot,
            $grid,
            $level,
            OrderSide::from($this->side),
            $this->price,
            $this->quantity,
            $this->cycle,
        );
    }
}
