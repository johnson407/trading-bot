<?php

namespace App\Domain\Activity;

use App\Domain\Alerts\AlertRecorder;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\User;
use InvalidArgumentException;

class ActivityRecorder
{
    /**
     * Activity types Laravel may write after the engine cutover. Runtime
     * outcomes (stale bots, fills, engine-driven pauses) belong to the engine.
     *
     * @var list<string>
     */
    public const USER_TYPES = [
        'bot.created',
        'bot.updated',
        'bot.exit_guard_updated',
        'bot.started',
        'bot.paused',
        'bot.stopped',
        'bot.archived',
        'bot.restored',
        'kill_switch.activated',
        'order.local_canceled',
        'order.local_cancel_failed',
    ];

    /**
     * Activity types reserved for the engine. Kept here so a user-facing code
     * path can be rejected before it writes a misleading runtime event.
     *
     * @var list<string>
     */
    public const ENGINE_TYPES = [
        'bot.stale',
        'bot.reconciled',
        'bot.reconciliation_conflict',
        'bot.balance_synced',
        'bot.balance_sync_skipped',
        'bot.strategy_stop',
        'bot.strategy_state_invalid',
        'bot.daily_loss_paused',
        'bot.exit_guard_triggered',
        'bot.live_trading_disabled',
        'exchange.failure_threshold',
        'order.canceled',
        'order.cancel_failed',
        'order.filled',
        'order.level_missing',
        'order.min_amount',
        'order.min_notional',
        'order.placement_uncertain',
        'order.rejected',
        'orders.synced',
    ];

    public function __construct(private readonly AlertRecorder $alerts) {}

    /**
     * Record a user-triggered acknowledgement. Engine-reserved types are
     * rejected so a Laravel control action cannot impersonate runtime state.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordUser(
        string $type,
        string $message,
        ?Bot $bot = null,
        ?User $user = null,
        array $context = [],
        string $severity = 'info',
    ): ActivityLog {
        if (! in_array($type, self::USER_TYPES, true)) {
            throw new InvalidArgumentException("Activity type {$type} is not a Laravel user event.");
        }

        return $this->record($type, $message, $bot, $user, $context, $severity);
    }

    /**
     * Record a user- or bot-attributed activity event.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $type,
        string $message,
        ?Bot $bot = null,
        ?User $user = null,
        array $context = [],
        string $severity = 'info',
    ): ActivityLog {
        $user ??= $bot?->user;

        if (! $user instanceof User) {
            throw new InvalidArgumentException('An activity event requires a user or a bot with a user.');
        }

        $activity = ActivityLog::create([
            'user_id' => $user->id,
            'bot_id' => $bot?->id,
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);

        $this->alerts->fromActivity($activity);

        return $activity;
    }
}
