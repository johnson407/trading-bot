<?php

it('documents the live-trading runbook in go-live order', function () {
    $path = base_path('docs/live-trading.md');

    expect(file_exists($path))->toBeTrue();

    $docs = file_get_contents($path);

    $orderedHeadings = [
        '## 1. Pre-enablement preflight',
        '## 2. Testnet evidence',
        '## 3. Enable the gate',
        '## 4. Default `php artisan live:preflight`',
        '## 5. Canary',
    ];

    $previous = -1;

    foreach ($orderedHeadings as $heading) {
        $position = strpos($docs, $heading);

        expect($position)->not->toBeFalse()
            ->and($position)->toBeGreaterThan($previous);

        $previous = $position;
    }

    expect($docs)
        ->toContain('live:preflight --pre-enable')
        ->toContain('Start the canary')
        ->toContain('`php artisan live:preflight`');
});

it('documents the fail-safe, canary limits, and preflight modes', function () {
    $docs = file_get_contents(base_path('docs/live-trading.md'));

    expect($docs)
        ->toContain('final default `live:preflight` fails')
        ->toContain('`TRADING_LIVE_ENABLED=false` immediately and stop')
        ->toContain('lesser of 1% of account equity and a USD 250 absolute cap')
        ->toContain('60 minutes from the first live fill')
        ->toContain('20 completed ticks')
        ->toContain('0.5% of account equity')
        ->toContain('canary allocation')
        ->toContain('--pre-enable')
        ->toContain('| Pre-enablement |')
        ->toContain('| Default |');
});

it('documents every required testnet drill', function () {
    $docs = file_get_contents(base_path('docs/live-trading.md'));

    foreach ([
        'Start',
        'Ladder placement',
        'Fill',
        'Partial fill',
        'Cancel',
        'Kill switch',
        'Restart with resting orders',
        'Invalid credentials',
        'Stale feed',
        'Daily-loss pause',
        'Manual flatten',
    ] as $drill) {
        expect($docs)->toContain($drill);
    }
});

it('documents manual flatten, restart reconciliation, rollback, and escalation', function () {
    $docs = file_get_contents(base_path('docs/live-trading.md'));

    expect($docs)
        ->toContain('Spot limit order with IOC time-in-force')
        ->toContain('exchange free balance')
        ->toContain('Never use a market order')
        ->toContain('0.5% below the')
        ->toContain('Cancel only orders with no matching local record')
        ->toContain('## Rollback')
        ->toContain('`TRADING_LIVE_ENABLED=false`')
        ->toContain('`./deploy.sh --rollback`')
        ->toContain('## Escalation')
        ->toContain('The named escalation owner is')
        ->toContain('@johnson407')
        ->toContain('### Evidence to preserve');
});

it('links the runbook from the deployment and security docs', function () {
    $deployment = file_get_contents(base_path('docs/deployment.md'));
    $security = file_get_contents(base_path('docs/security.md'));

    expect($deployment)
        ->toContain('## Live-trading preflight')
        ->toContain('(live-trading.md)')
        ->toContain('live:preflight --pre-enable')
        ->toContain('php artisan live:preflight');

    expect($security)->toContain('(live-trading.md)');
});
