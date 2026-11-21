<?php

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Risk\Enums\RiskSeverity;
use App\Domain\Risk\RiskEventRecorder;
use App\Models\Bot;
use App\Models\RiskEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records activity with bot and user attribution', function () {
    $bot = Bot::factory()->create();

    $event = app(ActivityRecorder::class)->record(
        'bot.started',
        'Bot started',
        $bot,
        context: ['source' => 'test'],
        severity: 'info',
    );

    expect($event->bot_id)->toBe($bot->id)
        ->and($event->user_id)->toBe($bot->user_id)
        ->and($event->type)->toBe('bot.started')
        ->and($event->severity)->toBe('info')
        ->and($event->context)->toBe(['source' => 'test'])
        ->and($event->fresh()->context)->toBe(['source' => 'test']);
});

it('records queryable risk events with severity and context', function () {
    $bot = Bot::factory()->create();

    $event = app(RiskEventRecorder::class)->record(
        'risk.position_limit',
        'Position limit reached',
        $bot,
        context: ['notional' => '1000'],
        severity: RiskSeverity::Critical,
    );

    expect($event)->toBeInstanceOf(RiskEvent::class)
        ->and($event->severity)->toBe('critical')
        ->and($bot->riskEvents()->where('type', 'risk.position_limit')->count())->toBe(1)
        ->and($event->fresh()->context['notional'])->toBe('1000');
});
