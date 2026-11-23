<?php

namespace Tests\Support;

use App\Actions\CancelOrder;
use App\Actions\StartBot;
use App\Domain\Activity\ActivityRecorder;
use App\Domain\Bot\BotTickPipeline;
use App\Domain\Bot\Enums\BotMode;
use App\Domain\Bot\Enums\BotStatus;
use App\Domain\Bot\ValueObjects\TickResult;
use App\Domain\Exchange\ConsecutiveFailureTracker;
use App\Domain\Exit\ExitGuardMonitor;
use App\Domain\Exit\ExitRules;
use App\Domain\Exit\ExitSignal;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\OrderStateMachine;
use App\Domain\Risk\DailyLossMonitor;
use App\Domain\Risk\Enums\RiskScope;
use App\Domain\Risk\RiskEventRecorder;
use App\Domain\Risk\RiskGuard;
use App\Domain\Risk\ValueObjects\LiveStartConfirmation;
use App\Domain\Risk\ValueObjects\RiskDecision;
use App\Domain\Support\Decimal;
use App\Http\Requests\StoreBotRequest;
use App\Models\Bot;
use App\Models\Order;
use App\Models\RiskLimit;
use App\Models\StrategyConfig;
use App\Models\User;
use App\Services\Exchange\ExchangeClientFactory;
use App\Services\Exchange\PaperExchangeClient;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Tests\Fixtures\FakeExchangeClient;
use Throwable;

/**
 * Captures deterministic golden fixtures for the current PHP risk guards.
 *
 * These fixtures are input for the later Go ports (tasks 5.1-5.7). The harness
 * calls production classes only; it does not reimplement or change behaviour.
 * It never reads .env, APP_KEY, credentials, or enables live trading.
 */
final class GoldenRiskFixtures
{
    public const FORMAT = 'php-golden-risk/1';

    public const CLOCK = GoldenEngineFixtures::CLOCK;

    /** @var list<string> */
    public const FILES = ['risk'];

    public const CIPHERTEXT_FILE = 'credentials_ciphertext';

    public const CIPHERTEXT_FORMAT = 'php-golden-credentials/1';

    /**
     * Test-only key, hardcoded here on purpose. It is not, and must never be,
     * the application key from .env. Both keys decode to 32 raw bytes.
     */
    public const TEST_KEY = 'base64:Z2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2c=';

    public const TEST_PREVIOUS_KEY = 'base64:aGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGhoaGg=';

    /** @var array<string, string> */
    public const CIPHERTEXT_CASES = [
        'api_key_ascii' => 'golden-test-api-key-0001',
        'api_secret_symbols' => 'g0lden-s3cret/+=?&',
        'passphrase_unicode' => 'pässphrase-测试-🔑',
    ];

    public const ROTATION_PLAINTEXT = 'golden-rotation-secret-0001';

