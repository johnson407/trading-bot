<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $bot_id
 * @property string $type
 * @property string $severity
 * @property string $title
 * @property string $message
 * @property array<array-key, mixed>|null $context
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string $dedupe_key
 * @property int $dedupe_bucket
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 * @property-read Bot|null $bot
 * @property-read User $user
 *
 * @method static \Database\Factories\AlertFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert unread()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereBotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereDedupeBucket($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereDedupeKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereReadAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereSeverity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereSourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereSourceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Alert whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Alert extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'bot_id',
        'type',
        'severity',
        'title',
        'message',
        'context',
        'source_type',
        'source_id',
        'dedupe_key',
        'dedupe_bucket',
        'read_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'dedupe_bucket' => 'integer',
            'read_at' => 'datetime',
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

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
