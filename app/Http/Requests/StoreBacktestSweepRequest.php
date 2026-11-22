<?php

namespace App\Http\Requests;

use App\Domain\Backtest\Enums\Timeframe;
use App\Domain\Support\Decimal;
use App\Models\BacktestSweep;
use App\Models\Bot;
use App\Services\Backtest\BacktestPeriodValidator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBacktestSweepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $decimal = function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                $fail("The {$attribute} must be a decimal string.");

                return;
            }

            $value = (string) $value;

            if (preg_match('/^\d+(?:\.(\d+))?$/', $value, $matches) !== 1) {
                $fail("The {$attribute} must be a positive decimal.");

                return;
            }

            if (isset($matches[1]) && strlen($matches[1]) > Decimal::SCALE) {
                $fail("The {$attribute} may have at most ".Decimal::SCALE.' decimal places.');

                return;
            }

            if (! Decimal::of($value)->isPositive()) {
                $fail("The {$attribute} must be a positive decimal.");
            }
        };

        $integer = function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                $fail("The {$attribute} must be an integer.");

                return;
            }

            $value = (string) $value;

            if (preg_match('/^\d+$/', $value) !== 1) {
                $fail("The {$attribute} must be an integer.");

                return;
            }

            $level = (int) $value;

            if ($level < 1 || $level > 50) {
                $fail("The {$attribute} must be between 1 and 50.");
            }
        };

        return [
            'bot_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                    $fail('Select one of your bots.');

                    return;
                }

                $bot = Bot::query()
                    ->whereKey($value)
                    ->where('user_id', $this->user()?->id)
                    ->first();

                if ($bot === null) {
                    $fail('Select one of your bots.');

                    return;
                }

                if (! in_array($bot->strategy_key, BacktestSweep::GRID_STRATEGIES, true)) {
                    $fail('Parameter sweeps require a grid strategy.');

                    return;
                }

                if (! $bot->strategyConfig()->exists()) {
                    $fail('The bot has no strategy configuration to replay.');
                }
            }],
            'timeframe' => ['required', 'string', Rule::in(Timeframe::configured())],
            'start_at' => ['required', 'date', 'before_or_equal:end_at', 'before_or_equal:now'],
            'end_at' => ['required', 'date', 'before_or_equal:now', 'after_or_equal:start_at'],
            'name' => ['nullable', 'string', 'max:120'],
            'center_prices' => ['required', 'array', 'min:1'],
            'center_prices.*' => ['required', $decimal],
            'spacing_values' => ['required', 'array', 'min:1'],
            'spacing_values.*' => ['required', $decimal],
            'level_counts' => ['required', 'array', 'min:1'],
            'level_counts.*' => ['required', $integer],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $centers = $this->normalizedDecimals('center_prices');
            $spacings = $this->normalizedDecimals('spacing_values');
            $levels = $this->normalizedLevels();

            if ($centers !== null && $centers->count() !== $centers->unique()->count()) {
                $validator->errors()->add('center_prices', 'Center prices must be unique.');
            }

            if ($spacings !== null && $spacings->count() !== $spacings->unique()->count()) {
                $validator->errors()->add('spacing_values', 'Spacing values must be unique.');
            }

            if ($levels !== null && $levels->count() !== $levels->unique()->count()) {
                $validator->errors()->add('level_counts', 'Level counts must be unique.');
            }

            if ($centers === null || $spacings === null || $levels === null
                || $centers->isEmpty() || $spacings->isEmpty() || $levels->isEmpty()) {
                return;
            }

            $total = $centers->count() * $spacings->count() * $levels->count();
            $maximum = BacktestSweep::maximumRuns();

            if ($total < 2) {
                $validator->errors()->add('parameters', 'A sweep must produce at least two runs.');
            } elseif ($total > $maximum) {
                $validator->errors()->add('parameters', "A sweep may produce at most {$maximum} runs.");
            }

            $this->validatePeriod($validator);
        });
    }

    /**
     * @return Collection<int, string>|null
     */
    private function normalizedDecimals(string $field): ?Collection
    {
        $values = $this->input($field);

        if (! is_array($values)) {
            return null;
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => (is_string($value) || is_int($value) || is_float($value))
                && preg_match('/^\d+(?:\.\d{1,'.Decimal::SCALE.'})?$/', (string) $value) === 1)
            ->map(fn (mixed $value): string => Decimal::of((string) $value)->toString())
            ->values();
    }

    /**
     * @return Collection<int, int>|null
     */
    private function normalizedLevels(): ?Collection
    {
        $values = $this->input('level_counts');

        if (! is_array($values)) {
            return null;
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => (is_string($value) || is_int($value) || is_float($value))
                && preg_match('/^\d+$/', (string) $value) === 1)
            ->map(fn (mixed $value): int => (int) $value)
            ->values();
    }

    private function validatePeriod(Validator $validator): void
    {
        $timeframeValue = $this->input('timeframe');
        $startValue = $this->input('start_at');
        $endValue = $this->input('end_at');

        if (! is_string($timeframeValue) || ! is_string($startValue) || ! is_string($endValue)) {
            return;
        }

        $utc = new DateTimeZone('UTC');

        try {
            $timeframe = Timeframe::fromConfig($timeframeValue);
            $startAt = (new DateTimeImmutable($startValue))->setTimezone($utc);
            $endAt = (new DateTimeImmutable($endValue))->setTimezone($utc);
        } catch (\Throwable) {
            return;
        }

        $error = app(BacktestPeriodValidator::class)->validate($timeframe, $startAt, $endAt);

        if ($error !== null) {
            $validator->errors()->add($error['field'], $error['message']);
        }
    }
}
