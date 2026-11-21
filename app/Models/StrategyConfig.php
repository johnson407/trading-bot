<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bot_id
 * @property string $strategy_key
 * @property array<array-key, mixed> $configuration
 * @property array<array-key, mixed>|null $state
 * @property int $state_version
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bot $bot
 * @method static \Database\Factories\StrategyConfigFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig whereBotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig whereConfiguration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig whereStateVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig whereStrategyKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StrategyConfig whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class StrategyConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_id',
        'strategy_key',
        'configuration',
        'state',
        'state_version',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'state' => 'array',
            'state_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
