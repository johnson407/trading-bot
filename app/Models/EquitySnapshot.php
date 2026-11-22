<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $captured_at
 * @property string $equity
 * @property string $quote_value
 * @property string $base_value
 * @property string $realized_pnl
 * @property string $unrealized_pnl
 * @property string $fees_paid
 * @property int $bot_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Database\Factories\EquitySnapshotFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereBotCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereCapturedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereEquity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereFeesPaid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereQuoteValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereRealizedPnl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereUnrealizedPnl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EquitySnapshot whereUserId($value)
 *
 * @mixin \Eloquent
 */
class EquitySnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'captured_at',
        'equity',
        'quote_value',
        'base_value',
        'realized_pnl',
        'unrealized_pnl',
        'fees_paid',
        'bot_count',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'equity' => 'decimal:8',
            'quote_value' => 'decimal:8',
            'base_value' => 'decimal:8',
            'realized_pnl' => 'decimal:8',
            'unrealized_pnl' => 'decimal:8',
            'fees_paid' => 'decimal:8',
            'bot_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
