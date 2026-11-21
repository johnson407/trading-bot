<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $exchange
 * @property string $symbol
 * @property string $timeframe
 * @property Carbon $open_time
 * @property numeric $open
 * @property numeric $high
 * @property numeric $low
 * @property numeric $close
 * @property numeric $volume
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MarketCandle extends Model
{
    use HasFactory;

    protected $fillable = [
        'exchange',
        'symbol',
        'timeframe',
        'open_time',
        'open',
        'high',
        'low',
        'close',
        'volume',
    ];

    protected function casts(): array
    {
        return [
            'open_time' => 'datetime',
            'open' => 'decimal:16',
            'high' => 'decimal:16',
            'low' => 'decimal:16',
            'close' => 'decimal:16',
            'volume' => 'decimal:16',
        ];
    }
}
