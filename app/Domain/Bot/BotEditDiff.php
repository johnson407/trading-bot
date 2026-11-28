<?php

namespace App\Domain\Bot;

use App\Domain\Support\Decimal;
use App\Models\Bot;
use App\Models\StrategyConfig;
use Throwable;

/**
 * Compares a bot's current editable settings with a proposed update.
 *
 * The result is a flat list so the edit page can render one row per changed
 * field without knowing how the strategy configuration is nested.
 */
final class BotEditDiff
{
    /**
     * @var array<int, array{key: string, label: string, type: string, source: string, field: string}>
     */
    private const FIELDS = [
        ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'source' => 'bot', 'field' => 'name'],
        ['key' => 'capital_allocation', 'label' => 'Capital allocation', 'type' => 'money', 'source' => 'bot', 'field' => 'capital_allocation'],
        ['key' => 'fee_rate', 'label' => 'Fee rate', 'type' => 'rate', 'source' => 'bot', 'field' => 'fee_rate'],
        ['key' => 'strategy.center_price', 'label' => 'Center price', 'type' => 'money', 'source' => 'strategy', 'field' => 'center_price'],
        ['key' => 'strategy.levels_per_side', 'label' => 'Levels per side', 'type' => 'integer', 'source' => 'strategy', 'field' => 'levels_per_side'],
        ['key' => 'strategy.spacing_type', 'label' => 'Spacing type', 'type' => 'enum', 'source' => 'strategy', 'field' => 'spacing_type'],
        ['key' => 'strategy.spacing_value', 'label' => 'Spacing value', 'type' => 'money', 'source' => 'strategy', 'field' => 'spacing_value'],
        ['key' => 'strategy.order_quantity', 'label' => 'Order quantity', 'type' => 'quantity', 'source' => 'strategy', 'field' => 'order_quantity'],
        ['key' => 'strategy.trailing_distance', 'label' => 'Trailing distance', 'type' => 'money', 'source' => 'strategy', 'field' => 'trailing_distance'],
        ['key' => 'strategy.base_order_quantity', 'label' => 'Base order quantity', 'type' => 'quantity', 'source' => 'strategy', 'field' => 'base_order_quantity'],
        ['key' => 'strategy.size_multiplier', 'label' => 'Size multiplier', 'type' => 'number', 'source' => 'strategy', 'field' => 'size_multiplier'],
        ['key' => 'strategy.max_order_quantity', 'label' => 'Max order quantity', 'type' => 'quantity', 'source' => 'strategy', 'field' => 'max_order_quantity'],
        ['key' => 'strategy.max_increases', 'label' => 'Max increases', 'type' => 'integer', 'source' => 'strategy', 'field' => 'max_increases'],
        ['key' => 'strategy.min_spacing', 'label' => 'Min spacing', 'type' => 'money', 'source' => 'strategy', 'field' => 'min_spacing'],
        ['key' => 'strategy.max_spacing', 'label' => 'Max spacing', 'type' => 'money', 'source' => 'strategy', 'field' => 'max_spacing'],
        ['key' => 'strategy.volatility_multiplier', 'label' => 'Volatility multiplier', 'type' => 'number', 'source' => 'strategy', 'field' => 'volatility_multiplier'],
        ['key' => 'strategy.recalculation_interval_seconds', 'label' => 'Recalculation interval', 'type' => 'integer', 'source' => 'strategy', 'field' => 'recalculation_interval_seconds'],
        ['key' => 'strategy.spacing_hysteresis_percent', 'label' => 'Spacing hysteresis', 'type' => 'rate', 'source' => 'strategy', 'field' => 'spacing_hysteresis_percent'],
    ];

    /**
     * @param  array<string, mixed>  $proposed
     * @return array<int, array{key: string, label: string, type: string, from: mixed, to: mixed}>
     */
    public static function between(Bot $bot, array $proposed): array
    {
        $strategyConfig = $bot->relationLoaded('strategyConfig')
            ? $bot->getRelation('strategyConfig')
            : $bot->strategyConfig()->first();

        $currentConfiguration = $strategyConfig instanceof StrategyConfig
            ? $strategyConfig->configuration
            : [];

        if (! is_array($currentConfiguration)) {
            $currentConfiguration = [];
        }
        $proposedConfiguration = array_key_exists('configuration', $proposed)
            ? (is_array($proposed['configuration']) ? $proposed['configuration'] : [])
            : $currentConfiguration;

        $changes = [];

        foreach (self::FIELDS as $field) {
            if ($field['source'] === 'bot') {
                $current = $bot->{$field['field']};
                $next = array_key_exists($field['field'], $proposed)
                    ? $proposed[$field['field']]
                    : $current;
            } else {
                $current = array_key_exists($field['field'], $currentConfiguration)
                    ? $currentConfiguration[$field['field']]
                    : null;
                $next = array_key_exists($field['field'], $proposedConfiguration)
                    ? $proposedConfiguration[$field['field']]
                    : null;
            }

            $currentCanonical = self::canonical($current, $field['type']);
            $nextCanonical = self::canonical($next, $field['type']);

            if ($currentCanonical === $nextCanonical) {
                continue;
            }

            $changes[] = [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'from' => $currentCanonical === '' ? null : $currentCanonical,
                'to' => $nextCanonical === '' ? null : $nextCanonical,
            ];
        }

        return $changes;
    }

    /**
     * Compare the full strategy configuration, including fields the display
     * diff does not know about, so a future parameter cannot be silently
     * dropped from persistence.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $proposed
     */
    public static function configurationsDiffer(array $current, array $proposed): bool
    {
        return self::canonicalConfiguration($current) !== self::canonicalConfiguration($proposed);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private static function canonicalConfiguration(array $configuration): array
    {
        $canonical = [];

        foreach ($configuration as $key => $value) {
            if (is_array($value)) {
                $canonical[$key] = self::canonicalConfiguration($value);

                continue;
            }

            if ($value === null) {
                $canonical[$key] = null;

                continue;
            }

            if (is_bool($value)) {
                $canonical[$key] = $value;

                continue;
            }

            if (is_int($value) || is_float($value)) {
                $canonical[$key] = (string) $value;

                continue;
            }

            $trimmed = trim((string) $value);

            if ($trimmed === '') {
                $canonical[$key] = null;

                continue;
            }

            try {
                $canonical[$key] = Decimal::of($trimmed)->toString();
            } catch (Throwable) {
                $canonical[$key] = $trimmed;
            }
        }

        ksort($canonical);

        return $canonical;
    }

    private static function canonical(mixed $value, string $type): string
    {
        if ($value === null) {
            return '';
        }

        if ($type === 'integer') {
            return is_numeric($value) ? (string) (int) $value : trim((string) $value);
        }

        if (in_array($type, ['money', 'quantity', 'rate', 'number'], true)) {
            try {
                return Decimal::of($value)->toString();
            } catch (Throwable) {
                return trim((string) $value);
            }
        }

        return trim((string) $value);
    }
}
