<?php

namespace App\Http\Controllers;

use App\Domain\Activity\ActivityRecorder;
use App\Http\Requests\UpdateBotExitGuardRequest;
use App\Models\Bot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class BotExitGuardController extends Controller
{
    public function update(
        UpdateBotExitGuardRequest $request,
        Bot $bot,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $validated = $request->validated();

        DB::transaction(function () use ($bot, $validated, $activity, $request) {
            /** @var Bot $locked */
            $locked = Bot::query()->lockForUpdate()->findOrFail($bot->id);
            $before = $this->snapshot($locked);

            $updates = [];

            foreach (['stop_loss_percent', 'take_profit_percent', 'range_low', 'range_high'] as $field) {
                if ($request->has($field)) {
                    $updates[$field] = $validated[$field] ?? null;
                }
            }

            if ($updates !== []) {
                $locked->forceFill($updates)->save();
            }

            $after = $this->snapshot($locked->refresh());

            $activity->record(
                'bot.exit_guard_updated',
                "Protective exits updated for {$locked->name}.",
                $locked,
                $request->user(),
                ['before' => $before, 'after' => $after],
            );
        });

        return redirect()
            ->route('bots.show', $bot)
            ->with('status', 'Protective exits updated.');
    }

    /** @return array{stop_loss_percent: ?string, take_profit_percent: ?string, range_low: ?string, range_high: ?string} */
    private function snapshot(Bot $bot): array
    {
        return [
            'stop_loss_percent' => $bot->stop_loss_percent,
            'take_profit_percent' => $bot->take_profit_percent,
            'range_low' => $bot->range_low,
            'range_high' => $bot->range_high,
        ];
    }
}
