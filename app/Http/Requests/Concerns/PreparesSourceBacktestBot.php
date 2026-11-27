<?php

namespace App\Http\Requests\Concerns;

use App\Models\BacktestRun;
use App\Services\Backtest\BacktestBotPrefill;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

trait PreparesSourceBacktestBot
{
    /**
     * Replace browser-supplied source fields with values derived from the
     * completed backtest. The name remains user-editable.
     */
    protected function prepareSourceBacktestBot(bool $preview = false): bool
    {
        if (! $this->filled('source_backtest_run_id')) {
            return false;
        }

        $run = BacktestRun::query()
            ->where('user_id', $this->user()?->id)
            ->findOrFail((int) $this->input('source_backtest_run_id'));

        if ($this->input('mode') === 'live') {
            throw ValidationException::withMessages([
                'mode' => 'Live trading is not available when creating a bot from a backtest.',
            ]);
        }

        try {
            $values = app(BacktestBotPrefill::class)->fromRun($run);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'source_backtest_run_id' => $exception->getMessage(),
            ]);
        }

        $name = trim((string) $this->input('name'));

        if ($name !== '') {
            $values['name'] = $name;
        }

        if ($preview) {
            $values['bot_id'] = null;
        }

        $this->merge(array_merge($values, [
            'preset_key' => null,
            'live_confirmation' => null,
        ]));

        return true;
    }
}
