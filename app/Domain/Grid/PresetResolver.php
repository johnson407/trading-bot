<?php

namespace App\Domain\Grid;

use App\Domain\Support\Decimal;
use InvalidArgumentException;

/**
 * Turns a symbol-agnostic preset plus the user's capital and center price into
 * the concrete configuration the rest of the setup flow already understands.
 */
class PresetResolver
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('bot_presets', []);
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        $preset = $this->all()[$key] ?? null;

        return is_array($preset) ? $preset : null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array{
     *     strategy_key: string,
     *     level_count: int,
     *     spacing_type: string,
     *     spacing_value: string,
     *     order_quantity: string,
     *     trailing_distance?: string
     * }
     */
    public function values(string $key, Decimal|string $centerPrice, Decimal|string $capitalAllocation): array
    {
        $preset = $this->find($key);

        if ($preset === null) {
            throw new InvalidArgumentException("Unknown bot preset [{$key}].");
        }

        $center = Decimal::of($centerPrice);
        $levels = (int) $preset['levels_per_side'];
        $spacing = GridDerivation::percentToFraction($preset['spacing_percent']);
        $quantity = GridDerivation::orderQuantity(
            $capitalAllocation,
            $preset['utilization'] ?? '0.8',
            $levels,
            $center,
        );

        $values = [
            'strategy_key' => (string) $preset['strategy'],
            'level_count' => $levels,
            'spacing_type' => (string) $preset['spacing_type'],
            'spacing_value' => $spacing->toString(),
            'order_quantity' => $quantity->toString(),
        ];

        $defaults = $preset['strategy_defaults'] ?? [];

        if (isset($defaults['trailing_distance_percent'])) {
            $values['trailing_distance'] = $center
                ->mul(Decimal::of($defaults['trailing_distance_percent']))
                ->div('100')
                ->toString();
        }

        foreach ($defaults as $field => $value) {
            if ($field !== 'trailing_distance_percent' && ! array_key_exists($field, $values)) {
                $values[$field] = $value;
            }
        }

        return $values;
    }
}
