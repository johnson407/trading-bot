<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ActivityLog> $activityLogs
 * @property-read int|null $activity_logs_count
 * @property-read Collection<int, Alert> $alerts
 * @property-read int|null $alerts_count
 * @property-read Collection<int, Bot> $bots
 * @property-read int|null $bots_count
 * @property-read Collection<int, ExchangeCredential> $exchangeCredentials
 * @property-read int|null $exchange_credentials_count
 * @property-read Collection<int, EquitySnapshot> $equitySnapshots
 * @property-read int|null $equity_snapshots_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, RiskEvent> $riskEvents
 * @property-read int|null $risk_events_count
 * @property-read Collection<int, RiskLimit> $riskLimits
 * @property-read int|null $risk_limits_count
 * @property-read Collection<int, Alert> $unreadAlerts
 * @property-read int|null $unread_alerts_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<Bot, $this> */
    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    /** @return HasMany<ExchangeCredential, $this> */
    public function exchangeCredentials(): HasMany
    {
        return $this->hasMany(ExchangeCredential::class);
    }

    /** @return HasMany<RiskLimit, $this> */
    public function riskLimits(): HasMany
    {
        return $this->hasMany(RiskLimit::class);
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** @return HasMany<RiskEvent, $this> */
    public function riskEvents(): HasMany
    {
        return $this->hasMany(RiskEvent::class);
    }

    /** @return HasMany<BacktestRun, $this> */
    public function backtestRuns(): HasMany
    {
        return $this->hasMany(BacktestRun::class);
    }

    /** @return HasMany<BacktestSweep, $this> */
    public function backtestSweeps(): HasMany
    {
        return $this->hasMany(BacktestSweep::class);
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /** @return HasMany<Alert, $this> */
    public function unreadAlerts(): HasMany
    {
        return $this->hasMany(Alert::class)->whereNull('read_at');
    }

    /** @return HasMany<EquitySnapshot, $this> */
    public function equitySnapshots(): HasMany
    {
        return $this->hasMany(EquitySnapshot::class);
    }
}
