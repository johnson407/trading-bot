<?php

namespace Tests\Support;

use App\Domain\Bot\BotHealthMonitor;
use App\Domain\Bot\BotTickPipeline;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Bot\ValueObjects\TickResult;
use App\Domain\Exchange\ValueObjects\MarketData;
use App\Domain\Grid\Enums\GridSpacingType;
use App\Domain\Grid\GridDerivation;
use App\Domain\Grid\GridGenerator;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderType;
use App\Domain\Strategy\ValueObjects\BotSnapshot;
use App\Domain\Strategy\ValueObjects\CancelEvent;
use App\Domain\Strategy\ValueObjects\FillEvent;
use App\Domain\Strategy\ValueObjects\GridLevelPlan;
use App\Domain\Strategy\ValueObjects\MarketSnapshot;
use App\Domain\Strategy\ValueObjects\OpenOrderSnapshot;
use App\Domain\Strategy\ValueObjects\OrderIntent;
use App\Domain\Strategy\ValueObjects\StrategyConfiguration;
use App\Domain\Strategy\ValueObjects\StrategyDecision;
use App\Domain\Strategy\ValueObjects\StrategyInput;
use App\Domain\Strategy\ValueObjects\StrategyState;
use App\Domain\Support\Decimal;
use App\Jobs\RunBotTick;
use App\Models\Bot;
use App\Models\Grid;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use App\Strategies\DynamicGridStrategy;
use App\Strategies\MartingaleGridStrategy;
use App\Strategies\SimpleGridStrategy;
use App\Strategies\TrailingGridStrategy;
use DateTimeImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\FakeExchangeClient;
use Throwable;

/**
 * Captures deterministic golden fixtures from the current committed PHP engine.
 *
 * The fixtures are a behavioural snapshot for the later Go ports. Nothing in
 * this class changes engine behaviour; it only calls the production classes.
 */
final class GoldenEngineFixtures
{
    public const FORMAT = 'php-golden-engine/1';

    public const CLOCK = '2026-11-23T00:00:00+00:00';

    public const FEE_RATE = '0.001';

    /** @var list<string> */
    public const FILES = [
        'tick_loop',
        'grid_generation',
        'grid_sync',
        'paper_fills',
        'strategies',
    ];

    /** @return array<string, array<string, mixed>> */
    public function capture(): array
    {
        return [
            'tick_loop' => $this->tickLoop(),
            'grid_generation' => $this->gridGeneration(),
            'grid_sync' => $this->gridSync(),
            'paper_fills' => $this->paperFills(),
            'strategies' => $this->strategies(),
        ];
    }

    public static function path(string $name): string
    {
        return base_path('tests/golden/engine/'.$name.'.json');
    }

