<?php

namespace App\Models;

use App\Domain\Bot\Enums\BotMode;
use App\Domain\Bot\Enums\BotStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $exchange_credential_id
 * @property string $name
 * @property string $exchange
 * @property string $symbol
 * @property string $strategy_key
 * @property BotMode $mode
 * @property BotStatus $status
 * @property BotStatus $desired_status
 * @property numeric $capital_allocation
 * @property numeric $base_inventory
 * @property numeric $quote_inventory
 * @property numeric $average_entry_price
 * @property numeric $realized_pnl
 * @property numeric $fees_paid
 * @property numeric $fee_rate
 * @property numeric|null $stop_loss_percent
 * @property numeric|null $take_profit_percent
 * @property numeric|null $range_low
 * @property numeric|null $range_high
 * @property int $tick_interval_seconds
 * @property int $consecutive_failures
 * @property Carbon|null $last_tick_at
 * @property Carbon|null $last_successful_tick_at
 * @property string|null $last_error
 * @property array<array-key, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Grid|null $activeGrid
 * @property-read Collection<int, ActivityLog> $activityLogs
 * @property-read int|null $activity_logs_count
 * @property-read Collection<int, Alert> $alerts
 * @property-read int|null $alerts_count
 * @property-read ExchangeCredential|null $credential
 * @property-read Collection<int, Fill> $fills
 * @property-read int|null $fills_count
 * @property-read Collection<int, Grid> $grids
 * @property-read int|null $grids_count
 * @property-read Collection<int, InventoryLedgerEntry> $inventoryLedgerEntries
 * @property-read int|null $inventory_ledger_entries_count
 * @property-read Collection<int, Order> $orders
 * @property-read int|null $orders_count
 * @property-read Collection<int, RiskEvent> $riskEvents
 * @property-read int|null $risk_events_count
 * @property-read RiskLimit|null $riskLimit
 * @property-read StrategyConfig|null $strategyConfig
 * @property-read User $user
 * @property string|null $unrealized_pnl
 *
 * @method static \Database\Factories\BotFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot running()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereAverageEntryPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereBaseInventory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereCapitalAllocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereConsecutiveFailures($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereExchange($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereExchangeCredentialId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereFeeRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereFeesPaid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereLastError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereLastSuccessfulTickAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereLastTickAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereQuoteInventory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereRealizedPnl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereStrategyKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereSymbol($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereTickIntervalSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bot whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Bot extends Model
{
    use HasFactory;

    /**
     * Settings owned by the Go engine. Laravel merges around these keys and
     * never overwrites them with user-editable values.
     *
     * @var list<string>
     */
    public const ENGINE_SETTINGS = [
        'mark_price',
        'recent_prices',
        'daily_loss_baseline',
    ];

    protected $fillable = [
        'user_id',
        'exchange_credential_id',
        'name',
        'exchange',
        'symbol',
        'strategy_key',
        'mode',
        'desired_status',
        'capital_allocation',
        'fee_rate',
        'stop_loss_percent',
        'take_profit_percent',
        'range_low',
        'range_high',
        'tick_interval_seconds',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'mode' => BotMode::class,
            'status' => BotStatus::class,
            'desired_status' => BotStatus::class,
            'capital_allocation' => 'decimal:16',
            'base_inventory' => 'decimal:16',
            'quote_inventory' => 'decimal:16',
            'average_entry_price' => 'decimal:16',
            'realized_pnl' => 'decimal:16',
            'fees_paid' => 'decimal:16',
            'fee_rate' => 'decimal:8',
            'stop_loss_percent' => 'decimal:4',
            'take_profit_percent' => 'decimal:4',
            'range_low' => 'decimal:8',
            'range_high' => 'decimal:8',
            'tick_interval_seconds' => 'integer',
            'consecutive_failures' => 'integer',
            'last_tick_at' => 'datetime',
            'last_successful_tick_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    /**
     * Merge non-engine settings without clobbering engine-owned keys.
     *
     * @param  array<string, mixed>  $settings
     */
    public function mergeSettings(array $settings): void
    {
        $current = $this->settings ?? [];

        foreach ($settings as $key => $value) {
            if (in_array($key, self::ENGINE_SETTINGS, true)) {
                continue;
            }

            $current[$key] = $value;
        }

        $this->settings = $current;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ExchangeCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(ExchangeCredential::class, 'exchange_credential_id');
    }

    /** @return HasOne<StrategyConfig, $this> */
    public function strategyConfig(): HasOne
    {
        return $this->hasOne(StrategyConfig::class);
    }

    /** @return HasMany<Grid, $this> */
    public function grids(): HasMany
    {
        return $this->hasMany(Grid::class);
    }

    /** @return HasOne<Grid, $this> */
    public function activeGrid(): HasOne
    {
        return $this->hasOne(Grid::class)->where('status', 'active')->latestOfMany();
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Fill, $this> */
    public function fills(): HasMany
    {
        return $this->hasMany(Fill::class);
    }

    /** @return HasOne<RiskLimit, $this> */
    public function riskLimit(): HasOne
    {
        return $this->hasOne(RiskLimit::class);
    }

    /** @return HasMany<RiskEvent, $this> */
    public function riskEvents(): HasMany
    {
        return $this->hasMany(RiskEvent::class);
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** @return HasMany<InventoryLedgerEntry, $this> */
    public function inventoryLedgerEntries(): HasMany
    {
        return $this->hasMany(InventoryLedgerEntry::class);
    }

    /** @return HasMany<BacktestRun, $this> */
    public function backtestRuns(): HasMany
    {
        return $this->hasMany(BacktestRun::class);
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function scopeRunning($query)
    {
        return $query->where('status', BotStatus::Running->value);
    }

    public function isLive(): bool
    {
        return $this->mode === BotMode::Live;
    }

    public function isRunning(): bool
    {
        return $this->status === BotStatus::Running;
    }
}
