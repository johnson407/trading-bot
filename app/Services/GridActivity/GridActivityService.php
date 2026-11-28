<?php

namespace App\Services\GridActivity;

use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Grid\Enums\GridStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\Fill;
use App\Models\Grid;
use App\Models\Order;
use App\Services\Exchange\CandleProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GridActivityService
{
    public function __construct(private readonly CandleProvider $candles) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Bot $bot, ?string $range): array
    {
        $config = config('trading.grid_activity');
        $ranges = array_keys($config['ranges']);
        $selected = in_array($range, $ranges, true)
            ? $range
            : (string) $config['default_range'];
        $rangeConfig = $config['ranges'][$selected];

        $end = Carbon::now();
        $start = $end->copy()->subSeconds((int) $rangeConfig['seconds']);
        $startTs = $start->getTimestamp();
        $endTs = $end->getTimestamp();

        $maxMarkers = max(1, (int) $config['max_markers']);
        $maxGrids = max(1, (int) $config['max_grids']);

        $gridRows = $this->overlappingGrids($bot, $start, $end)
            ->with('levels')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($maxGrids + 1)
            ->get();

        $gridsTruncated = $gridRows->count() > $maxGrids;
        $grids = $gridRows->take($maxGrids)
            ->map(fn (Grid $grid) => $this->presentGrid($grid, $bot, $startTs, $endTs))
            ->reverse()
            ->values();

        $fillsTruncated = false;
        $fills = Fill::query()
            ->where('bot_id', $bot->id)
            ->where('filled_at', '>=', $start)
            ->where('filled_at', '<', $end)
            ->orderByDesc('filled_at')
            ->orderByDesc('id')
            ->limit($maxMarkers + 1)
            ->get();
        $fillsTruncated = $fills->count() > $maxMarkers;
        $fills = $fills->take($maxMarkers)
            ->reverse()
            ->map(fn (Fill $fill) => [
                'id' => $fill->id,
                'side' => $fill->side->value,
                'price' => (string) $fill->price,
                'quantity' => (string) $fill->quantity,
                'filled_at' => $fill->filled_at->toIso8601String(),
            ])
            ->values();

        $cancelsTruncated = false;
        $cancels = Order::query()
            ->where('bot_id', $bot->id)
            ->where('status', OrderStatus::Canceled->value)
            ->where('closed_at', '>=', $start)
            ->where('closed_at', '<', $end)
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->limit($maxMarkers + 1)
            ->get();
        $cancelsTruncated = $cancels->count() > $maxMarkers;
        $cancels = $cancels->take($maxMarkers)
            ->reverse()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'side' => $order->side->value,
                'price' => (string) $order->price,
                'closed_at' => $order->closed_at->toIso8601String(),
            ])
            ->values();

        $fillCount = Fill::query()
            ->where('bot_id', $bot->id)
            ->where('filled_at', '>=', $start)
            ->where('filled_at', '<', $end)
            ->count();

        $cancelCount = Order::query()
            ->where('bot_id', $bot->id)
            ->where('status', OrderStatus::Canceled->value)
            ->where('closed_at', '>=', $start)
            ->where('closed_at', '<', $end)
            ->count();

        $revisionCount = $this->overlappingGrids($bot, $start, $end)->count();

        $median = DB::table('grids')
            ->where('bot_id', $bot->id)
            ->where('status', '!=', GridStatus::Active->value)
            ->where('updated_at', '>=', $start)
            ->where('updated_at', '<', $end)
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (updated_at - created_at))) AS median')
            ->value('median');

        $spacing = $this->spacing($bot);

        $price = $this->candles->get(
            $bot->exchange,
            $bot->symbol,
            (string) $rangeConfig['timeframe'],
            (int) $rangeConfig['limit'],
        );
        $price['timeframe'] = $rangeConfig['timeframe'];

        return [
            'range' => $selected,
            'available_ranges' => $ranges,
            'window_start' => $start->toIso8601String(),
            'window_end' => $end->toIso8601String(),
            'price' => $price,
            'grids' => $grids,
            'fills' => $fills,
            'cancels' => $cancels,
            'summary' => [
                'fill_count' => $fillCount,
                'cancel_count' => $cancelCount,
                'cancel_fill_ratio' => $fillCount === 0
                    ? null
                    : Decimal::of($cancelCount)->div($fillCount)->round(2)->toFixed(2),
                'grid_revisions' => $revisionCount,
                'median_lifetime_seconds' => $median === null ? null : (int) round((float) $median),
                'spacing' => $spacing['spacing'],
                'spacing_type' => $spacing['spacing_type'],
                'spacing_hysteresis_percent' => $spacing['spacing_hysteresis_percent'],
                'cancel_causes' => 'all',
            ],
            'truncated' => [
                'fills' => $fillsTruncated,
                'cancels' => $cancelsTruncated,
                'grids' => $gridsTruncated,
            ],
        ];
    }

    /**
     * @return Builder<Grid>
     */
    private function overlappingGrids(Bot $bot, Carbon $start, Carbon $end): Builder
    {
        return Grid::query()
            ->where('bot_id', $bot->id)
            ->where('created_at', '<', $end)
            ->where(function ($query) use ($start) {
                $query->where('status', GridStatus::Active->value)
                    ->orWhere('updated_at', '>', $start);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGrid(Grid $grid, Bot $bot, int $windowStart, int $windowEnd): array
    {
        $created = $grid->created_at->getTimestamp();
        $updated = $grid->updated_at->getTimestamp();
        $current = $grid->status === GridStatus::Active;
        $trading = $current && $bot->status === BotStatus::Running;

        $start = max($created, $windowStart);

        if (! $current) {
            $end = min($updated, $windowEnd);
        } elseif ($trading) {
            $end = $windowEnd;
        } else {
            $lastTick = $bot->last_successful_tick_at ?? $bot->last_tick_at;
            $end = min($windowEnd, $lastTick?->getTimestamp() ?? $created);
        }

        $end = max($start, $end);

        return [
            'id' => $grid->id,
            'center_price' => (string) $grid->center_price,
            'level_count' => $grid->level_count,
            'current' => $current,
            'trading' => $trading,
            'started_at' => Carbon::createFromTimestamp($start, 'UTC')->toIso8601String(),
            'ended_at' => Carbon::createFromTimestamp($end, 'UTC')->toIso8601String(),
            'levels' => $grid->levels->map(fn ($level) => [
                'sequence' => $level->sequence,
                'price' => (string) $level->price,
                'side' => $level->side->value,
                'state' => $level->state->value,
            ])->values(),
        ];
    }

    /**
     * @return array{spacing: ?string, spacing_type: ?string, spacing_hysteresis_percent: ?string}
     */
    private function spacing(Bot $bot): array
    {
        $strategyConfig = $bot->strategyConfig;
        $configuration = is_array($strategyConfig?->configuration) ? $strategyConfig->configuration : [];

        if ($bot->strategy_key !== StrategyRegistry::DYNAMIC_GRID) {
            return [
                'spacing' => isset($configuration['spacing_value'])
                    ? (string) $configuration['spacing_value']
                    : null,
                'spacing_type' => isset($configuration['spacing_type'])
                    ? (string) $configuration['spacing_type']
                    : null,
                'spacing_hysteresis_percent' => null,
            ];
        }

        $grid = Grid::query()
            ->where('bot_id', $bot->id)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [GridStatus::Active->value])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return [
            'spacing' => $grid?->spacing_value !== null ? (string) $grid->spacing_value : null,
            'spacing_type' => 'dynamic',
            'spacing_hysteresis_percent' => isset($configuration['spacing_hysteresis_percent'])
                ? (string) $configuration['spacing_hysteresis_percent']
                : '20',
        ];
    }
}
