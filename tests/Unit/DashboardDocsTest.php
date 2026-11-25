<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('documents every dashboard route that exists', function () {
    $docs = File::get(base_path('docs/dashboard.md'));

    $routes = [
        'bots.index',
        'bots.create',
        'bots.store',
        'bots.show',
        'bots.activity',
        'bots.start',
        'bots.pause',
        'bots.stop',
        'bots.orders.cancel',
        'settings.index',
        'kill-switch.create',
        'kill-switch.store',
        'exchange-credentials.index',
        'exchange-credentials.create',
        'exchange-credentials.store',
        'exchange-credentials.edit',
        'exchange-credentials.update',
        'exchange-credentials.destroy',
        'backtest-sweeps.index',
        'backtest-sweeps.create',
        'backtest-sweeps.store',
        'backtest-sweeps.show',
    ];

    foreach ($routes as $route) {
        expect(Route::has($route))->toBeTrue()
            ->and($docs)->toContain($route);
    }
});

it('names the feature test covering each documented flow', function () {
    $docs = File::get(base_path('docs/dashboard.md'));

    foreach ([
        'AuthorizationTest',
        'BotListTest',
        'BotCreationTest',
        'BotDetailTest',
        'BotControlTest',
        'ActivityLogTest',
        'SettingsUiTest',
        'KillSwitchUiTest',
        'ExchangeCredentialUiTest',
        'BacktestSweepDashboardTest',
    ] as $test) {
        expect($docs)->toContain($test);
    }
});
