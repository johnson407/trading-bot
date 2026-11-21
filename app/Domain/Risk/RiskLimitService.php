<?php

namespace App\Domain\Risk;

use App\Domain\Risk\Enums\RiskScope;
use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\RiskEvent;
use App\Models\RiskLimit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class RiskLimitService
{
    public function forUser(User $user): RiskLimit
    {
        return RiskLimit::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'scope' => RiskScope::User->value,
                'bot_id' => null,
            ],
            $this->defaults(),
        );
    }

    /**
     * Effective bot limits: an explicit bot override when present, otherwise
     * the account-wide user row. This never creates a shadowing bot row.
     */
    public function forBot(Bot $bot): RiskLimit
    {
        return $this->botOverride($bot) ?? $this->forUser($bot->user);
    }

    public function botOverride(Bot $bot): ?RiskLimit
    {
        return RiskLimit::query()
            ->where('user_id', $bot->user_id)
            ->where('scope', RiskScope::Bot->value)
            ->where('bot_id', $bot->id)
            ->first();
    }

    /** @return array{bot: RiskLimit, user: RiskLimit} */
    public function limitsFor(Bot $bot): array
    {
        $user = $this->forUser($bot->user);
        $override = $this->botOverride($bot);

        return $override === null ? ['user' => $user] : ['bot' => $override, 'user' => $user];
    }

    /** @param array<string, mixed> $data */
    public function updateForUser(User $user, array $data): RiskLimit
    {
        $limit = $this->forUser($user);
        $limit->update($this->validated($data));

        return $limit->refresh();
    }

    /** @param array<string, mixed> $data */
    public function updateForBot(Bot $bot, array $data): RiskLimit
    {
        $limit = $this->botOverride($bot) ?? RiskLimit::query()->firstOrCreate(
            [
                'user_id' => $bot->user_id,
                'scope' => RiskScope::Bot->value,
                'bot_id' => $bot->id,
            ],
            $this->defaults(),
        );

        $limit->update($this->validated($data));

        return $limit->refresh();
    }

    /** @return Collection<int, RiskEvent> */
    public function eventsForBot(Bot $bot, int $limit = 50): Collection
    {
        return $bot->riskEvents()->latest('created_at')->latest('id')->limit($limit)->get();
    }

    /** @return Collection<int, RiskEvent> */
    public function eventsForUser(User $user, int $limit = 100): Collection
    {
        return $user->riskEvents()->latest('created_at')->latest('id')->limit($limit)->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        $allowed = ['max_position_notional', 'max_open_orders', 'daily_loss_limit'];

        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Unknown risk limit field: {$key}");
            }
        }

        $update = [];

        if (array_key_exists('max_position_notional', $data)) {
            $update['max_position_notional'] = $this->nonNegativeDecimal($data['max_position_notional'], 'max_position_notional');
        }

        if (array_key_exists('daily_loss_limit', $data)) {
            $update['daily_loss_limit'] = $this->nonNegativeDecimal($data['daily_loss_limit'], 'daily_loss_limit');
        }

        if (array_key_exists('max_open_orders', $data)) {
            if (! is_int($data['max_open_orders']) && ! (is_string($data['max_open_orders']) && ctype_digit($data['max_open_orders']))) {
                throw new InvalidArgumentException('max_open_orders must be a non-negative integer.');
            }

            $update['max_open_orders'] = (int) $data['max_open_orders'];

            if ($update['max_open_orders'] < 0) {
                throw new InvalidArgumentException('max_open_orders must not be negative.');
            }
        }

        return $update;
    }

    private function nonNegativeDecimal(mixed $value, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$field} must be a decimal string.");
        }

        $decimal = Decimal::of((string) $value);

        if ($decimal->isNegative()) {
            throw new InvalidArgumentException("{$field} must not be negative.");
        }

        return $decimal->toString();
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'max_position_notional' => (string) config('risk.defaults.max_position_notional', '1000'),
            'max_open_orders' => (int) config('risk.defaults.max_open_orders', 20),
            'daily_loss_limit' => (string) config('risk.defaults.daily_loss_limit', '100'),
            'day_start' => now('UTC')->startOfDay(),
            'day_start_realized_pnl' => '0',
        ];
    }
}
