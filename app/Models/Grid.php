<?php

namespace App\Models;

use App\Domain\Grid\Enums\GridSpacingType;
use App\Domain\Grid\Enums\GridStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $bot_id
 * @property numeric $center_price
 * @property GridSpacingType $spacing_type
 * @property int $level_count
 * @property numeric $spacing_value
 * @property GridStatus $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bot $bot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\GridLevel> $levels
 * @property-read int|null $levels_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @method static \Database\Factories\GridFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid whereBotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid whereCenterPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid whereLevelCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid whereSpacingType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid whereSpacingValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Grid whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Grid extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_id',
        'center_price',
        'spacing_type',
        'level_count',
        'spacing_value',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'center_price' => 'decimal:16',
            'spacing_type' => GridSpacingType::class,
            'level_count' => 'integer',
            'spacing_value' => 'decimal:16',
            'status' => GridStatus::class,
        ];
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return HasMany<GridLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(GridLevel::class)->orderBy('sequence');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
