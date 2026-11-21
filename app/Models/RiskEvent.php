<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $bot_id
 * @property string $type
 * @property string $severity
 * @property string $message
 * @property array<array-key, mixed>|null $context
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \App\Models\Bot|null $bot
 * @property-read \App\Models\User $user
 * @method static \Database\Factories\RiskEventFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent whereBotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent whereSeverity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RiskEvent whereUserId($value)
 * @mixin \Eloquent
 */
class RiskEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'bot_id',
        'type',
        'severity',
        'message',
        'context',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
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
