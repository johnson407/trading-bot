<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $exchange
 * @property string $api_key
 * @property string $api_secret
 * @property string|null $passphrase
 * @property bool $testnet
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Bot> $bots
 * @property-read int|null $bots_count
 * @property-read \App\Models\User $user
 * @method static \Database\Factories\ExchangeCredentialFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential whereApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential whereApiSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential whereExchange($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential wherePassphrase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential whereTestnet($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExchangeCredential whereUserId($value)
 * @mixin \Eloquent
 */
class ExchangeCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exchange',
        'api_key',
        'api_secret',
        'passphrase',
        'testnet',
    ];

    protected $hidden = [
        'api_key',
        'api_secret',
        'passphrase',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'passphrase' => 'encrypted',
            'testnet' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Bot, $this> */
    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    public function maskedKey(): string
    {
        $key = (string) $this->api_key;

        if (strlen($key) <= 8) {
            return '****';
        }

        return substr($key, 0, 4).'****'.substr($key, -4);
    }

    /**
     * Safe representation for dashboards and API resources.
     *
     * @return array<string, mixed>
     */
    public function toMaskedArray(): array
    {
        return [
            'id' => $this->id,
            'exchange' => $this->exchange,
            'masked_key' => $this->maskedKey(),
            'testnet' => $this->testnet,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
