<?php

namespace App\Models;

use App\Domain\Grid\Enums\GridLevelState;
use App\Domain\Order\Enums\OrderSide;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $grid_id
 * @property int $sequence
 * @property numeric $price
 * @property OrderSide $side
 * @property GridLevelState $state
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Grid $grid
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @method static \Database\Factories\GridLevelFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel whereGridId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel whereSide($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GridLevel whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class GridLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'grid_id',
        'sequence',
        'price',
        'side',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'price' => 'decimal:16',
            'side' => OrderSide::class,
            'state' => GridLevelState::class,
        ];
    }

    /** @return BelongsTo<Grid, $this> */
    public function grid(): BelongsTo
    {
        return $this->belongsTo(Grid::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
