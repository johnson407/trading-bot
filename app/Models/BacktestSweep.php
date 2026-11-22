<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $bot_id
 * @property string $name
 * @property string $symbol
 * @property string $timeframe
 * @property Carbon $start_at
 * @property Carbon $end_at
 * @property array<string, array<int, mixed>> $parameters
 * @property int $total_runs
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Bot|null $bot
 * @property-read Collection<int, BacktestRun> $runs
 * @property-read int|null $runs_count
 * @property-read int|null $pending_runs_count
 * @property-read int|null $running_runs_count
 * @property-read int|null $active_runs_count
 * @property-read int|null $completed_runs_count
 * @property-read int|null $failed_runs_count
 * @property-read User $user
 */
class BacktestSweep extends Model
{
    use HasFactory;

    /** Strategy keys a parameter sweep can evaluate. */
    public const GRID_STRATEGIES = [
        'simple-grid',
        'trailing-grid',
        'martingale-grid',
    ];

    protected $fillable = [
        'user_id',
        'bot_id',
        'name',
        'symbol',
        'timeframe',
        'start_at',
        'end_at',
        'parameters',
        'total_runs',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'parameters' => 'array',
            'total_runs' => 'integer',
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
        return $this->belongsTo(Bot::class);
    }

    /** @return HasMany<BacktestRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(BacktestRun::class, 'sweep_id');
    }

    /**
     * The configured combination cap, clamped so a sweep always has at least
     * two runs to compare.
     */
    public static function maximumRuns(): int
    {
        return max(2, (int) config('backtest.sweep_max_runs', 24));
    }
}
