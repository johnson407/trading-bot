<?php

namespace App\Models;

use App\Domain\Risk\Enums\RiskScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property RiskScope $scope
 * @property int $user_id
 * @property int|null $bot_id
 * @property numeric|null $max_position_notional
 * @property int|null $max_open_orders
 * @property numeric|null $daily_loss_limit
 * @property \Illuminate\Support\Carbon|null $day_start
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property numeric $day_start_realized_pnl
 * @property-read \App\Models\Bot|null $bot
 * @property-read \App\Models\User $user
 * @method static \Database\Factories\RiskLimitFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereBotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereDailyLossLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereDayStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereDayStartRealizedPnl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereMaxOpenOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereMaxPositionNotional($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereScope($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskLimit whereUserId($value)
 * @mixin \Eloquent
 */
class RiskLimit extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope',
        'user_id',
        'bot_id',
        'max_position_notional',
        'max_open_orders',
        'daily_loss_limit',
        'day_start',
        'day_start_realized_pnl',
    ];

    protected function casts(): array
    {
        return [
            'scope' => RiskScope::class,
            'max_position_notional' => 'decimal:16',
            'max_open_orders' => 'integer',
            'daily_loss_limit' => 'decimal:16',
            'day_start' => 'datetime',
            'day_start_realized_pnl' => 'decimal:16',
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
}
