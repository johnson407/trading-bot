<?php

namespace App\Models;

use App\Domain\Bot\Enums\BotMode;
use App\Domain\Order\Enums\OrderSide;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $bot_id
 * @property int|null $grid_id
 * @property int|null $grid_level_id
 * @property string $client_order_id
 * @property string|null $exchange_order_id
 * @property BotMode $mode
 * @property OrderSide $side
 * @property OrderType $type
 * @property numeric $price
 * @property numeric $quantity
 * @property numeric $filled_quantity
 * @property OrderStatus $status
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $requested_at
 * @property \Illuminate\Support\Carbon|null $opened_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bot $bot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Fill> $fills
 * @property-read int|null $fills_count
 * @property-read \App\Models\Grid|null $grid
 * @property-read \App\Models\GridLevel|null $gridLevel
 * @method static \Database\Factories\OrderFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereBotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereClientOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereClosedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereExchangeOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereFilledQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereGridId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereGridLevelId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereLastError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereOpenedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereRequestedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereSide($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_id',
        'grid_id',
        'grid_level_id',
        'client_order_id',
        'exchange_order_id',
        'mode',
        'side',
        'type',
        'price',
        'quantity',
        'filled_quantity',
        'status',
        'last_error',
        'requested_at',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => BotMode::class,
            'side' => OrderSide::class,
            'type' => OrderType::class,
            'price' => 'decimal:16',
            'quantity' => 'decimal:16',
            'filled_quantity' => 'decimal:16',
            'status' => OrderStatus::class,
            'requested_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return BelongsTo<Grid, $this> */
    public function grid(): BelongsTo
    {
        return $this->belongsTo(Grid::class);
    }

    /** @return BelongsTo<GridLevel, $this> */
    public function gridLevel(): BelongsTo
    {
        return $this->belongsTo(GridLevel::class);
    }

    /** @return HasMany<Fill, $this> */
    public function fills(): HasMany
    {
        return $this->hasMany(Fill::class);
    }

    public function remainingQuantity(): string
    {
        return \App\Domain\Support\Decimal::of($this->quantity)->sub($this->filled_quantity)->toString();
    }
}
