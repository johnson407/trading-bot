<?php

namespace App\Models;

use App\Domain\Backtest\Enums\BacktestEndReason;
use App\Domain\Backtest\Enums\BacktestStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $bot_id
 * @property int|null $sweep_id
 * @property string $name
 * @property string $exchange
 * @property string $symbol
 * @property string $strategy_key
 * @property string $timeframe
 * @property Carbon $start_at
 * @property Carbon $end_at
 * @property numeric $capital_allocation
 * @property numeric $fee_rate
 * @property array<string, mixed> $config_snapshot
 * @property BacktestStatus $status
 * @property BacktestEndReason|null $end_reason
 * @property int $candles_processed
 * @property int $progress
 * @property array<string, mixed>|null $metrics
 * @property array<int, array<string, mixed>>|null $equity_curve
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bot|null $bot
 * @property-read Collection<int, Bot> $derivedBots
 * @property-read Collection<int, BacktestFill> $fills
 * @property-read BacktestSweep|null $sweep
 * @property-read User $user
 */
class BacktestRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bot_id',
        'sweep_id',
        'name',
        'exchange',
        'symbol',
        'strategy_key',
        'timeframe',
        'start_at',
        'end_at',
        'capital_allocation',
        'fee_rate',
        'config_snapshot',
        'status',
        'end_reason',
        'candles_processed',
        'progress',
        'metrics',
        'equity_curve',
        'error',
        'started_at',
        'finished_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (BacktestRun $run): void {
            if ($run->exists && $run->isDirty('config_snapshot')) {
                throw new LogicException('A backtest snapshot is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'capital_allocation' => 'decimal:16',
            'fee_rate' => 'decimal:8',
            'config_snapshot' => 'array',
            'status' => BacktestStatus::class,
            'end_reason' => BacktestEndReason::class,
            'candles_processed' => 'integer',
            'progress' => 'integer',
            'metrics' => 'array',
            'equity_curve' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class)->withTrashed();
    }

    /** @return HasMany<Bot, $this> */
    public function derivedBots(): HasMany
    {
        return $this->hasMany(Bot::class, 'source_backtest_run_id');
    }

    /** @return HasMany<BacktestFill, $this> */
    public function fills(): HasMany
    {
        return $this->hasMany(BacktestFill::class);
    }

    /** @return BelongsTo<BacktestSweep, $this> */
    public function sweep(): BelongsTo
    {
        return $this->belongsTo(BacktestSweep::class, 'sweep_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [BacktestStatus::Pending, BacktestStatus::Running], true);
    }
}
