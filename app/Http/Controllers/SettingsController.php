<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'tickIntervalSeconds' => max(1, (int) config('trading.tick.interval_seconds')),
        ]);
    }
}