    /** @param array<string, array<string, mixed>> $fixtures */
    public function write(array $fixtures): void
    {
        if (! is_dir(dirname(self::path('x')))) {
            mkdir(dirname(self::path('x')), 0777, true);
        }

        foreach ($fixtures as $name => $data) {
            $path = self::path($name);
            $encoded = self::encode($data);
            $temporary = $path.'.tmp.'.getmypid();

            if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
                throw new \RuntimeException("Unable to write golden fixture [{$temporary}].");
            }

            @chmod($temporary, 0666);

            if (! rename($temporary, $path)) {
                @unlink($temporary);

                throw new \RuntimeException("Unable to replace golden fixture [{$path}].");
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function encode(array $data): string
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        return $json."\n";
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return [
            'format' => self::FORMAT,
            'clock' => self::CLOCK,
            'decimal_scale' => Decimal::SCALE,
            'fee_rate' => self::FEE_RATE,
        ];
    }

    /** @return array<string, mixed> */
    private function tickLoop(): array
    {
        $cases = [
            $this->dispatchCase('default_60_fresh', null, 60),
            $this->dispatchCase('default_60_exactly_due', 60, 60),
            $this->dispatchCase('default_60_not_due', 59, 60),
            $this->dispatchCase('minimum_1_same_second', 0, 1),
            $this->dispatchCase('minimum_1_after_one_second', 1, 1),
            $this->dispatchCase('interval_0_is_clamped_to_1_same_second', 0, 0),
            $this->dispatchCase('interval_0_is_clamped_to_1_after_one_second', 1, 0),
            $this->dispatchCase('paused_skipped', null, 60, BotStatus::Paused),
            $this->dispatchCase('stopped_skipped', null, 60, BotStatus::Stopped),
        ];

        $originalStale = config('trading.tick.stale_after_seconds');
        $originalHealth = config('trading.health.check_interval_seconds');

        try {
            $healthCases = [
                $this->healthCase('default_60_healthy_at_179s', 60, 179, 180, 30),
                $this->healthCase('default_60_stale_at_181s', 60, 181, 180, 30),
                $this->healthCase('interval_300_healthy_at_329s', 300, 329, 180, 30),
                $this->healthCase('interval_300_stale_at_331s', 300, 331, 180, 30),
                $this->healthCase('interval_0_threshold_is_31s_healthy_at_30s', 0, 30, 10, 30),
                $this->healthCase('interval_0_threshold_is_31s_stale_at_32s', 0, 32, 10, 30),
                $this->healthCase('last_successful_tick_takes_precedence', 60, 10, 180, 30, [
                    'last_tick_at' => now()->subSeconds(500),
                    'last_successful_tick_at' => now()->subSeconds(10),
                ]),
                $this->healthCase('created_at_is_the_fallback', 60, 181, 180, 30, [
                    'last_tick_at' => null,
                    'last_successful_tick_at' => null,
                    'created_at' => now()->subSeconds(181),
                ]),
            ];
        } finally {
            config([
                'trading.tick.stale_after_seconds' => $originalStale,
                'trading.health.check_interval_seconds' => $originalHealth,
            ]);
        }

        $job = new RunBotTick(42);
        /** @var WithoutOverlapping $middleware */
        $middleware = $job->middleware()[0];

        return $this->metadata() + [
            'description' => 'Current bots:dispatch due-check, dispatch cache guard, RunBotTick uniqueness/overlap, and BotHealthMonitor stale threshold.',
            'dispatch_due_check' => [
                'cases' => $cases,
            ],
            'dispatch_cache_guard' => $this->dispatchCacheGuard(),
            'run_bot_tick_job' => [
                'tries' => $job->tries,
                'unique_for' => $job->uniqueFor,
                'unique_id' => $job->uniqueId(),
                'overlap' => [
                    'middleware' => $middleware::class,
                    'key' => $middleware->key,
                    'expire_after_seconds' => $middleware->expiresAfter,
                    'release_after_seconds' => $middleware->releaseAfter,
                ],
            ],
            'health' => [
                'cases' => $healthCases,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dispatchCase(
        string $label,
        ?int $lastTickAgeSeconds,
        int $tickIntervalSeconds,
        BotStatus $status = BotStatus::Running,
    ): array {
        Bot::query()->update(['status' => BotStatus::Paused->value]);
        Cache::flush();
        Queue::fake();

        $bot = Bot::factory()->create([
            'status' => $status,
            'tick_interval_seconds' => $tickIntervalSeconds,
            'last_tick_at' => $lastTickAgeSeconds === null ? null : now()->subSeconds($lastTickAgeSeconds),
        ]);

        $exitCode = Artisan::call('bots:dispatch');

        return [
            'label' => $label,
            'status' => $status->value,
            'tick_interval_seconds' => $tickIntervalSeconds,
            'last_tick_age_seconds' => $lastTickAgeSeconds,
            'dispatched' => Queue::pushed(RunBotTick::class)->count(),
            'exit_code' => $exitCode,
        ];
    }

    /** @return array<string, mixed> */
    private function dispatchCacheGuard(): array
    {
        Bot::query()->update(['status' => BotStatus::Paused->value]);
        Cache::flush();
        Queue::fake();

        $bot = Bot::factory()->running()->create([
            'tick_interval_seconds' => 60,
            'last_tick_at' => null,
        ]);

        Artisan::call('bots:dispatch');
        $first = Queue::pushed(RunBotTick::class)->count();

        // Release the unique-job lock so this fixture isolates the dispatcher's
        // own per-interval cache guard.
        (new UniqueLock(Cache::store()))->release(new RunBotTick($bot->id));

        Artisan::call('bots:dispatch');
        $second = Queue::pushed(RunBotTick::class)->count();

        Cache::forget('bot-dispatch:'.$bot->id);
        Artisan::call('bots:dispatch');
        $third = Queue::pushed(RunBotTick::class)->count();

        return [
            'first_dispatch' => $first,
            'second_dispatch_inside_interval' => $second,
            'after_cache_forget' => $third,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function healthCase(
        string $label,
        int $tickIntervalSeconds,
        int $ageSeconds,
        int $staleAfterSeconds,
        int $healthIntervalSeconds,
        array $overrides = [],
    ): array {
        Bot::query()->update(['status' => BotStatus::Paused->value]);
        config([
            'trading.tick.stale_after_seconds' => $staleAfterSeconds,
            'trading.health.check_interval_seconds' => $healthIntervalSeconds,
        ]);

        $bot = Bot::factory()->running()->create(array_replace([
            'tick_interval_seconds' => $tickIntervalSeconds,
            'last_tick_at' => now()->subSeconds($ageSeconds),
            'last_successful_tick_at' => now()->subSeconds($ageSeconds),
        ], $overrides));

        $paused = app(BotHealthMonitor::class)->check();

        return [
            'label' => $label,
            'tick_interval_seconds' => $tickIntervalSeconds,
            'stale_after_seconds' => $staleAfterSeconds,
            'health_interval_seconds' => $healthIntervalSeconds,
            'age_seconds' => $ageSeconds,
            'paused_count' => count($paused),
            'paused' => $bot->fresh()->status === BotStatus::Paused,
        ];
    }

    /** @return array<string, mixed> */
    private function gridGeneration(): array
    {
        $generator = [
            $this->generatorCase('arithmetic_center_100_spacing_10_per_side_2', '100', GridSpacingType::Arithmetic, '10', 2),
            $this->generatorCase('geometric_center_100_spacing_0_1_per_side_2', '100', GridSpacingType::Geometric, '0.1', 2),
            $this->generatorCase('dynamic_uses_arithmetic_ladder', '100', GridSpacingType::Dynamic, '7.5', 1),
            $this->generatorCase('arithmetic_single_level_per_side', '100', GridSpacingType::Arithmetic, '10', 1),
            $this->generatorCase('reject_zero_center', '0', GridSpacingType::Arithmetic, '10', 1),
            $this->generatorCase('reject_negative_center', '-100', GridSpacingType::Arithmetic, '10', 1),
            $this->generatorCase('reject_zero_spacing', '100', GridSpacingType::Arithmetic, '0', 1),
            $this->generatorCase('reject_zero_levels', '100', GridSpacingType::Arithmetic, '10', 0),
            $this->generatorCase('reject_geometric_spacing_one', '100', GridSpacingType::Geometric, '1', 1),
            $this->generatorCase('reject_level_below_zero', '5', GridSpacingType::Arithmetic, '10', 1),
            $this->generatorCase('reject_duplicate_prices', '1', GridSpacingType::Arithmetic, '0.00000000000000001', 1),
        ];

        $derivation = [
            $this->derivationCase('percent_0_4_to_fraction', fn () => GridDerivation::percentToFraction('0.4'), ['percent' => '0.4']),
            $this->derivationCase('percent_1_to_fraction', fn () => GridDerivation::percentToFraction('1'), ['percent' => '1']),
            $this->derivationCase('percent_0_05_to_fraction', fn () => GridDerivation::percentToFraction('0.05'), ['percent' => '0.05']),
            $this->derivationCase('percent_2_5_to_fraction', fn () => GridDerivation::percentToFraction('2.5'), ['percent' => '2.5']),
            $this->derivationCase('order_quantity_1000_0_8_6_50000', fn () => GridDerivation::orderQuantity('1000', '0.8', 6, '50000'), [
                'capital' => '1000',
                'utilization' => '0.8',
                'levels_per_side' => 6,
                'center_price' => '50000',
            ]),
            $this->derivationCase('order_quantity_1000_0_5_4_250', fn () => GridDerivation::orderQuantity('1000', '0.5', 4, '250'), [
                'capital' => '1000',
                'utilization' => '0.5',
                'levels_per_side' => 4,
                'center_price' => '250',
            ]),
            $this->derivationCase('reject_zero_percent', fn () => GridDerivation::percentToFraction('0'), ['percent' => '0']),
            $this->derivationCase('reject_float_percent', fn () => GridDerivation::percentToFraction(0.4), ['percent' => 0.4]),
            $this->derivationCase('reject_zero_levels', fn () => GridDerivation::orderQuantity('1000', '0.8', 0, '50000'), [
                'capital' => '1000',
                'utilization' => '0.8',
                'levels_per_side' => 0,
                'center_price' => '50000',
            ]),
            $this->derivationCase('reject_zero_center', fn () => GridDerivation::orderQuantity('1000', '0.8', 6, '0'), [
                'capital' => '1000',
                'utilization' => '0.8',
                'levels_per_side' => 6,
                'center_price' => '0',
            ]),
            $this->derivationCase('reject_float_capital', fn () => GridDerivation::orderQuantity(1000.0, '0.8', 6, '50000'), [
                'capital' => 1000.0,
                'utilization' => '0.8',
                'levels_per_side' => 6,
                'center_price' => '50000',
            ]),
        ];

        return $this->metadata() + [
            'description' => 'GridGenerator ladders and GridDerivation decimal helpers from the current PHP implementation.',
            'generator' => $generator,
            'derivation' => $derivation,
        ];
    }

    /** @return array<string, mixed> */
    private function generatorCase(
        string $label,
        string $center,
        GridSpacingType $type,
        string $spacing,
        int $levelsPerSide,
    ): array {
        $base = [
            'label' => $label,
            'center_price' => $center,
            'spacing_type' => $type->value,
            'spacing_value' => $spacing,
            'levels_per_side' => $levelsPerSide,
        ];

        try {
            $levels = app(GridGenerator::class)->generate($center, $type, $spacing, $levelsPerSide);

            return $base + ['levels' => $this->levels($levels)];
        } catch (Throwable $exception) {
            return $base + ['error' => $this->error($exception)];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function derivationCase(string $label, callable $call, array $arguments): array
    {
        $base = [
            'label' => $label,
            'arguments' => $arguments,
        ];

        try {
            $result = $call();

            return $base + [
                'result' => $result instanceof Decimal ? $result->toString() : $result,
            ];
        } catch (Throwable $exception) {
            return $base + [
                'error' => $this->error($exception),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function gridSync(): array
    {
        return $this->metadata() + [
            'description' => 'BotTickPipeline grid creation, level matching, fill-driven grid sync, and trailing recenter, with paper exchange data.',
            'scenarios' => [
                'simple_grid_create_match_and_fill' => $this->simpleGridSyncScenario(),
                'trailing_grid_recenter' => $this->trailingGridSyncScenario(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function simpleGridSyncScenario(): array
    {
        [$bot, $fake, $pipeline] = $this->paperBot('simple-grid', [
            'center_price' => '100',
            'levels_per_side' => 1,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
        ], '95');

        $steps = [];

        $first = $pipeline->tick($bot);
        $steps[] = $this->pipelineStep('initial_tick', '95', $first, $bot);

        $second = $pipeline->tick($bot->fresh());
        $steps[] = $this->pipelineStep('same_levels_keep_grid_and_orders', '95', $second, $bot);

        $fake->price = '89';
        $third = $pipeline->tick($bot->fresh());
        $steps[] = $this->pipelineStep('buy_fill_at_89', '89', $third, $bot);

        $fake->price = '111';
        $fourth = $pipeline->tick($bot->fresh());
        $steps[] = $this->pipelineStep('sell_fill_at_111', '111', $fourth, $bot);

        return ['steps' => $steps];
    }

    /** @return array<string, mixed> */
    private function trailingGridSyncScenario(): array
    {
        [$bot, $fake, $pipeline] = $this->paperBot('trailing-grid', [
            'center_price' => '100',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
            'trailing_distance' => '10',
        ], '100');

        $steps = [];

        $first = $pipeline->tick($bot);
        $steps[] = $this->pipelineStep('initial_grid_at_100', '100', $first, $bot);

        $fake->price = '111';
        $second = $pipeline->tick($bot->fresh());
        $steps[] = $this->pipelineStep('recenter_at_111', '111', $second, $bot);

        return ['steps' => $steps];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{0: Bot, 1: FakeExchangeClient, 2: BotTickPipeline}
     */
    private function paperBot(string $strategyKey, array $configuration, string $price): array
    {
        $bot = Bot::factory()->running()->create([
            'symbol' => 'BTC/USDT',
            'strategy_key' => $strategyKey,
            'base_inventory' => '2',
            'quote_inventory' => '1000',
            'fee_rate' => self::FEE_RATE,
        ]);

        StrategyConfig::factory()->for($bot)->create([
            'strategy_key' => $strategyKey,
            'configuration' => $configuration,
            'state' => null,
        ]);

        $fake = new FakeExchangeClient(price: $price);
        app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
            new PaperExchangeClient($fake),
            $fake,
        ));

        return [$bot, $fake, app(BotTickPipeline::class)];
    }

    /** @return array<string, mixed> */
    private function pipelineStep(string $label, string $marketPrice, TickResult $result, Bot $bot): array
    {
        $bot->refresh();

        return [
            'label' => $label,
            'market_price' => $marketPrice,
            'result' => [
                'status' => $result->status,
                'market_price' => $result->marketPrice,
                'orders_placed' => $result->ordersPlaced,
                'fills' => $result->fills,
                'orders_rejected' => $result->ordersRejected,
                'errors' => $result->errors,
            ],
            'bot' => [
                'status' => $bot->status->value,
                'base_inventory' => (string) $bot->base_inventory,
                'quote_inventory' => (string) $bot->quote_inventory,
                'average_entry_price' => (string) $bot->average_entry_price,
                'realized_pnl' => (string) $bot->realized_pnl,
                'fees_paid' => (string) $bot->fees_paid,
                'last_error' => $bot->last_error,
                'settings' => $bot->settings ?? [],
            ],
            'grids' => $this->grids($bot),
            'orders' => $this->orders($bot),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function grids(Bot $bot): array
    {
        return $bot->grids()
            ->with('levels')
            ->orderBy('id')
            ->get()
            ->map(fn (Grid $grid) => [
                'center_price' => (string) $grid->center_price,
                'spacing_type' => $grid->spacing_type->value,
                'spacing_value' => (string) $grid->spacing_value,
                'level_count' => $grid->level_count,
                'status' => $grid->status->value,
                'levels' => $grid->levels->map(fn ($level) => [
                    'sequence' => $level->sequence,
                    'price' => (string) $level->price,
                    'side' => $level->side->value,
                    'state' => $level->state->value,
                ])->all(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function orders(Bot $bot): array
    {
        $grids = $bot->grids()->orderBy('id')->pluck('id')->values();
        $gridIndexes = [];

        foreach ($grids as $index => $id) {
            $gridIndexes[$id] = $index + 1;
        }

        return $bot->orders()
            ->with('gridLevel')
            ->orderBy('id')
            ->get()
            ->map(fn (Order $order) => [
                'grid' => $order->grid_id === null ? null : ($gridIndexes[$order->grid_id] ?? null),
                'grid_level_sequence' => $order->gridLevel?->sequence,
                'side' => $order->side->value,
                'type' => $order->type->value,
                'price' => (string) $order->price,
                'quantity' => (string) $order->quantity,
                'filled_quantity' => (string) $order->filled_quantity,
                'status' => $order->status->value,
                'mode' => $order->mode->value,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function paperFills(): array
    {
        $cases = [
            $this->fillCase('buy_crossed_at_or_below_limit', '95', [
                $this->order('buy-100', OrderSide::Buy, '100', '1', '0', OrderStatus::Open),
            ]),
            $this->fillCase('buy_not_crossed_above_limit', '101', [
                $this->order('buy-100', OrderSide::Buy, '100', '1', '0', OrderStatus::Open),
            ]),
            $this->fillCase('sell_crossed_at_or_above_limit', '105', [
                $this->order('sell-100', OrderSide::Sell, '100', '1', '0', OrderStatus::Open),
            ]),
            $this->fillCase('sell_not_crossed_below_limit', '99', [
                $this->order('sell-100', OrderSide::Sell, '100', '1', '0', OrderStatus::Open),
            ]),
            $this->fillCase('closest_to_market_first', '75', [
                $this->order('buy-90', OrderSide::Buy, '90', '1', '0', OrderStatus::Open),
                $this->order('buy-80', OrderSide::Buy, '80', '1', '0', OrderStatus::Open),
            ]),
            $this->fillCase('distance_tie_breaks_on_lower_price', '100', [
                $this->order('buy-105', OrderSide::Buy, '105', '1', '0', OrderStatus::Open),
                $this->order('sell-95', OrderSide::Sell, '95', '1', '0', OrderStatus::Open),
            ]),
            $this->fillCase('partial_remaining_quantity', '90', [
                $this->order('buy-100-partial', OrderSide::Buy, '100', '2', '0.5', OrderStatus::PartiallyFilled),
            ]),
            $this->fillCase('filled_and_canceled_orders_are_skipped', '90', [
                $this->order('filled', OrderSide::Buy, '100', '1', '1', OrderStatus::Filled),
                $this->order('canceled', OrderSide::Buy, '100', '1', '0', OrderStatus::Canceled),
                $this->order('open', OrderSide::Buy, '100', '1', '0', OrderStatus::Open),
            ]),
            $this->fillCase('recross_seen_only_at_tick_time_91', '91', [
                $this->order('buy-90', OrderSide::Buy, '90', '1', '0', OrderStatus::Open),
            ]),
            $this->fillCase('tick_time_89_fills', '89', [
                $this->order('buy-90', OrderSide::Buy, '90', '1', '0', OrderStatus::Open),
            ]),
        ];

        return $this->metadata() + [
            'description' => 'PaperExchangeClient::qualifyingFills and BotTickPipeline tick-time paper fills, including cross-and-recross between ticks.',
            'qualifying_fills' => [
                'cases' => $cases,
            ],
            'tick_pipeline_cross_and_recross' => $this->crossAndRecrossScenario(),
        ];
    }

    /**
     * @param  list<Order>  $orders
     * @return array<string, mixed>
     */
    private function fillCase(string $label, string $marketLast, array $orders): array
    {
        $paper = new PaperExchangeClient(new FakeExchangeClient(price: $marketLast));
        $market = MarketData::make('BTC/USDT', $marketLast);
        $fills = $paper->qualifyingFills($market, $orders, self::FEE_RATE);

        return [
            'label' => $label,
            'market_last' => $marketLast,
            'fee_rate' => self::FEE_RATE,
            'orders' => array_map(fn (Order $order) => [
                'client_order_id' => $order->client_order_id,
                'side' => $order->side->value,
                'price' => (string) $order->price,
                'quantity' => (string) $order->quantity,
                'filled_quantity' => (string) $order->filled_quantity,
                'status' => $order->status->value,
            ], $orders),
            'fills' => array_map(fn ($fill) => [
                'client_order_id' => $fill->clientOrderId,
                'side' => $fill->side->value,
                'price' => $fill->price->toString(),
                'quantity' => $fill->quantity->toString(),
                'fee' => $fill->fee->toString(),
                'fee_currency' => $fill->feeCurrency,
            ], $fills),
        ];
    }

    private function order(
        string $clientOrderId,
        OrderSide $side,
        string $price,
        string $quantity,
        string $filledQuantity,
        OrderStatus $status,
    ): Order {
        return Order::factory()->make([
            'bot_id' => null,
            'client_order_id' => $clientOrderId,
            'side' => $side,
            'type' => OrderType::Limit,
            'price' => $price,
            'quantity' => $quantity,
            'filled_quantity' => $filledQuantity,
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function crossAndRecrossScenario(): array
    {
        [$bot, $fake, $pipeline] = $this->paperBot('simple-grid', [
            'center_price' => '100',
            'levels_per_side' => 1,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
        ], '95');

        $steps = [];

        $first = $pipeline->tick($bot);
        $steps[] = $this->pipelineStep('initial_tick_95', '95', $first, $bot);

        // The price traded through 89 between ticks but the tick only observes 91.
        $fake->price = '91';
        $second = $pipeline->tick($bot->fresh());
        $steps[] = $this->pipelineStep('recross_returns_before_tick_91', '91', $second, $bot);

        $fake->price = '89';
        $third = $pipeline->tick($bot->fresh());
        $steps[] = $this->pipelineStep('tick_observes_89_and_fills_buy', '89', $third, $bot);

        // The price traded through 111 between ticks but the tick only observes 109.
        $fake->price = '109';
        $fourth = $pipeline->tick($bot->fresh());
        $steps[] = $this->pipelineStep('recross_returns_before_tick_109', '109', $fourth, $bot);

        $fake->price = '111';
        $fifth = $pipeline->tick($bot->fresh());
        $steps[] = $this->pipelineStep('tick_observes_111_and_fills_sell', '111', $fifth, $bot);

        return [
            'intra_tick_prints' => [
                'before_91' => ['89'],
                'before_109' => ['111'],
            ],
            'steps' => $steps,
        ];
    }

    /** @return array<string, mixed> */
    private function strategies(): array
    {
        return $this->metadata() + [
            'description' => 'Strategy inputs and decisions from the four current PHP strategy implementations.',
            'strategies' => [
                SimpleGridStrategy::KEY => $this->simpleCases(),
                DynamicGridStrategy::KEY => $this->dynamicCases(),
                MartingaleGridStrategy::KEY => $this->martingaleCases(),
                TrailingGridStrategy::KEY => $this->trailingCases(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function simpleCases(): array
    {
        $strategy = new SimpleGridStrategy;
        $config = [
            'center_price' => '100',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
        ];

        $initial = $strategy->initialize($this->input(SimpleGridStrategy::KEY, $config));
        $initialState = $initial->state;
        $openAt90 = [OpenOrderSnapshot::make('existing', OrderSide::Buy, '90', '1', 2)];

        $buyFill = FillEvent::make('fill-buy-90', OrderSide::Buy, '90', '1', '0.1', 'client-2', 2);
        $afterBuy = $strategy->onFill($this->input(SimpleGridStrategy::KEY, $config, state: $initialState), $buyFill);

        $sellFill = FillEvent::make('fill-sell-110', OrderSide::Sell, '110', '1', '0.1', 'client-3', 3);
        $afterSell = $strategy->onFill($this->input(SimpleGridStrategy::KEY, $config, state: $afterBuy->state), $sellFill);

        return [
            'cases' => [
                $this->decisionCase('initialize_arithmetic', 'initialize', $this->input(SimpleGridStrategy::KEY, $config), $initial),
                $this->decisionCase('initialize_geometric', 'initialize', $this->input(SimpleGridStrategy::KEY, array_replace($config, [
                    'spacing_type' => 'geometric',
                    'spacing_value' => '0.1',
                ])), $strategy->initialize($this->input(SimpleGridStrategy::KEY, array_replace($config, [
                    'spacing_type' => 'geometric',
                    'spacing_value' => '0.1',
                ])))),
                $this->decisionCase('on_tick_new_input_initializes_and_orders', 'onTick', $this->input(SimpleGridStrategy::KEY, $config), $strategy->onTick($this->input(SimpleGridStrategy::KEY, $config))),
                $this->decisionCase('on_tick_skips_level_with_open_order', 'onTick', $this->input(SimpleGridStrategy::KEY, $config, state: $initialState, openOrders: $openAt90), $strategy->onTick($this->input(SimpleGridStrategy::KEY, $config, state: $initialState, openOrders: $openAt90))),
                $this->decisionCase('on_fill_buy_places_adjacent_sell', 'onFill', $this->input(SimpleGridStrategy::KEY, $config, state: $initialState), $afterBuy, $buyFill),
                $this->decisionCase('on_fill_sell_uses_adjacent_level_side', 'onFill', $this->input(SimpleGridStrategy::KEY, $config, state: $initialState), $strategy->onFill($this->input(SimpleGridStrategy::KEY, $config, state: $initialState), $sellFill), $sellFill),
                $this->decisionCase('on_fill_buy_then_sell_rearms_level', 'onTick', $this->input(SimpleGridStrategy::KEY, $config, state: $afterSell->state), $strategy->onTick($this->input(SimpleGridStrategy::KEY, $config, state: $afterSell->state))),
                $this->decisionCase('on_cancel_records_client_order_id', 'onCancel', $this->input(SimpleGridStrategy::KEY, $config, state: $initialState), $strategy->onCancel($this->input(SimpleGridStrategy::KEY, $config, state: $initialState), new CancelEvent('client-1', 2, 'manual'))),
                $this->decisionCase('initialize_existing_state_is_idle', 'initialize', $this->input(SimpleGridStrategy::KEY, $config, state: $initialState), $strategy->initialize($this->input(SimpleGridStrategy::KEY, $config, state: $initialState))),
                $this->decisionCase('get_grid_levels_from_state', 'getGridLevels', $this->input(SimpleGridStrategy::KEY, $config, state: $initialState), null, null, $strategy->getGridLevels($this->input(SimpleGridStrategy::KEY, $config, state: $initialState))),
                $this->errorCase('reject_dynamic_spacing', 'initialize', $this->input(SimpleGridStrategy::KEY, array_replace($config, ['spacing_type' => 'dynamic'])), fn () => $strategy->initialize($this->input(SimpleGridStrategy::KEY, array_replace($config, ['spacing_type' => 'dynamic'])))),
                $this->errorCase('reject_duplicate_levels', 'initialize', $this->input(SimpleGridStrategy::KEY, array_replace($config, ['spacing_value' => '0.000000000000000001'])), fn () => $strategy->initialize($this->input(SimpleGridStrategy::KEY, array_replace($config, ['spacing_value' => '0.000000000000000001'])))),
                $this->shouldStopCase('should_stop_false', $this->input(SimpleGridStrategy::KEY, $config, state: $initialState), $strategy->shouldStop($this->input(SimpleGridStrategy::KEY, $config, state: $initialState))),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dynamicCases(): array
    {
        $strategy = new DynamicGridStrategy;
        $config = [
            'center_price' => '100',
            'levels_per_side' => 1,
            'order_quantity' => '1',
            'min_spacing' => '0.5',
            'max_spacing' => '20',
            'volatility_multiplier' => '1',
            'initial_spacing' => '1',
            'recalculation_interval_seconds' => 60,
        ];

        $initialInput = $this->input(DynamicGridStrategy::KEY, $config, recent: ['100', '110']);
        $initial = $strategy->initialize($initialInput);
        $initialState = $initial->state;

        $suppressedInput = $this->input(DynamicGridStrategy::KEY, $config, recent: ['100', '150'], state: $initialState, at: '2026-11-23T00:00:30+00:00');
        $recalculatedInput = $this->input(DynamicGridStrategy::KEY, $config, recent: ['100', '150'], state: $initialState, at: '2026-11-23T00:02:00+00:00', openOrders: [
            OpenOrderSnapshot::make('outside', OrderSide::Buy, '80', '1', 1),
            OpenOrderSnapshot::make('inside', OrderSide::Buy, '101', '1', 2),
        ]);
        $fill = FillEvent::make('fill-buy-80', OrderSide::Buy, '80', '1', '0.08', 'client-1', 1);

        return [
            'cases' => [
                $this->decisionCase('initialize_volatility_spacing', 'initialize', $initialInput, $initial),
                $this->decisionCase('initialize_without_history_uses_initial_spacing', 'initialize', $this->input(DynamicGridStrategy::KEY, $config), $strategy->initialize($this->input(DynamicGridStrategy::KEY, $config))),
                $this->decisionCase('on_tick_before_interval_is_unchanged', 'onTick', $suppressedInput, $strategy->onTick($suppressedInput)),
                $this->decisionCase('on_tick_recalculates_cancels_outside_and_reorders', 'onTick', $recalculatedInput, $strategy->onTick($recalculatedInput)),
                $this->decisionCase('on_fill_buy_places_adjacent_sell', 'onFill', $this->input(DynamicGridStrategy::KEY, $config, recent: ['100', '110'], state: $initialState), $strategy->onFill($this->input(DynamicGridStrategy::KEY, $config, recent: ['100', '110'], state: $initialState), $fill), $fill),
                $this->decisionCase('get_grid_levels_from_state', 'getGridLevels', $this->input(DynamicGridStrategy::KEY, $config, state: $initialState), null, null, $strategy->getGridLevels($this->input(DynamicGridStrategy::KEY, $config, state: $initialState))),
                $this->decisionCase('on_cancel_records_client_order_id', 'onCancel', $this->input(DynamicGridStrategy::KEY, $config, state: $initialState), $strategy->onCancel($this->input(DynamicGridStrategy::KEY, $config, state: $initialState), new CancelEvent('client-1', 2, 'manual'))),
                $this->errorCase('reject_zero_min_spacing', 'initialize', $this->input(DynamicGridStrategy::KEY, array_replace($config, ['min_spacing' => '0']), recent: ['100', '110']), fn () => $strategy->initialize($this->input(DynamicGridStrategy::KEY, array_replace($config, ['min_spacing' => '0']), recent: ['100', '110']))),
                $this->errorCase('reject_max_below_min', 'initialize', $this->input(DynamicGridStrategy::KEY, array_replace($config, ['max_spacing' => '0.1']), recent: ['100', '110']), fn () => $strategy->initialize($this->input(DynamicGridStrategy::KEY, array_replace($config, ['max_spacing' => '0.1']), recent: ['100', '110']))),
                $this->shouldStopCase('should_stop_false', $this->input(DynamicGridStrategy::KEY, $config, state: $initialState), $strategy->shouldStop($this->input(DynamicGridStrategy::KEY, $config, state: $initialState))),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function martingaleCases(): array
    {
        $strategy = new MartingaleGridStrategy;
        $config = [
            'center_price' => '100',
            'levels_per_side' => 1,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'base_order_quantity' => '1',
            'size_multiplier' => '2',
            'max_order_quantity' => '10',
            'max_increases' => 10,
        ];

        $initialInput = $this->input(MartingaleGridStrategy::KEY, $config);
        $initial = $strategy->initialize($initialInput);
        $initialState = $initial->state;

        $fillOne = FillEvent::make('fill-1', OrderSide::Buy, '90', '1', '0.09', 'client-1', 1);
        $first = $strategy->onFill($this->input(MartingaleGridStrategy::KEY, $config, state: $initialState), $fillOne);

        $fillTwo = FillEvent::make('fill-2', OrderSide::Buy, '90', '2', '0.18', 'client-2', 1);
        $second = $strategy->onFill($this->input(MartingaleGridStrategy::KEY, $config, state: $first->state), $fillTwo);

        $capConfig = array_replace($config, ['max_order_quantity' => '3']);
        $capInitial = $strategy->initialize($this->input(MartingaleGridStrategy::KEY, $capConfig));
        $capFirst = $strategy->onFill($this->input(MartingaleGridStrategy::KEY, $capConfig, state: $capInitial->state), $fillOne);
        $capSecond = $strategy->onFill($this->input(MartingaleGridStrategy::KEY, $capConfig, state: $capFirst->state), FillEvent::make('fill-3', OrderSide::Buy, '90', '2', '0.18', 'client-3', 1));

        $limitedConfig = array_replace($config, ['max_increases' => 2, 'max_order_quantity' => '100']);
        $limitedInitial = $strategy->initialize($this->input(MartingaleGridStrategy::KEY, $limitedConfig));
        $limitedFirst = $strategy->onFill($this->input(MartingaleGridStrategy::KEY, $limitedConfig, state: $limitedInitial->state), $fillOne);
        $limitedSecond = $strategy->onFill($this->input(MartingaleGridStrategy::KEY, $limitedConfig, state: $limitedFirst->state), $fillTwo);
        $limitedThird = $strategy->onFill($this->input(MartingaleGridStrategy::KEY, $limitedConfig, state: $limitedSecond->state), FillEvent::make('fill-4', OrderSide::Buy, '90', '4', '0.36', 'client-4', 1));

        $badMultiplierConfig = array_replace($config, ['size_multiplier' => '1']);
        $badMultiplierInitial = $strategy->initialize($this->input(MartingaleGridStrategy::KEY, $badMultiplierConfig));

        $badMaximumConfig = array_replace($config, ['max_order_quantity' => '0.5']);
        $badMaximumInitial = $strategy->initialize($this->input(MartingaleGridStrategy::KEY, $badMaximumConfig));

        return [
            'cases' => [
                $this->decisionCase('initialize_base_quantity', 'initialize', $initialInput, $initial),
                $this->decisionCase('on_tick_new_input_orders_base_quantity', 'onTick', $initialInput, $strategy->onTick($initialInput)),
                $this->decisionCase('on_fill_first_increase', 'onFill', $this->input(MartingaleGridStrategy::KEY, $config, state: $initialState), $first, $fillOne),
                $this->decisionCase('on_fill_second_increase', 'onFill', $this->input(MartingaleGridStrategy::KEY, $config, state: $first->state), $second, $fillTwo),
                $this->decisionCase('on_fill_caps_at_max_quantity', 'onFill', $this->input(MartingaleGridStrategy::KEY, $capConfig, state: $capFirst->state), $capSecond, FillEvent::make('fill-3', OrderSide::Buy, '90', '2', '0.18', 'client-3', 1)),
                $this->decisionCase('on_fill_second_reaches_max_increases', 'onFill', $this->input(MartingaleGridStrategy::KEY, $limitedConfig, state: $limitedFirst->state), $limitedSecond, $fillTwo),
                $this->decisionCase('on_fill_after_cap_stays_at_cap', 'onFill', $this->input(MartingaleGridStrategy::KEY, $limitedConfig, state: $limitedSecond->state), $limitedThird, FillEvent::make('fill-4', OrderSide::Buy, '90', '4', '0.36', 'client-4', 1)),
                $this->decisionCase('on_tick_uses_increased_quantity', 'onTick', $this->input(MartingaleGridStrategy::KEY, $config, state: $second->state), $strategy->onTick($this->input(MartingaleGridStrategy::KEY, $config, state: $second->state))),
                $this->decisionCase('get_grid_levels_from_state', 'getGridLevels', $this->input(MartingaleGridStrategy::KEY, $config, state: $initialState), null, null, $strategy->getGridLevels($this->input(MartingaleGridStrategy::KEY, $config, state: $initialState))),
                $this->decisionCase('on_cancel_records_client_order_id', 'onCancel', $this->input(MartingaleGridStrategy::KEY, $config, state: $initialState), $strategy->onCancel($this->input(MartingaleGridStrategy::KEY, $config, state: $initialState), new CancelEvent('client-1', 2, 'manual'))),
                $this->errorCase('reject_multiplier_of_one', 'onFill', $this->input(MartingaleGridStrategy::KEY, $badMultiplierConfig, state: $badMultiplierInitial->state), fn () => $strategy->onFill($this->input(MartingaleGridStrategy::KEY, $badMultiplierConfig, state: $badMultiplierInitial->state), $fillOne)),
                $this->errorCase('reject_max_below_base', 'onFill', $this->input(MartingaleGridStrategy::KEY, $badMaximumConfig, state: $badMaximumInitial->state), fn () => $strategy->onFill($this->input(MartingaleGridStrategy::KEY, $badMaximumConfig, state: $badMaximumInitial->state), $fillOne)),
                $this->shouldStopCase('should_stop_false', $this->input(MartingaleGridStrategy::KEY, $config, state: $initialState), $strategy->shouldStop($this->input(MartingaleGridStrategy::KEY, $config, state: $initialState))),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function trailingCases(): array
    {
        $strategy = new TrailingGridStrategy;
        $config = [
            'center_price' => '100',
            'levels_per_side' => 2,
            'spacing_type' => 'arithmetic',
            'spacing_value' => '10',
            'order_quantity' => '1',
            'trailing_distance' => '10',
        ];

        $initialInput = $this->input(TrailingGridStrategy::KEY, $config);
        $initial = $strategy->initialize($initialInput);
        $initialState = $initial->state;

        $insideInput = $this->input(TrailingGridStrategy::KEY, $config, price: '105', state: $initialState);
        $recenterInput = $this->input(TrailingGridStrategy::KEY, $config, price: '111', state: $initialState);
        $recenterWithOrders = $this->input(TrailingGridStrategy::KEY, $config, price: '111', state: $initialState, openOrders: [
            OpenOrderSnapshot::make('old-outside', OrderSide::Buy, '80', '1', 1),
            OpenOrderSnapshot::make('still-inside', OrderSide::Buy, '101', '1', 2),
        ]);
        $fill = FillEvent::make('fill-buy-90', OrderSide::Buy, '90', '1', '0.09', 'client-1', 2);

        return [
            'cases' => [
                $this->decisionCase('initialize_center_and_distance', 'initialize', $initialInput, $initial),
                $this->decisionCase('on_tick_inside_distance_keeps_center', 'onTick', $insideInput, $strategy->onTick($insideInput)),
                $this->decisionCase('on_tick_recenters_beyond_distance', 'onTick', $recenterInput, $strategy->onTick($recenterInput)),
                $this->decisionCase('on_tick_recenter_cancels_outside_orders', 'onTick', $recenterWithOrders, $strategy->onTick($recenterWithOrders)),
                $this->decisionCase('on_fill_buy_places_adjacent_sell', 'onFill', $this->input(TrailingGridStrategy::KEY, $config, state: $initialState), $strategy->onFill($this->input(TrailingGridStrategy::KEY, $config, state: $initialState), $fill), $fill),
                $this->decisionCase('get_grid_levels_from_state', 'getGridLevels', $this->input(TrailingGridStrategy::KEY, $config, state: $initialState), null, null, $strategy->getGridLevels($this->input(TrailingGridStrategy::KEY, $config, state: $initialState))),
                $this->decisionCase('on_cancel_records_client_order_id', 'onCancel', $this->input(TrailingGridStrategy::KEY, $config, state: $initialState), $strategy->onCancel($this->input(TrailingGridStrategy::KEY, $config, state: $initialState), new CancelEvent('client-1', 2, 'manual'))),
                $this->errorCase('reject_zero_trailing_distance', 'initialize', $this->input(TrailingGridStrategy::KEY, array_replace($config, ['trailing_distance' => '0'])), fn () => $strategy->initialize($this->input(TrailingGridStrategy::KEY, array_replace($config, ['trailing_distance' => '0'])))),
                $this->shouldStopCase('should_stop_false', $this->input(TrailingGridStrategy::KEY, $config, state: $initialState), $strategy->shouldStop($this->input(TrailingGridStrategy::KEY, $config, state: $initialState))),
            ],
        ];
    }

    /**
     * @param  list<string>  $recent
     * @param  list<OpenOrderSnapshot>  $openOrders
     */
    private function input(
        string $key,
        array $config,
        string $price = '100',
        array $recent = [],
        ?StrategyState $state = null,
        array $openOrders = [],
        string $at = self::CLOCK,
    ): StrategyInput {
        return new StrategyInput(
            market: new MarketSnapshot(
                symbol: 'BTC/USDT',
                price: Decimal::of($price),
                recentPrices: array_map(fn (string $value) => Decimal::of($value)->toString(), $recent),
                timestamp: new DateTimeImmutable($at),
            ),
            bot: BotSnapshot::make(1, '0', '1000', '0', '0', '1000', $openOrders),
            config: new StrategyConfiguration($key, $config),
            state: $state ?? StrategyState::empty(),
        );
    }

    /** @return array<string, mixed> */
    private function decisionCase(
        string $label,
        string $operation,
        StrategyInput $input,
        ?StrategyDecision $decision,
        ?FillEvent $fill = null,
        ?array $levels = null,
    ): array {
        $case = [
            'label' => $label,
            'operation' => $operation,
            'input' => $this->inputSnapshot($input),
        ];

        if ($fill instanceof FillEvent) {
            $case['fill'] = [
                'exchange_fill_id' => $fill->exchangeFillId,
                'side' => $fill->side->value,
                'price' => $fill->price->toString(),
                'quantity' => $fill->quantity->toString(),
                'fee' => $fill->fee->toString(),
                'client_order_id' => $fill->clientOrderId,
                'grid_level_sequence' => $fill->gridLevelSequence,
            ];
        }

        if ($decision instanceof StrategyDecision) {
            $case['decision'] = [
                'state' => $decision->state->toArray(),
                'levels' => $this->levels($decision->levels),
                'orders' => $this->ordersFromIntents($decision->orders),
                'stop' => $decision->stop,
                'events' => $decision->events,
                'cancel_client_order_ids' => $decision->cancelClientOrderIds,
            ];
        } elseif ($levels !== null) {
            $case['levels'] = $this->levels($levels);
        }

        return $case;
    }

    /** @return array<string, mixed> */
    private function errorCase(string $label, string $operation, StrategyInput $input, callable $call): array
    {
        try {
            $call();
        } catch (Throwable $exception) {
            return [
                'label' => $label,
                'operation' => $operation,
                'input' => $this->inputSnapshot($input),
                'error' => $this->error($exception),
            ];
        }

        return [
            'label' => $label,
            'operation' => $operation,
            'input' => $this->inputSnapshot($input),
            'error' => ['exception' => null, 'message' => 'no exception thrown'],
        ];
    }

    /** @return array<string, mixed> */
    private function shouldStopCase(string $label, StrategyInput $input, bool $stop): array
    {
        return [
            'label' => $label,
            'operation' => 'shouldStop',
            'input' => $this->inputSnapshot($input),
            'stop' => $stop,
        ];
    }

    /** @return array<string, mixed> */
    private function inputSnapshot(StrategyInput $input): array
    {
        return [
            'market' => [
                'symbol' => $input->market->symbol,
                'price' => $input->market->price->toString(),
                'bid' => $input->market->bid?->toString(),
                'ask' => $input->market->ask?->toString(),
                'recent_prices' => $input->market->recentPrices,
                'timestamp' => $input->market->timestamp?->format(DATE_ATOM),
            ],
            'bot' => [
                'bot_id' => $input->bot->botId,
                'base_inventory' => $input->bot->baseInventory->toString(),
                'quote_inventory' => $input->bot->quoteInventory->toString(),
                'average_entry_price' => $input->bot->averageEntryPrice->toString(),
                'realized_pnl' => $input->bot->realizedPnl->toString(),
                'capital_allocation' => $input->bot->capitalAllocation->toString(),
                'open_orders' => array_map(fn (OpenOrderSnapshot $order) => [
                    'client_order_id' => $order->clientOrderId,
                    'side' => $order->side->value,
                    'price' => $order->price->toString(),
                    'quantity' => $order->quantity->toString(),
                    'grid_level_sequence' => $order->gridLevelSequence,
                ], $input->bot->openOrders),
            ],
            'config' => [
                'key' => $input->config->key,
                'values' => $input->config->values,
            ],
            'state' => $input->state->toArray(),
        ];
    }

    /** @param list<GridLevelPlan> $levels */
    private function levels(array $levels): array
    {
        return array_map(fn (GridLevelPlan $level) => [
            'sequence' => $level->sequence,
            'price' => $level->price->toString(),
            'side' => $level->side->value,
        ], $levels);
    }

    /** @param list<OrderIntent> $orders */
    private function ordersFromIntents(array $orders): array
    {
        return array_map(fn (OrderIntent $order) => [
            'side' => $order->side->value,
            'price' => $order->price->toString(),
            'quantity' => $order->quantity->toString(),
            'grid_level_sequence' => $order->gridLevelSequence,
            'reason' => $order->reason,
        ], $orders);
    }

    /** @return array{exception: string, message: string} */
    private function error(Throwable $exception): array
    {
        return [
            'exception' => (new \ReflectionClass($exception))->getShortName(),
            'message' => $exception->getMessage(),
        ];
    }
}
