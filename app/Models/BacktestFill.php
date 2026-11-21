<?php

namespace App\Models;

use App\Domain\Order\Enums\OrderSide;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $backtest_run_id
 * @property int $sequence
 * @property OrderSide $side
 * @property numeric $price
 * @property numeric $quantity
 * @property numeric $fee
 * @property string $fee_currency
 * @property Carbon $filled_at
 * @property int|null $grid_level_sequence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BacktestRun $run
 */
class BacktestFill extends Model
{
    use HasFactory;

    protected $fillable = [
        'backtest_run_id',
        'sequence',
        'side',
        'price',
        'quantity',
        'fee',
        'fee_currency',
        'filled_at',
        'grid_level_sequence',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'side' => OrderSide::class,
            'price' => 'decimal:16',
            'quantity' => 'decimal:16',
            'fee' => 'decimal:16',
            'filled_at' => 'datetime',
            'grid_level_sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<BacktestRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
