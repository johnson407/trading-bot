<?php

use App\Models\Bot;
use App\Models\ExchangeCredential;
use App\Models\User;
use App\Services\Engine\EngineHeartbeat;
use App\Services\Engine\HorizonStatus;
use App\Services\Engine\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeEngineHeartbeat;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config([
        'trading.live_enabled' => true,
        'mail.default' => 'smtp',
        'mail.from.address' => 'alerts@trading-bot.test',
        'app.url' => 'https://bot.example.com',
    ]);

    $this->heartbeat = new FakeEngineHeartbeat;
    $this->heartbeat->available = true;
    $this->heartbeat->liveTrading = true;

    $this->app->instance(EngineHeartbeat::class, $this->heartbeat);

    $this->mock(HorizonStatus::class, function ($mock): void {
        $mock->shouldReceive('running')->andReturn(true);
    });

    app(SchedulerHeartbeat::class)->record();
});

afterEach(function () {
    $this->app['env'] = 'testing';
});

function runLivePreflight(array $options = []): array
{
    $exit = Artisan::call('live:preflight', $options);

    return [$exit, Artisan::output()];
}

it('passes the final preflight with every required check green', function () {
    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Live preflight passed.')
        ->and($output)->toContain('Runbook: docs/live-trading.md');
});

it('passes the pre-enablement preflight with the gate and engine closed', function () {
    config(['trading.live_enabled' => false]);
    $this->heartbeat->liveTrading = false;

    [$exit, $output] = runLivePreflight(['--pre-enable' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Live preflight passed.');
});

it('blocks the final preflight when the application gate is closed', function () {
    config(['trading.live_enabled' => false]);

    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Application live gate');
});

it('blocks pre-enablement when another required check fails', function () {
    config(['trading.live_enabled' => false]);
    $this->heartbeat->liveTrading = false;
    Cache::flush();

    [$exit, $output] = runLivePreflight(['--pre-enable' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Scheduler heartbeat');
});

it('blocks when the application and engine gates differ', function () {
    $this->heartbeat->liveTrading = false;

    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Application and engine gate match');
});

it('blocks a log-only mailer', function () {
    config(['mail.default' => 'log']);

    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Mailer delivery');
});

it('blocks a non-https application url outside local and testing', function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'http://bot.example.com']);

    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(1)
        ->and($output)->toContain('HTTPS application URL');
});

it('blocks when Horizon is not running', function () {
    $this->mock(HorizonStatus::class, function ($mock): void {
        $mock->shouldReceive('running')->andReturn(false);
    });

    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Horizon queue worker');
});

it('blocks when the scheduler heartbeat is stale', function () {
    Cache::flush();

    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Scheduler heartbeat');
});

it('blocks when a credential has more than one active live bot', function () {
    $user = User::factory()->create();
    $credential = ExchangeCredential::factory()->for($user)->create(['exchange' => 'binance']);

    Bot::factory()->for($user)->live()->running()->create(['exchange_credential_id' => $credential->id]);
    Bot::factory()->for($user)->live()->create([
        'exchange_credential_id' => $credential->id,
        'status' => 'paused',
        'desired_status' => 'paused',
    ]);

    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Live credential conflicts');
});

it('does not change trading state while checking', function () {
    $user = User::factory()->create();
    $credential = ExchangeCredential::factory()->for($user)->create(['exchange' => 'binance']);
    $bot = Bot::factory()->for($user)->live()->running()->create([
        'exchange_credential_id' => $credential->id,
        'desired_status' => 'running',
    ]);

    config(['trading.live_enabled' => false]);

    runLivePreflight();

    $bot->refresh();

    expect($bot->status->value)->toBe('running')
        ->and($bot->desired_status->value)->toBe('running')
        ->and($bot->kill_switch_requested_at)->toBeNull();
});

it('does not print secret configuration values', function () {
    config([
        'mail.default' => 'log',
        'mail.mailers.smtp.password' => 'super-secret-value',
    ]);

    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(1)
        ->and($output)->not->toContain('super-secret-value');
});

it('blocks a placeholder mail from address', function () {
    config(['mail.from.address' => 'alerts@example.com']);

    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Mailer delivery')
        ->and($output)->toContain('MAIL_FROM_ADDRESS');
});

it('blocks when the engine heartbeat is unavailable', function () {
    app(EngineHeartbeat::class)->available = false;

    [$exit, $output] = runLivePreflight();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Engine heartbeat')
        ->and($output)->toContain('Start the engine');
});
