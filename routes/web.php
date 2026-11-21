<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExchangeCredentialController;
use App\Http\Controllers\KillSwitchController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('bots', BotController::class)->only(['index', 'create', 'show']);
    Route::post('bots', [BotController::class, 'store'])->name('bots.store')->middleware('throttle:bot-control');
    Route::post('bots/{bot}/start', [BotController::class, 'start'])->name('bots.start')->middleware('throttle:bot-control');
    Route::post('bots/{bot}/pause', [BotController::class, 'pause'])->name('bots.pause')->middleware('throttle:bot-control');
    Route::post('bots/{bot}/stop', [BotController::class, 'stop'])->name('bots.stop')->middleware('throttle:bot-control');
    Route::get('bots/{bot}/activity', [BotController::class, 'activity'])->name('bots.activity');
    Route::get('bots/{bot}/edit', [BotController::class, 'edit'])->name('bots.edit')->middleware('throttle:bot-control');
    Route::patch('bots/{bot}', [BotController::class, 'update'])->name('bots.update')->middleware('throttle:bot-control');

    Route::get('kill-switch', [KillSwitchController::class, 'create'])->name('kill-switch.create');
    Route::post('kill-switch', [KillSwitchController::class, 'store'])->name('kill-switch.store')->middleware('throttle:kill-switch');

    Route::middleware('throttle:exchange-credentials')->group(function () {
        Route::resource('exchange-credentials', ExchangeCredentialController::class)->except(['show']);
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
