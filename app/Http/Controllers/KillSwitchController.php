<?php

namespace App\Http\Controllers;

use App\Actions\ActivateKillSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KillSwitchController extends Controller
{
    public function create(Request $request): Response
    {
        $bots = $request->user()->bots()->withCount([
            'orders as open_orders_count' => fn ($query) => $query->whereIn('status', ['pending', 'open', 'partially_filled']),
        ])->get();

        return Inertia::render('KillSwitch/Confirm', [
            'botCount' => $bots->count(),
            'activeBotCount' => $bots->where('status.value', '!=', 'stopped')->count(),
            'openOrderCount' => $bots->sum('open_orders_count'),
        ]);
    }

    public function store(Request $request, ActivateKillSwitch $killSwitch): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:KILL SWITCH'],
        ]);

        $result = $killSwitch->handle($request->user());

        return redirect()->route('dashboard')->with(
            'status',
            match (true) {
                $result->hasFailures() => "Kill switch activated; {$result->failures()} order cancellation(s) failed.",
                $result->emergency => 'Kill switch activated; all open live orders were canceled.',
                default => 'Kill switch activated; pause commands sent to the engine.',
            },
        );
    }
}
