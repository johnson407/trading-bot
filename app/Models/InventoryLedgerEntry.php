<?php

namespace App\Models;

use App\Domain\Order\Enums\OrderSide;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bot_id
 * @property int|null $fill_id
 * @property OrderSide $side
 * @property numeric $quantity
 * @property numeric $price
 * @property numeric $base_delta
 * @property numeric $quote_delta
 * @property numeric $base_balance
 * @property numeric $quote_balance
 * @property numeric $average_entry_price
 * @property numeric $realized_pnl
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \App\Models\Bot $bot
 * @property-read \App\Models\Fill|null $fillRecord
 * @method static \Database\Factories\InventoryLedgerEntryFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereAverageEntryPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereBaseBalance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereBaseDelta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereBotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereFillId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereQuoteBalance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereQuoteDelta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereRealizedPnl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryLedgerEntry whereSide($value)
 * @mixin \Eloquent
 */
class InventoryLedgerEntry extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'bot_id',
        'fill_id',
        'side',
        'quantity',
        'price',
        'base_delta',
        'quote_delta',
        'base_balance',
        'quote_balance',
        'average_entry_price',
        'realized_pnl',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,
            'quantity' => 'decimal:16',
            'price' => 'decimal:16',
            'base_delta' => 'decimal:16',
            'quote_delta' => 'decimal:16',
            'base_balance' => 'decimal:16',
            'quote_balance' => 'decimal:16',
            'average_entry_price' => 'decimal:16',
            'realized_pnl' => 'decimal:16',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return BelongsTo<Fill, $this> */
    public function fillRecord(): BelongsTo
    {
        return $this->belongsTo(Fill::class);
    }
}
