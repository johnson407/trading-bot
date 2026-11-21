<?php

namespace App\Models;

use App\Domain\Bot\Enums\BotMode;
use App\Domain\Bot\Enums\BotStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property numeric $capital_allocation
 * @property numeric $base_inventory
 * @property numeric $quote_inventory
 * @property numeric $average_entry_price
 * @property numeric $realized_pnl
 * @property numeric $fees_paid
 * @property numeric $fee_rate
 * @property int $tick_interval_seconds
 * @property int $consecutive_failures
 * @property \Illuminate\Support\Carbon|null $last_tick_at
 * @property \Illuminate\Support\Carbon|null $last_successful_tick_at
 * @property string|null $last_error
 * @property array<array-key, mixed>|null $settings
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Grid|null $activeGrid
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ActivityLog> $activityLogs
 * @property-read int|null $activity_logs_count
 * @property-read \App\Models\ExchangeCredential|null $credential
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Fill> $fills
 * @property-read int|null $fills_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Grid> $grids
 * @property-read int|null $grids_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryLedgerEntry> $inventoryLedgerEntries
 * @property-read int|null $inventory_ledger_entries_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RiskEvent> $riskEvents
 * @property-read int|null $risk_events_count
 * @property-read \App\Models\RiskLimit|null $riskLimit
 * @property-read \App\Models\StrategyConfig|null $strategyConfig
 * @property-read \App\Models\User $user
 * @property string|null $unrealized_pnl
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
 * @mixin \Eloquent
 */
class Bot extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exchange_credential_id',
        'name',
        'exchange',
        'symbol',
        'strategy_key',
        'mode',
        'status',
        'capital_allocation',
        'base_inventory',
        'quote_inventory',
        'average_entry_price',
        'realized_pnl',
        'fees_paid',
        'fee_rate',
        'tick_interval_seconds',
        'consecutive_failures',
        'last_tick_at',
        'last_successful_tick_at',
        'last_error',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'mode' => BotMode::class,
            'status' => BotStatus::class,
            'capital_allocation' => 'decimal:16',
            'base_inventory' => 'decimal:16',
            'quote_inventory' => 'decimal:16',
            'average_entry_price' => 'decimal:16',
            'realized_pnl' => 'decimal:16',
            'fees_paid' => 'decimal:16',
            'fee_rate' => 'decimal:8',
            'tick_interval_seconds' => 'integer',
            'consecutive_failures' => 'integer',
            'last_tick_at' => 'datetime',
            'last_successful_tick_at' => 'datetime',
            'settings' => 'array',
        ];
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
