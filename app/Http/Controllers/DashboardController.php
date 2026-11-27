<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\EquityHistory;
use App\Domain\Bot\BotHealth;
use App\Domain\Portfolio\InventoryLedger;
use App\Domain\Portfolio\MarkPrice;
use App\Domain\Support\Decimal;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, InventoryLedger $ledger, EquityHistory $history, EngineHeartbeat $heartbeat, BotHealth $botHealth): Response
    {
        $user = $request->user();

        /** @var Collection<int, Bot> $bots */
        $bots = Bot::withTrashed()->where('user_id', $user->id)->get();

        $counts = [
            'total' => 0,
            'running' => 0,
            'paused' => 0,
            'idle' => 0,
            'stopped' => 0,
        ];

        $realized = Decimal::zero();
        $unrealized = Decimal::zero();

        foreach ($bots as $bot) {
            if (! $bot->trashed()) {
                $status = $bot->status->value;
                $counts['total']++;
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }

            $realized = $realized->add($bot->realized_pnl);

            $mark = MarkPrice::for($bot);

            if ($mark !== null) {
                $unrealized = $unrealized->add($ledger->unrealizedPnl($bot, $mark));
            }
        }

        $activity = ActivityLog::query()
            ->where('user_id', $user->id)
            ->with('bot:id,name,deleted_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'type' => $log->type,
                'severity' => $log->severity,
                'message' => $log->message,
                'bot' => $log->bot?->name,
                'bot_id' => $log->bot_id,
                'archived' => $log->bot?->trashed() ?? false,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();

        $ranges = array_map('intval', (array) config('analytics.ranges', [7, 30, 90]));
        $defaultRange = (int) config('analytics.default_range_days', 30);
        $requestedRange = $request->query('range');
        $requestedRange = is_numeric($requestedRange) ? (int) $requestedRange : null;
        $rangeDays = in_array($requestedRange, $ranges, true) ? $requestedRange : $defaultRange;
        $engine = $heartbeat->status();

        return Inertia::render('Dashboard', [
            'summary' => [
                ...$counts,
                'realized_pnl' => $realized->toString(),
                'unrealized_pnl' => $unrealized->toString(),
            ],
            'recentActivity' => $activity,
            'equityHistory' => fn () => $history->forUser($user, $rangeDays),
            'botHealth' => $botHealth->forBots($bots, $engine),
            'engine' => $engine,
        ]);
    }
}
