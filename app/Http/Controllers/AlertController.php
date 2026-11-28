<?php

namespace App\Http\Controllers;

use App\Domain\Alerts\AlertGrouping;
use App\Models\Alert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request, AlertGrouping $grouping): Response
    {
        $user = $request->user();
        $botId = $this->ownedBotId($request);
        $mode = $request->string('mode')->toString() === 'all' ? 'all' : 'grouped';

        $alerts = null;
        $groups = null;
        $recentOccurrences = [];

        if ($mode === 'all') {
            $alerts = $user->alerts()
                ->with('bot:id,name,deleted_at')
                ->when($botId !== null, fn ($query) => $query->where('bot_id', $botId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (Alert $alert) => $this->alertPayload($alert));
        } else {
            $groups = $grouping->groups($user, $botId);
            $recentOccurrences = $grouping->recentOccurrences($user, $groups->items());
        }

        return Inertia::render('Alerts/Index', [
            'mode' => $mode,
            'alerts' => $alerts,
            'groups' => $groups,
            'recentOccurrences' => $recentOccurrences,
            'bots' => $user->bots()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($bot) => [
                    'id' => $bot->id,
                    'name' => $bot->name,
                ])
                ->values(),
            'filters' => [
                'bot_id' => $botId,
                'mode' => $mode,
            ],
        ]);
    }

    private function alertPayload(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'type' => $alert->type,
            'severity' => $alert->severity,
            'title' => $alert->title,
            'message' => $alert->message,
            'context' => $alert->context,
            'bot' => $alert->bot === null ? null : [
                'id' => $alert->bot->id,
                'name' => $alert->bot->name,
                'archived' => $alert->bot->trashed(),
            ],
            'read_at' => $alert->read_at?->toIso8601String(),
            'created_at' => $alert->created_at->toIso8601String(),
        ];
    }

    public function markGroupRead(Request $request, AlertGrouping $grouping): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'bot_id' => ['nullable', 'integer', 'min:1'],
            'through' => ['required', 'date'],
        ]);

        $botId = null;

        if (isset($validated['bot_id'])) {
            $botId = (int) $validated['bot_id'];

            abort_unless(
                $request->user()->bots()->withTrashed()->whereKey($botId)->exists(),
                404,
            );
        }

        $grouping->markRead($request->user(), $validated['type'], $botId, $validated['through']);

        return redirect()->back()->with('status', 'Alerts marked as read.');
    }

    public function markRead(Request $request, string $alert): RedirectResponse
    {
        $record = $request->user()->alerts()->findOrFail($alert);

        $record->forceFill(['read_at' => now()])->save();

        return redirect()->back()->with('status', 'Alert marked as read.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $botId = $this->ownedBotId($request);

        $request->user()->alerts()
            ->whereNull('read_at')
            ->when($botId !== null, fn ($query) => $query->where('bot_id', $botId))
            ->update(['read_at' => now()]);

        return redirect()->back()->with('status', 'Alerts marked as read.');
    }

    private function ownedBotId(Request $request): ?int
    {
        $botId = $request->input('bot_id');

        if ($botId === null || $botId === '') {
            return null;
        }

        $botId = filter_var($botId, FILTER_VALIDATE_INT);

        if ($botId === false || $botId < 1) {
            abort(404);
        }

        abort_unless(
            $request->user()->bots()->whereKey($botId)->exists(),
            404,
        );

        return $botId;
    }
}