    /** @return array<string, array<string, mixed>> */
    public function capture(): array
    {
        return [
            'risk' => $this->risk(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function captureCiphertext(): array
    {
        return [
            self::CIPHERTEXT_FILE => $this->ciphertextFixture(),
        ];
    }

    public static function path(string $name): string
    {
        return base_path('tests/golden/engine/'.$name.'.json');
    }

    /** @param array<string, mixed> $data */
    public static function encode(array $data): string
    {
        return GoldenEngineFixtures::encode($data);
    }

    /** @param array<string, array<string, mixed>> $fixtures */
    public function write(array $fixtures): void
    {
        if (! is_dir(dirname(self::path('x')))) {
            mkdir(dirname(self::path('x')), 0777, true);
        }

        foreach ($fixtures as $name => $data) {
            $path = self::path($name);
            $encoded = GoldenEngineFixtures::encode($data);
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

    /** @return array<string, mixed> */
    private function risk(): array
    {
        return [
            'metadata' => [
                'format' => self::FORMAT,
                'clock' => self::CLOCK,
                'decimal_scale' => Decimal::SCALE,
                'captured_from' => 'current PHP engine on branch hybrid-engine',
            ],
            'live_mode_guard' => $this->liveModeGuard(),
            'risk_guard' => $this->riskGuard(),
            'daily_loss_monitor' => $this->dailyLossMonitor(),
            'exit_guard' => $this->exitGuard(),
            'consecutive_failure_tracker' => $this->consecutiveFailureTracker(),
            'exchange_allowlist' => $this->exchangeAllowlist(),
        ];
    }

    /** @return array<string, mixed> */
    private function liveModeGuard(): array
    {
        $originalGate = config('trading.live_enabled');

        try {
            return [
                'notes' => [
                    'gate is config trading.live_enabled (TRADING_LIVE_ENABLED); the fixture never enables it outside these isolated cases',
                    'a live bot rejected by the gate stays live and idle; it is never converted to paper',
                ],
                'start_cases' => [
                    $this->liveStartCase('paper_mode_gate_off_starts', gate: false, live: false, confirmation: null, quoteBalance: '0'),
                    $this->liveStartCase('live_gate_off_valid_confirmation_rejected_stays_idle', gate: false, live: true, confirmation: 'Golden live_gate_off_valid_confirmation_rejected_stays_idle on binance', quoteBalance: '1000'),
                    $this->liveStartCase('live_gate_on_missing_confirmation_rejected', gate: true, live: true, confirmation: null, quoteBalance: '1000'),
                    $this->liveStartCase('live_gate_on_wrong_confirmation_rejected', gate: true, live: true, confirmation: 'not the phrase', quoteBalance: '1000'),
                    $this->liveStartCase('live_gate_on_exact_quote_balance_starts', gate: true, live: true, confirmation: 'Golden live_gate_on_exact_quote_balance_starts on binance', quoteBalance: '1000'),
                    $this->liveStartCase('live_gate_on_insufficient_quote_balance_rejected', gate: true, live: true, confirmation: 'Golden live_gate_on_insufficient_quote_balance_rejected on binance', quoteBalance: '999.99'),
                    $this->liveStartCase('live_gate_on_missing_quote_asset_rejected', gate: true, live: true, confirmation: 'Golden live_gate_on_missing_quote_asset_rejected on binance', quoteBalance: '1000', symbol: 'BTC/EUR'),
                    $this->liveStartCase('paper_mode_gate_on_ignores_balance', gate: true, live: false, confirmation: null, quoteBalance: '0'),
                ],
                'tick_gate_closed' => $this->liveTickGateClosedCase(),
            ];
        } finally {
            config(['trading.live_enabled' => $originalGate]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function liveStartCase(
        string $label,
        bool $gate,
        bool $live,
        ?string $confirmation,
        string $quoteBalance,
        string $symbol = 'BTC/USDT',
    ): array {
        config(['trading.live_enabled' => $gate]);

        $bot = Bot::factory()->create([
            'name' => 'Golden '.$label,
            'symbol' => $symbol,
            'mode' => $live ? BotMode::Live : BotMode::Paper,
            'status' => BotStatus::Idle,
            'capital_allocation' => '1000',
        ]);

        $fake = new FakeExchangeClient(quoteBalance: $quoteBalance);

        $case = [
            'label' => $label,
            'gate' => $gate,
            'confirmation' => $confirmation,
            'quote_balance' => $quoteBalance,
            'symbol' => $symbol,
        ];

        try {
            $started = app(StartBot::class)->handle(
                $bot,
                $confirmation === null ? null : new LiveStartConfirmation($confirmation),
                $fake,
            );

            $case['error'] = null;
            $case['bot'] = $this->botState($started);
        } catch (Throwable $exception) {
            $case['error'] = $this->error($exception);
            $case['bot'] = $this->botState($bot->fresh());
        }

        $case['balance_calls'] = $fake->balanceCalls;

        return $case;
    }

    /** @return array<string, mixed> */
    private function liveTickGateClosedCase(): array
    {
        config(['trading.live_enabled' => false]);

        $bot = Bot::factory()->live()->running()->create([
            'name' => 'Golden live tick gate closed',
            'capital_allocation' => '1000',
        ]);

        $this->openOrder($bot, 'live-tick-open-buy', OrderSide::Buy, '90', '0.5');
        $this->openOrder($bot, 'live-tick-open-sell', OrderSide::Sell, '110', '0.5');

        $fake = new FakeExchangeClient(price: '100');
        app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
            new PaperExchangeClient($fake),
            $fake,
        ));

        $result = app(BotTickPipeline::class)->tick($bot);

        return [
            'label' => 'live_tick_gate_closed_pauses_instead_of_falling_back_to_paper',
            'result' => $this->tickResult($result),
            'bot' => $this->botState($bot->fresh()),
            'orders' => $this->orders($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
            'exchange_calls' => [
                'market' => 0,
                'place' => count($fake->placeCalls),
                'balances' => $fake->balanceCalls,
                'cancel' => count($fake->cancelCalls),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function riskGuard(): array
    {
        return [
            'notes' => [
                'limits are created as bot-scope rows with explicit values so the fixture does not depend on env defaults',
                'insufficient inventory is a block without a risk event; other rejections record an event',
            ],
            'cases' => [
                $this->riskGuardCase(
                    'allow_buy_inside_position_limit',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 20],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '5'],
                ),
                $this->riskGuardCase(
                    'allow_buy_at_exact_position_boundary',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 20],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '10'],
                ),
                $this->riskGuardCase(
                    'reject_buy_above_position_boundary',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 20],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '10.0000000000000001'],
                ),
                $this->riskGuardCase(
                    'reject_zero_price',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 20],
                    ['side' => 'buy', 'price' => '0', 'quantity' => '1'],
                ),
                $this->riskGuardCase(
                    'reject_negative_quantity',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 20],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '-1'],
                ),
                $this->riskGuardCase(
                    'reject_sell_above_uncommitted_inventory_without_event',
                    ['base_inventory' => '1'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 20],
                    ['side' => 'sell', 'price' => '100', 'quantity' => '1.5'],
                ),
                $this->riskGuardCase(
                    'allow_sell_fully_covered_by_inventory',
                    ['base_inventory' => '2'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 20],
                    ['side' => 'sell', 'price' => '100', 'quantity' => '1'],
                ),
                $this->riskGuardCase(
                    'allow_sell_covered_after_reserved_sells',
                    ['base_inventory' => '2'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 20],
                    ['side' => 'sell', 'price' => '100', 'quantity' => '1'],
                    [['client_order_id' => 'reserved-sell', 'side' => 'sell', 'price' => '100', 'quantity' => '1']],
                ),
                $this->riskGuardCase(
                    'reject_sell_above_available_after_reserved_sells',
                    ['base_inventory' => '2'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 20],
                    ['side' => 'sell', 'price' => '100', 'quantity' => '1.0000000000000001'],
                    [['client_order_id' => 'reserved-sell', 'side' => 'sell', 'price' => '100', 'quantity' => '1']],
                ),
                $this->riskGuardCase(
                    'allow_position_at_boundary_with_open_buy_notional',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '250', 'max_open_orders' => 20],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '0.5'],
                    [['client_order_id' => 'open-buy', 'side' => 'buy', 'price' => '100', 'quantity' => '2']],
                ),
                $this->riskGuardCase(
                    'reject_position_with_open_buy_notional',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '250', 'max_open_orders' => 20],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '0.6'],
                    [['client_order_id' => 'open-buy', 'side' => 'buy', 'price' => '100', 'quantity' => '2']],
                ),
                $this->riskGuardCase(
                    'allow_at_open_order_boundary',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 2],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '1'],
                    [['client_order_id' => 'open-1', 'side' => 'buy', 'price' => '100', 'quantity' => '1']],
                ),
                $this->riskGuardCase(
                    'reject_above_open_order_limit',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 1],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '1'],
                    [['client_order_id' => 'open-1', 'side' => 'buy', 'price' => '100', 'quantity' => '1']],
                ),
                $this->riskGuardCase(
                    'allow_when_only_terminal_orders_exist',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '1000', 'max_open_orders' => 1],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '1'],
                    [['client_order_id' => 'filled-1', 'side' => 'buy', 'price' => '100', 'quantity' => '1', 'status' => 'filled']],
                ),
                $this->riskGuardCase(
                    'open_buy_counts_remaining_quantity_only',
                    ['base_inventory' => '0'],
                    ['max_position_notional' => '150', 'max_open_orders' => 20],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '0.6'],
                    [['client_order_id' => 'open-partial', 'side' => 'buy', 'price' => '100', 'quantity' => '2', 'filled_quantity' => '1']],
                ),
                $this->riskGuardCase(
                    'allow_without_limit_row_uses_config_defaults',
                    ['base_inventory' => '0'],
                    [],
                    ['side' => 'buy', 'price' => '100', 'quantity' => '5'],
                ),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $botAttributes
     * @param  array<string, mixed>  $limits
     * @param  array{side: string, price: string, quantity: string}  $order
     * @param  list<array<string, string>>  $openOrders
     * @return array<string, mixed>
     */
    private function riskGuardCase(
        string $label,
        array $botAttributes,
        array $limits,
        array $order,
        array $openOrders = [],
    ): array {
        $bot = Bot::factory()->create(array_merge([
            'name' => 'Golden '.$label,
            'base_inventory' => '0',
            'quote_inventory' => '1000',
        ], $botAttributes));

        if ($limits !== []) {
            RiskLimit::factory()->forBot($bot)->create([
                'max_position_notional' => $limits['max_position_notional'] ?? '1000',
                'max_open_orders' => $limits['max_open_orders'] ?? 20,
                'daily_loss_limit' => $limits['daily_loss_limit'] ?? '100',
            ]);
        }

        foreach ($openOrders as $openOrder) {
            $this->openOrder(
                $bot,
                $openOrder['client_order_id'],
                OrderSide::from($openOrder['side']),
                $openOrder['price'],
                $openOrder['quantity'],
                $openOrder['filled_quantity'] ?? '0',
                OrderStatus::from($openOrder['status'] ?? OrderStatus::Open->value),
            );
        }

        $guard = app(RiskGuard::class);
        $effective = $guard->limits($bot);

        $decision = $guard->checkOrder(
            $bot,
            OrderSide::from($order['side']),
            $order['price'],
            $order['quantity'],
        );

        return [
            'label' => $label,
            'bot' => [
                'base_inventory' => (string) $bot->base_inventory,
                'open_orders' => $this->orders($bot),
            ],
            'limits' => [
                'max_position_notional' => $effective['max_position_notional']->toString(),
                'max_open_orders' => $effective['max_open_orders'],
                'daily_loss_limit' => $effective['daily_loss_limit']->toString(),
            ],
            'order' => $order,
            'decision' => $this->decision($decision),
            'risk_events' => $this->riskEvents($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function dailyLossMonitor(): array
    {
        return [
            'notes' => [
                'bots without a bot-scope override store the per-bot baseline in bot.settings.daily_loss_baseline',
                'the user-wide baseline stays on the user-scope risk_limits row (day_start / day_start_realized_pnl)',
                'pausing never resumes automatically',
            ],
            'cases' => [
                $this->dailyPerBotBreachCase(),
                $this->dailyBelowLimitCase(),
                $this->dailyAccountWideCase(),
                $this->dailyBaselineIsolationCase(),
                $this->dailyEngineOwnedBaselineCase(),
                $this->dailyExplicitOverrideResetCase(),
                $this->dailyUnrealizedLossCase(),
                $this->dailyAccountMarkIsolationCase(),
                $this->dailyNoAutoResumeCase(),
                $this->dailyTickPausesBeforePlacingCase(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dailyPerBotBreachCase(): array
    {
        $user = User::factory()->create();
        $this->userRiskLimit($user, ['daily_loss_limit' => '15']);
        $bot = $this->dailyBot($user, 'daily_per_bot_breach');
        $this->openOrder($bot, 'daily-open-buy', OrderSide::Buy, '95', '0.5');

        $monitor = app(DailyLossMonitor::class);
        $monitor->evaluate($bot);
        $baseline = $this->baseline($bot);

        $bot->update(['realized_pnl' => '-20']);
        $monitor->evaluate($bot);

        return [
            'label' => 'per_bot_realized_loss_pauses_and_cancels_orders',
            'baseline_after_first_evaluate' => $baseline,
            'user_limit_after' => $this->riskLimitState($this->userRiskLimitRow($user)),
            'bot' => $this->botState($bot),
            'orders' => $this->orders($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function dailyBelowLimitCase(): array
    {
        $user = User::factory()->create();
        $this->userRiskLimit($user, ['daily_loss_limit' => '10']);
        $bot = $this->dailyBot($user, 'daily_below_limit');

        $monitor = app(DailyLossMonitor::class);
        $monitor->evaluate($bot);
        $bot->update(['realized_pnl' => '-9.99']);
        $monitor->evaluate($bot);

        return [
            'label' => 'loss_below_limit_keeps_running_without_events',
            'bot' => $this->botState($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function dailyAccountWideCase(): array
    {
        $user = User::factory()->create();
        $this->userRiskLimit($user, ['daily_loss_limit' => '25']);
        $first = $this->dailyBot($user, 'daily_account_first');
        $second = $this->dailyBot($user, 'daily_account_second');
        $this->openOrder($first, 'account-first-open', OrderSide::Buy, '95', '0.5');
        $this->openOrder($second, 'account-second-open', OrderSide::Sell, '105', '0.5');

        $monitor = app(DailyLossMonitor::class);
        $monitor->evaluate($first);
        $monitor->evaluate($second);

        $first->update(['realized_pnl' => '-20']);
        $second->update(['realized_pnl' => '-10']);
        $monitor->evaluate($first);

        return [
            'label' => 'account_wide_loss_pauses_every_user_bot',
            'first' => [
                'bot' => $this->botState($first),
                'risk_events' => $this->riskEvents($first),
                'activity' => $this->activity($first),
            ],
            'second' => [
                'bot' => $this->botState($second),
                'risk_events' => $this->riskEvents($second),
                'activity' => $this->activity($second),
            ],
            'orders' => [
                'first' => $this->orders($first),
                'second' => $this->orders($second),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dailyBaselineIsolationCase(): array
    {
        $user = User::factory()->create();
        $this->userRiskLimit($user, ['daily_loss_limit' => '1000']);
        $first = $this->dailyBot($user, 'daily_isolation_first');
        $second = $this->dailyBot($user, 'daily_isolation_second');

        $monitor = app(DailyLossMonitor::class);
        $monitor->evaluate($first);
        $monitor->evaluate($second);
        $firstBaseline = $this->baseline($first);
        $secondBaseline = $this->baseline($second);

        RiskLimit::factory()->forBot($first)->create([
            'max_position_notional' => '1000',
            'max_open_orders' => 20,
            'daily_loss_limit' => '15',
        ]);

        $first->update(['realized_pnl' => '-20']);
        $second->update(['realized_pnl' => '-10']);
        $monitor->evaluate($first);
        $monitor->evaluate($second);

        return [
            'label' => 'bot_baselines_are_isolated_from_sibling_bots',
            'first_baseline_after_first_evaluate' => $firstBaseline,
            'second_baseline_after_first_evaluate' => $secondBaseline,
            'first' => $this->botState($first),
            'second' => $this->botState($second),
            'risk_events' => [
                'first' => $this->riskEvents($first),
                'second' => $this->riskEvents($second),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dailyEngineOwnedBaselineCase(): array
    {
        $user = User::factory()->create();
        $userLimit = $this->userRiskLimit($user, ['daily_loss_limit' => '100']);
        $bot = $this->dailyBot($user, 'daily_engine_baseline');

        $monitor = app(DailyLossMonitor::class);
        $monitor->evaluate($bot);
        $baselineAfterFirst = $this->baseline($bot);
        $userRowAfterFirst = $this->riskLimitState($userLimit);

        $bot->update(['realized_pnl' => '-5']);
        $monitor->evaluate($bot);

        return [
            'label' => 'bot_without_override_uses_engine_owned_settings_baseline',
            'baseline_after_first_evaluate' => $baselineAfterFirst,
            'baseline_after_second_evaluate' => $this->baseline($bot),
            'user_row_after_first_evaluate' => $userRowAfterFirst,
            'user_row_after_second_evaluate' => $this->riskLimitState($this->userRiskLimitRow($user)),
            'bot' => $this->botState($bot),
            'risk_events' => $this->riskEvents($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function dailyExplicitOverrideResetCase(): array
    {
        $user = User::factory()->create();
        $this->userRiskLimit($user, ['daily_loss_limit' => '100']);
        $bot = $this->dailyBot($user, 'daily_override_reset', ['realized_pnl' => '-20']);

        $override = RiskLimit::factory()->forBot($bot)->create([
            'max_position_notional' => '1000',
            'max_open_orders' => 20,
            'daily_loss_limit' => '10',
            'day_start' => now('UTC')->subDay()->startOfDay(),
            'day_start_realized_pnl' => '0',
        ]);

        $monitor = app(DailyLossMonitor::class);
        $monitor->evaluate($bot);
        $afterReset = $this->riskLimitState($override->fresh());

        $bot->update(['realized_pnl' => '-35']);
        $monitor->evaluate($bot);

        return [
            'label' => 'explicit_bot_override_resets_daily_baseline_then_breaches',
            'row_after_reset' => $afterReset,
            'row_after_breach' => $this->riskLimitState($override->fresh()),
            'bot' => $this->botState($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function dailyUnrealizedLossCase(): array
    {
        $user = User::factory()->create();
        $this->userRiskLimit($user, ['daily_loss_limit' => '15']);
        $bot = $this->dailyBot($user, 'daily_unrealized');

        $monitor = app(DailyLossMonitor::class);
        $monitor->evaluate($bot);
        $monitor->evaluate($bot, '80');

        return [
            'label' => 'unrealized_loss_at_mark_pauses_bot',
            'mark' => '80',
            'bot' => $this->botState($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function dailyAccountMarkIsolationCase(): array
    {
        $user = User::factory()->create();
        $this->userRiskLimit($user, ['daily_loss_limit' => '25']);
        $btc = $this->dailyBot($user, 'daily_mark_btc', ['settings' => ['mark_price' => '80']]);
        $eth = $this->dailyBot($user, 'daily_mark_eth', [
            'symbol' => 'ETH/USDT',
            'settings' => ['mark_price' => '100'],
        ]);

        $monitor = app(DailyLossMonitor::class);
        $monitor->evaluate($btc, '80');
        $inside = [$btc->fresh()->status->value, $eth->fresh()->status->value];

        $monitor->evaluate($btc, '70');

        return [
            'label' => 'account_wide_loss_uses_each_bots_own_mark',
            'first_mark' => '80',
            'second_mark' => '70',
            'statuses_after_first_mark' => $inside,
            'btc' => $this->botState($btc),
            'eth' => $this->botState($eth),
            'risk_events' => [
                'btc' => $this->riskEvents($btc),
                'eth' => $this->riskEvents($eth),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dailyNoAutoResumeCase(): array
    {
        $user = User::factory()->create();
        $this->userRiskLimit($user, ['daily_loss_limit' => '15']);
        $bot = $this->dailyBot($user, 'daily_no_resume');

        $monitor = app(DailyLossMonitor::class);
        $monitor->evaluate($bot);
        $bot->update(['realized_pnl' => '-20']);
        $monitor->evaluate($bot);
        $afterPause = [
            'status' => $bot->fresh()->status->value,
            'risk_events' => count($this->riskEvents($bot)),
        ];

        $bot->update(['realized_pnl' => '0']);
        $monitor->evaluate($bot);

        return [
            'label' => 'paused_bot_is_not_resumed_after_loss_recovers',
            'after_pause' => $afterPause,
            'bot' => $this->botState($bot),
            'risk_events' => $this->riskEvents($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function dailyTickPausesBeforePlacingCase(): array
    {
        $user = User::factory()->create();
        $this->userRiskLimit($user, ['daily_loss_limit' => '15']);
        $bot = $this->dailyBot($user, 'daily_tick_before_place');

        StrategyConfig::factory()->for($bot)->create([
            'strategy_key' => 'simple-grid',
            'configuration' => [
                'center_price' => '100',
                'levels_per_side' => 1,
                'spacing_type' => 'arithmetic',
                'spacing_value' => '10',
                'order_quantity' => '0.1',
            ],
            'state' => null,
        ]);

        app(DailyLossMonitor::class)->evaluate($bot);

        $fake = new FakeExchangeClient(price: '80');
        app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
            new PaperExchangeClient($fake),
            $fake,
        ));

        $result = app(BotTickPipeline::class)->tick($bot);

        return [
            'label' => 'tick_pauses_before_placing_orders',
            'result' => $this->tickResult($result),
            'bot' => $this->botState($bot),
            'orders' => $this->orders($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function exitGuard(): array
    {
        return [
            'notes' => [
                'precedence is stop-loss, then take-profit, then range',
                'the guard pauses and cancels open orders; it never places a market flatten order and never resumes automatically',
            ],
            'rule_cases' => [
                $this->exitRuleCase('stop_loss_triggers_at_threshold', ['stop_loss_percent' => '10'], '90'),
                $this->exitRuleCase('stop_loss_triggers_below_threshold', ['stop_loss_percent' => '10'], '89'),
                $this->exitRuleCase('stop_loss_does_not_trigger_above_threshold', ['stop_loss_percent' => '10'], '91'),
                $this->exitRuleCase('take_profit_triggers_at_threshold', ['take_profit_percent' => '9'], '109'),
                $this->exitRuleCase('take_profit_does_not_trigger_below_threshold', ['take_profit_percent' => '9'], '108.99'),
                $this->exitRuleCase('range_low_triggers_at_bound', ['range_low' => '90'], '90', average: '0', base: '0'),
                $this->exitRuleCase('range_high_triggers_at_bound', ['range_high' => '110'], '110', average: '0', base: '0'),
                $this->exitRuleCase('stop_loss_precedes_range_exit', ['stop_loss_percent' => '10', 'range_high' => '100'], '89'),
                $this->exitRuleCase('take_profit_precedes_range_exit', ['take_profit_percent' => '9', 'range_high' => '105'], '109'),
                $this->exitRuleCase('zero_inventory_ignores_stop_loss_and_take_profit', ['stop_loss_percent' => '10', 'take_profit_percent' => '9'], '50', base: '0'),
                $this->exitRuleCase('zero_average_ignores_stop_loss', ['stop_loss_percent' => '10'], '50', average: '0'),
                $this->exitRuleCase('empty_rules_never_trigger', [], '50'),
                $this->exitRuleCase('non_positive_range_bound_does_not_trigger', ['range_low' => '0'], '1', average: '0', base: '0'),
                $this->exitRuleCase('blank_rule_values_are_normalized', ['stop_loss_percent' => ' ', 'take_profit_percent' => '', 'range_low' => '', 'range_high' => ' '], '100'),
            ],
            'monitor_cases' => [
                $this->exitMonitorCase(
                    'stop_loss_pauses_cancels_and_keeps_inventory',
                    ['stop_loss_percent' => '10', 'base_inventory' => '1', 'average_entry_price' => '100', 'quote_inventory' => '1000'],
                    '89',
                    [
                        ['client_order_id' => 'exit-stop-buy', 'side' => 'buy', 'price' => '95', 'quantity' => '0.5'],
                        ['client_order_id' => 'exit-stop-sell', 'side' => 'sell', 'price' => '105', 'quantity' => '0.5'],
                    ],
                ),
                $this->exitMonitorCase(
                    'take_profit_pauses_and_cancels',
                    ['take_profit_percent' => '9', 'base_inventory' => '1', 'average_entry_price' => '100'],
                    '110',
                    [['client_order_id' => 'exit-tp-buy', 'side' => 'buy', 'price' => '100', 'quantity' => '0.5']],
                ),
                $this->exitMonitorCase(
                    'range_low_exit_without_inventory',
                    ['range_low' => '90', 'base_inventory' => '0', 'average_entry_price' => '0'],
                    '89',
                    [['client_order_id' => 'exit-range-buy', 'side' => 'buy', 'price' => '89', 'quantity' => '0.5']],
                ),
                $this->exitMonitorCase(
                    'range_high_exit',
                    ['range_high' => '120', 'base_inventory' => '0', 'average_entry_price' => '0'],
                    '121',
                ),
                $this->exitMonitorCase(
                    'inside_rules_does_not_trigger',
                    ['stop_loss_percent' => '10', 'take_profit_percent' => '9', 'range_low' => '80', 'range_high' => '120', 'base_inventory' => '1', 'average_entry_price' => '100'],
                    '100',
                    [['client_order_id' => 'exit-inside-buy', 'side' => 'buy', 'price' => '95', 'quantity' => '0.5']],
                ),
                $this->exitMonitorCase(
                    'stop_loss_precedence_over_range',
                    ['stop_loss_percent' => '10', 'range_high' => '95', 'base_inventory' => '1', 'average_entry_price' => '100'],
                    '89',
                ),
                $this->exitMonitorCase(
                    'paused_bot_not_evaluated',
                    ['stop_loss_percent' => '10', 'base_inventory' => '1', 'average_entry_price' => '100'],
                    '89',
                    [['client_order_id' => 'exit-already-paused', 'side' => 'buy', 'price' => '95', 'quantity' => '0.5']],
                    status: BotStatus::Paused,
                ),
                $this->exitCancelFailureCase(),
            ],
        ];
    }

    /**
     * @param  array<string, string|null>  $rules
     * @return array<string, mixed>
     */
    private function exitRuleCase(
        string $label,
        array $rules,
        string $mark,
        string $average = '100',
        string $base = '1',
    ): array {
        $exitRules = new ExitRules(
            stopLossPercent: $rules['stop_loss_percent'] ?? null,
            takeProfitPercent: $rules['take_profit_percent'] ?? null,
            rangeLow: $rules['range_low'] ?? null,
            rangeHigh: $rules['range_high'] ?? null,
        );

        $signal = $exitRules->evaluate($mark, $average, $base);

        return [
            'label' => $label,
            'rules' => $exitRules->toArray(),
            'empty' => $exitRules->isEmpty(),
            'inputs' => [
                'mark_price' => $mark,
                'average_entry_price' => $average,
                'base_inventory' => $base,
            ],
            'signal' => $signal === null ? null : $this->exitSignalState($signal),
        ];
    }

    /**
     * @param  array<string, string>  $botAttributes
     * @param  list<array<string, string>>  $orders
     * @return array<string, mixed>
     */
    private function exitMonitorCase(
        string $label,
        array $botAttributes,
        string $mark,
        array $orders = [],
        BotStatus $status = BotStatus::Running,
        ?CancelOrder $cancelOrder = null,
    ): array {
        $bot = Bot::factory()->create(array_merge([
            'name' => 'Golden '.$label,
            'status' => $status,
        ], $botAttributes));

        foreach ($orders as $order) {
            $this->openOrder(
                $bot,
                $order['client_order_id'],
                OrderSide::from($order['side']),
                $order['price'],
                $order['quantity'],
            );
        }

        if ($cancelOrder !== null) {
            app()->instance(CancelOrder::class, $cancelOrder);
        }

        try {
            $signal = app(ExitGuardMonitor::class)->evaluate($bot, $mark);
            $second = $cancelOrder === null
                ? app(ExitGuardMonitor::class)->evaluate($bot, $mark)
                : null;
        } finally {
            if ($cancelOrder !== null) {
                app()->forgetInstance(CancelOrder::class);
            }
        }

        return [
            'label' => $label,
            'mark' => $mark,
            'signal' => $signal === null ? null : $this->exitSignalState($signal),
            'second_signal' => $second === null ? null : $this->exitSignalState($second),
            'bot' => $this->botState($bot),
            'orders' => $this->orders($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function exitCancelFailureCase(): array
    {
        $bot = Bot::factory()->create([
            'name' => 'Golden exit cancel failure',
            'status' => BotStatus::Running,
            'stop_loss_percent' => '10',
            'base_inventory' => '1',
            'average_entry_price' => '100',
        ]);

        $this->openOrder($bot, 'exit-cancel-fail-first', OrderSide::Buy, '95', '0.5');
        $this->openOrder($bot, 'exit-cancel-fail-second', OrderSide::Sell, '105', '0.5');

        $stub = $this->failingCancelOrder(['exit-cancel-fail-first']);

        app()->instance(CancelOrder::class, $stub);

        try {
            $signal = app(ExitGuardMonitor::class)->evaluate($bot, '89');
        } finally {
            app()->forgetInstance(CancelOrder::class);
        }

        return [
            'label' => 'cancel_failure_keeps_order_open_and_reports_exit_cancel_failed',
            'mark' => '89',
            'signal' => $signal === null ? null : $this->exitSignalState($signal),
            'bot' => $this->botState($bot),
            'orders' => $this->orders($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function consecutiveFailureTracker(): array
    {
        return [
            'notes' => [
                'the default threshold comes from trading.failure.max_consecutive; cases pass an explicit threshold to stay deterministic',
                'a stopped bot keeps its stopped status but still cancels orders and records events at the threshold',
            ],
            'cases' => [
                $this->failureCase('threshold_2_first_failure_keeps_running', 2, ['first failure']),
                $this->failureCase('threshold_2_second_failure_pauses_and_cancels', 2, ['first failure', 'second failure']),
                $this->failureCase('stopped_bot_stays_stopped_but_cancels', 1, ['stopped failure'], status: BotStatus::Stopped),
                $this->failureSuccessCase(),
                $this->failureCancelFailureCase(),
            ],
        ];
    }

    /**
     * @param  list<string>  $failures
     * @param  array<string, string>  $botAttributes
     * @return array<string, mixed>
     */
    private function failureCase(
        string $label,
        int $threshold,
        array $failures,
        BotStatus $status = BotStatus::Running,
        array $botAttributes = [],
        array $orders = [['client_order_id' => 'failure-open', 'side' => 'buy', 'price' => '100', 'quantity' => '0.5']],
    ): array {
        $bot = Bot::factory()->create(array_merge([
            'name' => 'Golden '.$label,
            'status' => $status,
        ], $botAttributes));

        foreach ($orders as $order) {
            $this->openOrder(
                $bot,
                $order['client_order_id'],
                OrderSide::from($order['side']),
                $order['price'],
                $order['quantity'],
            );
        }

        $tracker = new ConsecutiveFailureTracker(
            app(CancelOrder::class),
            app(ActivityRecorder::class),
            app(RiskEventRecorder::class),
            $threshold,
        );

        $counts = [];

        foreach ($failures as $failure) {
            $counts[] = $tracker->recordFailure($bot, $failure, 'tick');
            $counts[] = $bot->fresh()->status->value;
        }

        return [
            'label' => $label,
            'threshold' => $tracker->threshold(),
            'sequence' => $counts,
            'bot' => $this->botState($bot),
            'orders' => $this->orders($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function failureSuccessCase(): array
    {
        $bot = Bot::factory()->running()->create(['name' => 'Golden failure success']);

        $tracker = new ConsecutiveFailureTracker(
            app(CancelOrder::class),
            app(ActivityRecorder::class),
            app(RiskEventRecorder::class),
            3,
        );

        $tracker->recordFailure($bot, 'first failure', 'tick');
        $tracker->recordFailure($bot, 'second failure', 'tick');
        $tracker->recordSuccess($bot);

        return [
            'label' => 'success_resets_failure_counter',
            'threshold' => $tracker->threshold(),
            'bot' => $this->botState($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function failureCancelFailureCase(): array
    {
        $bot = Bot::factory()->running()->create(['name' => 'Golden failure cancel']);

        $this->openOrder($bot, 'failure-cancel-first', OrderSide::Buy, '100', '0.5');
        $this->openOrder($bot, 'failure-cancel-second', OrderSide::Sell, '110', '0.5');

        app()->instance(CancelOrder::class, $this->failingCancelOrder([
            'failure-cancel-first',
            'failure-cancel-second',
        ]));

        try {
            $tracker = new ConsecutiveFailureTracker(
                app(CancelOrder::class),
                app(ActivityRecorder::class),
                app(RiskEventRecorder::class),
                1,
            );

            $failures = $tracker->recordFailure($bot, 'boom', 'place');
        } finally {
            app()->forgetInstance(CancelOrder::class);
        }

        return [
            'label' => 'cancellation_failures_are_reported_at_threshold',
            'threshold' => $tracker->threshold(),
            'failures' => $failures,
            'bot' => $this->botState($bot),
            'orders' => $this->orders($bot),
            'risk_events' => $this->riskEvents($bot),
            'activity' => $this->activity($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function exchangeAllowlist(): array
    {
        $originalExchanges = config('trading.exchanges');
        $originalDefault = config('trading.default_exchange');

        try {
            return [
                'notes' => [
                    'current PHP validates the allowlist only in StoreBotRequest (creation), not in StartBot or order placement',
                    'the direct_insert cases document that current-PHP gap; task 5.6 must make the Go engine block a non-allowed exchange at start and placement',
                ],
                'creation_validation' => [
                    $this->allowlistCase('allowlisted_exchange_passes', ['binance', 'kraken'], 'binance', 'binance'),
                    $this->allowlistCase('second_allowlisted_exchange_passes', ['binance', 'kraken'], 'binance', 'kraken'),
                    $this->allowlistCase('non_allowlisted_exchange_rejected', ['binance'], 'binance', 'kucoin'),
                    $this->allowlistCase('case_sensitive_exchange_rejected', ['binance'], 'binance', 'Binance'),
                    $this->allowlistCase('blank_uses_allowlisted_default', ['binance', 'kraken'], 'kraken', ''),
                    $this->allowlistCase('blank_default_outside_allowlist_rejected', ['binance'], 'kucoin', ''),
                ],
                'direct_insert_current_php' => [
                    $this->allowlistRuntimeStartCase(),
                    $this->allowlistRuntimePlaceCase(),
                ],
            ];
        } finally {
            config([
                'trading.exchanges' => $originalExchanges,
                'trading.default_exchange' => $originalDefault,
            ]);
        }
    }

    /**
     * @param  list<string>  $allowlist
     * @return array<string, mixed>
     */
    private function allowlistCase(string $label, array $allowlist, string $default, string $input): array
    {
        config([
            'trading.exchanges' => $allowlist,
            'trading.default_exchange' => $default,
        ]);

        $request = StoreBotRequest::createFromBase(Request::create('/bots', 'POST', [
            'exchange' => $input,
        ]));

        $prepare = new \ReflectionMethod($request, 'prepareForValidation');
        $prepare->setAccessible(true);
        $prepare->invoke($request);

        $validator = Validator::make(
            ['exchange' => $request->input('exchange')],
            ['exchange' => (new StoreBotRequest)->rules()['exchange']],
        );

        return [
            'label' => $label,
            'allowlist' => $allowlist,
            'default_exchange' => $default,
            'submitted' => $input,
            'prepared' => (string) $request->input('exchange'),
            'valid' => $validator->passes(),
            'errors' => $validator->errors()->get('exchange'),
        ];
    }

    /** @return array<string, mixed> */
    private function allowlistRuntimeStartCase(): array
    {
        config(['trading.exchanges' => ['binance'], 'trading.default_exchange' => 'binance']);

        $bot = Bot::factory()->create([
            'name' => 'Golden direct kucoin start',
            'exchange' => 'kucoin',
            'mode' => BotMode::Paper,
        ]);

        $fake = new FakeExchangeClient;
        $started = app(StartBot::class)->handle($bot, null, $fake);

        return [
            'label' => 'direct_insert_non_allowlisted_paper_bot_starts_current_php_gap',
            'exchange' => $bot->exchange,
            'allowlist' => ['binance'],
            'status_after' => $started->status->value,
        ];
    }

    /** @return array<string, mixed> */
    private function allowlistRuntimePlaceCase(): array
    {
        config(['trading.exchanges' => ['binance'], 'trading.default_exchange' => 'binance']);

        $bot = Bot::factory()->running()->create([
            'name' => 'Golden direct kucoin place',
            'exchange' => 'kucoin',
            'base_inventory' => '2',
            'quote_inventory' => '1000',
            'fee_rate' => GoldenEngineFixtures::FEE_RATE,
        ]);

        StrategyConfig::factory()->for($bot)->create([
            'strategy_key' => 'simple-grid',
            'configuration' => [
                'center_price' => '100',
                'levels_per_side' => 1,
                'spacing_type' => 'arithmetic',
                'spacing_value' => '10',
                'order_quantity' => '0.1',
            ],
            'state' => null,
        ]);

        $fake = new FakeExchangeClient(price: '100');
        app()->instance(ExchangeClientFactory::class, new ExchangeClientFactory(
            new PaperExchangeClient($fake),
            $fake,
        ));

        $result = app(BotTickPipeline::class)->tick($bot);

        return [
            'label' => 'direct_insert_non_allowlisted_paper_bot_places_current_php_gap',
            'exchange' => $bot->exchange,
            'allowlist' => ['binance'],
            'result' => $this->tickResult($result),
            'orders' => $this->orders($bot),
        ];
    }

    /** @return array<string, mixed> */
    private function ciphertextFixture(): array
    {
        $currentKey = $this->keyBytes(self::TEST_KEY);
        $previousKey = $this->keyBytes(self::TEST_PREVIOUS_KEY);

        $cases = [];

        foreach (self::CIPHERTEXT_CASES as $label => $plaintext) {
            $ciphertext = $this->withEncrypter(
                new Encrypter($currentKey, 'AES-256-CBC'),
                fn (): string => Crypt::encryptString($plaintext),
            );

            $cases[] = $this->ciphertextCase($label, $plaintext, $ciphertext, $currentKey);
        }

        $rotationCiphertext = $this->withEncrypter(
            new Encrypter($previousKey, 'AES-256-CBC'),
            fn (): string => Crypt::encryptString(self::ROTATION_PLAINTEXT),
        );

        $currentOnly = null;

        try {
            $this->withEncrypter(
                new Encrypter($currentKey, 'AES-256-CBC'),
                fn (): string => Crypt::decryptString($rotationCiphertext),
            );
        } catch (Throwable $exception) {
            $currentOnly = $this->error($exception);
        }

        $withPrevious = $this->withEncrypter(
            (new Encrypter($currentKey, 'AES-256-CBC'))->previousKeys([$previousKey]),
            fn (): string => Crypt::decryptString($rotationCiphertext),
        );

        return [
            'metadata' => [
                'format' => self::CIPHERTEXT_FORMAT,
                'clock' => self::CLOCK,
                'cipher' => 'AES-256-CBC',
                'payload_format' => 'base64(json{iv,value,mac,tag})',
                'key_format' => 'base64: decoded to 32 raw bytes',
                'generation' => 'Illuminate\\Encryption\\Encrypter::encryptString (php_serialized=false)',
                'php_serialized' => false,
                'key_source' => 'hardcoded test-only constants in tests/Support/GoldenRiskFixtures.php; never .env or APP_KEY',
                'test_key' => self::TEST_KEY,
                'test_previous_key' => self::TEST_PREVIOUS_KEY,
                'note' => 'ciphertext is generated once and committed; IV randomness means byte-for-byte regeneration is not asserted',
            ],
            'cases' => $cases,
            'rotation' => [
                'label' => 'previous_key_ciphertext_decrypts_only_with_previous_keys',
                'plaintext' => self::ROTATION_PLAINTEXT,
                'encrypted_with_previous_key' => $rotationCiphertext,
                'payload' => $this->payload($rotationCiphertext),
                'decrypt_with_current_key_only' => $currentOnly,
                'decrypt_with_current_and_previous_keys' => $withPrevious,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ciphertextCase(string $label, string $plaintext, string $ciphertext, string $key): array
    {
        $payload = $this->payload($ciphertext);
        $raw = openssl_decrypt(
            base64_decode($payload['value'], true),
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            base64_decode($payload['iv'], true),
        );

        $roundTrip = $this->withEncrypter(
            new Encrypter($key, 'AES-256-CBC'),
            fn (): string => Crypt::decryptString($ciphertext),
        );

        return [
            'label' => $label,
            'plaintext' => $plaintext,
            'ciphertext' => $ciphertext,
            'payload' => $payload,
            'payload_keys' => array_keys($payload),
            'openssl_decrypt_without_unserialize' => $raw,
            'openssl_result_is_plaintext' => $raw === $plaintext,
            'starts_with_php_serialize_prefix' => is_string($raw) && str_starts_with($raw, 's:'),
            'decrypt_string_round_trip' => $roundTrip,
        ];
    }

    /** @return array{iv: string, value: string, mac: string, tag: string} */
    private function payload(string $ciphertext): array
    {
        /** @var array{iv: string, value: string, mac: string, tag: string} $payload */
        $payload = json_decode(base64_decode($ciphertext, true), true, 512, JSON_THROW_ON_ERROR);

        return [
            'iv' => $payload['iv'],
            'value' => $payload['value'],
            'mac' => $payload['mac'],
            'tag' => $payload['tag'],
        ];
    }

    private function keyBytes(string $key): string
    {
        if (! str_starts_with($key, 'base64:')) {
            throw new \RuntimeException('The golden test key must use the Laravel base64: format.');
        }

        $decoded = base64_decode(substr($key, 7), true);

        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException('The golden test key must decode to 32 raw bytes.');
        }

        return $decoded;
    }

    private function withEncrypter(Encrypter $encrypter, callable $callback): mixed
    {
        app()->instance('encrypter', $encrypter);
        Crypt::clearResolvedInstance('encrypter');

        try {
            return $callback();
        } finally {
            app()->forgetInstance('encrypter');
            Crypt::clearResolvedInstance('encrypter');
        }
    }

    private function failingCancelOrder(array $clientOrderIds, string $message = 'exchange refused cancellation'): CancelOrder
    {
        return new class(app(OrderStateMachine::class), app(ActivityRecorder::class), app(ExchangeClientFactory::class), $clientOrderIds, $message) extends CancelOrder
        {
            /**
             * @param  list<string>  $clientOrderIds
             */
            public function __construct(
                OrderStateMachine $stateMachine,
                ActivityRecorder $activity,
                ExchangeClientFactory $clients,
                private readonly array $clientOrderIds,
                private readonly string $message,
            ) {
                parent::__construct($stateMachine, $activity, $clients);
            }

            public function handle(Order $order): Order
            {
                if (in_array($order->client_order_id, $this->clientOrderIds, true)) {
                    throw new \RuntimeException($this->message);
                }

                return parent::handle($order);
            }
        };
    }

    /** @param array<string, string> $attributes */
    private function dailyBot(User $user, string $label, array $attributes = []): Bot
    {
        return Bot::factory()->for($user)->running()->create(array_merge([
            'name' => 'Golden '.$label,
            'symbol' => 'BTC/USDT',
            'base_inventory' => '1',
            'quote_inventory' => '1000',
            'average_entry_price' => '100',
            'realized_pnl' => '0',
            'fee_rate' => '0',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function userRiskLimit(User $user, array $attributes): RiskLimit
    {
        return RiskLimit::factory()->create(array_merge([
            'user_id' => $user->id,
            'scope' => RiskScope::User,
            'bot_id' => null,
        ], $attributes));
    }

    private function userRiskLimitRow(User $user): RiskLimit
    {
        return RiskLimit::query()
            ->where('user_id', $user->id)
            ->where('scope', RiskScope::User->value)
            ->whereNull('bot_id')
            ->firstOrFail();
    }

    /** @return array<string, mixed>|null */
    private function baseline(Bot $bot): ?array
    {
        $settings = $bot->fresh()->settings ?? [];

        return is_array($settings['daily_loss_baseline'] ?? null)
            ? $settings['daily_loss_baseline']
            : null;
    }

    /** @return array<string, mixed> */
    private function riskLimitState(RiskLimit $limit): array
    {
        return [
            'scope' => $limit->scope->value,
            'max_position_notional' => (string) $limit->max_position_notional,
            'max_open_orders' => $limit->max_open_orders,
            'daily_loss_limit' => (string) $limit->daily_loss_limit,
            'day_start' => $limit->day_start?->toIso8601String(),
            'day_start_realized_pnl' => (string) $limit->day_start_realized_pnl,
        ];
    }

    private function openOrder(
        Bot $bot,
        string $clientOrderId,
        OrderSide $side,
        string $price,
        string $quantity,
        string $filledQuantity = '0',
        OrderStatus $status = OrderStatus::Open,
    ): Order {
        return Order::factory()->for($bot)->create([
            'client_order_id' => $clientOrderId,
            'exchange_order_id' => null,
            'mode' => $bot->mode,
            'side' => $side,
            'price' => $price,
            'quantity' => $quantity,
            'filled_quantity' => $filledQuantity,
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function botState(Bot $bot): array
    {
        $bot->refresh();

        return [
            'mode' => $bot->mode->value,
            'status' => $bot->status->value,
            'exchange' => $bot->exchange,
            'symbol' => $bot->symbol,
            'capital_allocation' => (string) $bot->capital_allocation,
            'base_inventory' => (string) $bot->base_inventory,
            'quote_inventory' => (string) $bot->quote_inventory,
            'average_entry_price' => (string) $bot->average_entry_price,
            'realized_pnl' => (string) $bot->realized_pnl,
            'fees_paid' => (string) $bot->fees_paid,
            'consecutive_failures' => $bot->consecutive_failures,
            'stop_loss_percent' => $bot->stop_loss_percent === null ? null : (string) $bot->stop_loss_percent,
            'take_profit_percent' => $bot->take_profit_percent === null ? null : (string) $bot->take_profit_percent,
            'range_low' => $bot->range_low === null ? null : (string) $bot->range_low,
            'range_high' => $bot->range_high === null ? null : (string) $bot->range_high,
            'last_error' => $this->normalizeText($bot->last_error),
            'settings' => $bot->settings ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function tickResult(TickResult $result): array
    {
        return [
            'status' => $result->status,
            'market_price' => $result->marketPrice,
            'orders_placed' => $result->ordersPlaced,
            'fills' => $result->fills,
            'orders_rejected' => $result->ordersRejected,
            'errors' => $result->errors,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function orders(Bot $bot): array
    {
        return $bot->orders()
            ->orderBy('id')
            ->get()
            ->map(fn (Order $order) => [
                'client_order_id' => $this->normalizeText($order->client_order_id),
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
    private function decision(RiskDecision $decision): array
    {
        return [
            'allowed' => $decision->allowed,
            'type' => $decision->type,
            'message' => $decision->message,
            'context' => $decision->context,
            'severity' => $decision->severity->value,
        ];
    }

    /** @return array<string, mixed> */
    private function exitSignalState(ExitSignal $signal): array
    {
        return [
            'rule' => $signal->rule->value,
            'label' => $signal->rule->label(),
            'risk_event_type' => $signal->rule->riskEventType(),
            'mark_price' => $signal->markPrice,
            'threshold' => $signal->threshold,
            'average_entry_price' => $signal->averageEntryPrice,
            'base_inventory' => $signal->baseInventory,
            'bound' => $signal->bound,
            'context' => $signal->context(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function riskEvents(Bot $bot): array
    {
        return $bot->riskEvents()
            ->orderBy('id')
            ->get()
            ->map(fn ($event) => [
                'type' => $event->type,
                'severity' => $event->severity,
                'message' => $this->normalizeText($event->message),
                'context' => $this->sanitizeContext($event->context),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function activity(Bot $bot): array
    {
        return $bot->activityLogs()
            ->orderBy('id')
            ->get()
            ->map(fn ($event) => [
                'type' => $event->type,
                'severity' => $event->severity,
                'message' => $this->normalizeText($event->message),
                'context' => $this->sanitizeContext($event->context),
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed>|null $context */
    private function sanitizeContext(?array $context): array
    {
        if ($context === null || $context === []) {
            return [];
        }

        unset($context['order_id']);

        return $context;
    }

    private function normalizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/\blive bot \d+\b/', 'live bot {bot_id}', $text) ?? $text;
        $text = preg_replace('/\bbot-\d+-/', 'bot-{bot_id}-', $text) ?? $text;
        $text = preg_replace('/\bgrid-\d+-/', 'grid-{grid_id}-', $text) ?? $text;

        return preg_replace('/\bbot \d+\b/', 'bot {bot_id}', $text) ?? $text;
    }

    /** @return array{exception: string, message: string} */
    private function error(Throwable $exception): array
    {
        return [
            'exception' => (new \ReflectionClass($exception))->getShortName(),
            'message' => $this->normalizeText($exception->getMessage()) ?? '',
        ];
    }
}
