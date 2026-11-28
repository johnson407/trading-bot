<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $bot_id
 * @property string $type
 * @property string $severity
 * @property string $message
 * @property array<array-key, mixed>|null $context
 * @property Carbon|null $alert_processed_at
 * @property int $alert_ingest_attempts
 * @property Carbon|null $alert_next_attempt_at
 * @property Carbon $created_at
 * @property-read Bot|null $bot
 * @property-read User $user
 *
 * @method static \Database\Factories\ActivityLogFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereBotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereSeverity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog whereUserId($value)
 *
 * @mixin \Eloquent
 */
class ActivityLog extends Model
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
        'alert_processed_at',
        'alert_ingest_attempts',
        'alert_next_attempt_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'alert_processed_at' => 'datetime',
            'alert_ingest_attempts' => 'integer',
            'alert_next_attempt_at' => 'datetime',
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
        return $this->belongsTo(Bot::class)->withTrashed();
    }
}
