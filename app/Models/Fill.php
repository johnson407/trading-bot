<?php

namespace App\Models;

use App\Domain\Order\Enums\OrderSide;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bot_id
 * @property int $order_id
 * @property string $exchange
 * @property string $exchange_fill_id
 * @property OrderSide $side
 * @property numeric $price
 * @property numeric $quantity
 * @property numeric $fee
 * @property string|null $fee_currency
 * @property \Illuminate\Support\Carbon $filled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bot $bot
 * @property-read \App\Models\Order $order
 * @method static \Database\Factories\FillFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereBotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereExchange($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereExchangeFillId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereFee($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereFeeCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereFilledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereSide($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Fill whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Fill extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_id',
        'order_id',
        'exchange',
        'exchange_fill_id',
        'side',
        'price',
        'quantity',
        'fee',
        'fee_currency',
        'filled_at',
    ];

    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'price' => 'decimal:16',
            'quantity' => 'decimal:16',
            'fee' => 'decimal:16',
            'filled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
