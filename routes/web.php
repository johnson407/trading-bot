<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\BacktestSweepController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\BotExitGuardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExchangeCredentialController;
use App\Http\Controllers\KillSwitchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RiskLimitController;
use App\Http\Controllers\SettingsController;
use App\Services\Engine\EngineHeartbeat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/health/engine', function (EngineHeartbeat $heartbeat) {
    $status = $heartbeat->status();

    return response()
        ->json($status, $status['available'] ? 200 : 503)
        ->header('Cache-Control', 'no-store');
})->name('health.engine');

Route::get('/', function (Request $request) {
    if ($request->user()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('bots/market-data', [BotController::class, 'marketData'])->name('bots.market-data')->middleware('throttle:bot-preview');
    Route::resource('bots', BotController::class)->only(['index', 'create', 'show']);
    Route::post('bots', [BotController::class, 'store'])->name('bots.store')->middleware('throttle:bot-control');
    Route::post('bots/preview', [BotController::class, 'preview'])->name('bots.preview')->middleware('throttle:bot-preview');
    Route::post('bots/{bot}/start', [BotController::class, 'start'])->name('bots.start')->middleware('throttle:bot-control');
    Route::post('bots/{bot}/pause', [BotController::class, 'pause'])->name('bots.pause')->middleware('throttle:bot-control');
    Route::post('bots/{bot}/stop', [BotController::class, 'stop'])->name('bots.stop')->middleware('throttle:bot-control');
    Route::delete('bots/{bot}', [BotController::class, 'destroy'])->name('bots.destroy')->middleware('throttle:bot-control');
    Route::post('bots/{bot}/restore', [BotController::class, 'restore'])->name('bots.restore')->withTrashed()->middleware('throttle:bot-control');
    Route::post('bots/{bot}/orders/{order}/cancel', [BotController::class, 'cancelOrder'])->name('bots.orders.cancel')->middleware('throttle:bot-control');
    Route::get('bots/{bot}/activity', [BotController::class, 'activity'])->name('bots.activity');
    Route::get('bots/{bot}/edit', [BotController::class, 'edit'])->name('bots.edit')->middleware('throttle:bot-control');
    Route::patch('bots/{bot}', [BotController::class, 'update'])->name('bots.update')->middleware('throttle:bot-control');
    Route::patch('bots/{bot}/exit-guard', [BotExitGuardController::class, 'update'])->name('bots.exit-guard.update')->middleware('throttle:bot-control');

    Route::get('backtests/compare', [BacktestController::class, 'compare'])->name('backtests.compare');
    Route::get('backtests/sweeps', [BacktestSweepController::class, 'index'])->name('backtest-sweeps.index');
    Route::get('backtests/sweeps/create', [BacktestSweepController::class, 'create'])->name('backtest-sweeps.create');
    Route::post('backtests/sweeps', [BacktestSweepController::class, 'store'])->name('backtest-sweeps.store')->middleware('throttle:backtests');
    Route::get('backtests/sweeps/{sweep}', [BacktestSweepController::class, 'show'])->name('backtest-sweeps.show');
    Route::get('backtests', [BacktestController::class, 'index'])->name('backtests.index');
    Route::get('backtests/create', [BacktestController::class, 'create'])->name('backtests.create');
    Route::post('backtests', [BacktestController::class, 'store'])->name('backtests.store')->middleware('throttle:backtests');
    Route::get('backtests/{run}', [BacktestController::class, 'show'])->name('backtests.show');
    Route::delete('backtests/{run}', [BacktestController::class, 'destroy'])->name('backtests.destroy');

    Route::prefix('settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('settings.index');

        Route::get('risk-limits', [RiskLimitController::class, 'edit'])->name('risk-limits.edit');
        Route::patch('risk-limits', [RiskLimitController::class, 'update'])->name('risk-limits.update')->middleware('throttle:bot-control');

        Route::middleware('throttle:exchange-credentials')->group(function () {
            Route::resource('credentials', ExchangeCredentialController::class)
                ->except(['show'])
                ->names('exchange-credentials')
                ->parameters(['credentials' => 'exchangeCredential']);
        });
    });

    Route::get('kill-switch', [KillSwitchController::class, 'create'])->name('kill-switch.create');
    Route::post('kill-switch', [KillSwitchController::class, 'store'])->name('kill-switch.store')->middleware('throttle:kill-switch');

    Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('alerts/read-all', [AlertController::class, 'markAllRead'])->name('alerts.read-all');
    Route::post('alerts/groups/read', [AlertController::class, 'markGroupRead'])->name('alerts.groups.read');
    Route::post('alerts/{alert}/read', [AlertController::class, 'markRead'])->whereNumber('alert')->name('alerts.read');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
