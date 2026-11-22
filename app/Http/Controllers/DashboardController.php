<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\EquityHistory;
use App\Domain\Portfolio\InventoryLedger;
use App\Domain\Portfolio\MarkPrice;
use App\Domain\Support\Decimal;
use App\Models\ActivityLog;
use App\Models\Bot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, InventoryLedger $ledger, EquityHistory $history): Response
    {
        $user = $request->user();

        /** @var Collection<int, Bot> $bots */
        $bots = Bot::query()->where('user_id', $user->id)->get();

        $counts = [
            'total' => $bots->count(),
            'running' => 0,
            'paused' => 0,
            'idle' => 0,
            'stopped' => 0,
        ];

        $realized = Decimal::zero();
        $unrealized = Decimal::zero();

        foreach ($bots as $bot) {
            $status = $bot->status->value;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $realized = $realized->add($bot->realized_pnl);

            $mark = MarkPrice::for($bot);

            if ($mark !== null) {
                $unrealized = $unrealized->add($ledger->unrealizedPnl($bot, $mark));
            }
        }

        $activity = ActivityLog::query()
            ->where('user_id', $user->id)
            ->with('bot:id,name')
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
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();

        $ranges = array_map('intval', (array) config('analytics.ranges', [7, 30, 90]));
        $defaultRange = (int) config('analytics.default_range_days', 30);
        $requestedRange = $request->query('range');
        $requestedRange = is_numeric($requestedRange) ? (int) $requestedRange : null;
        $rangeDays = in_array($requestedRange, $ranges, true) ? $requestedRange : $defaultRange;

        return Inertia::render('Dashboard', [
            'summary' => [
                ...$counts,
                'realized_pnl' => $realized->toString(),
                'unrealized_pnl' => $unrealized->toString(),
            ],
            'recentActivity' => $activity,
            'equityHistory' => fn () => $history->forUser($user, $rangeDays),
        ]);
    }
}
