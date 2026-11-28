<?php

use Symfony\Component\Yaml\Yaml;

function productionCompose(): array
{
    return Yaml::parseFile(base_path('docker-compose.prod.yml'));
}

/** @param array<string, array<string, mixed>> $services */
function productionLiveGateParity(array $services): bool
{
    $source = '${TRADING_LIVE_ENABLED:-false}';

    if (($services['engine']['environment']['TRADING_LIVE_ENABLED'] ?? null) !== $source) {
        return false;
    }

    foreach (['app', 'horizon', 'scheduler'] as $name) {
        if (($services[$name]['env_file'] ?? null) !== '.env') {
            return false;
        }

        if (array_key_exists('TRADING_LIVE_ENABLED', $services[$name]['environment'] ?? [])) {
            return false;
        }
    }

    return true;
}

it('bounds logs and memory for every production service', function () {
    $services = productionCompose()['services'];

    expect(array_keys($services))->toEqualCanonicalizing([
        'app', 'nginx', 'postgres', 'redis', 'engine', 'horizon', 'scheduler',
    ]);

    $memoryLimits = [
        'app' => '${APP_MEM_LIMIT:-512m}',
        'nginx' => '${NGINX_MEM_LIMIT:-128m}',
        'postgres' => '${POSTGRES_MEM_LIMIT:-512m}',
        'redis' => '${REDIS_MEM_LIMIT:-256m}',
        'engine' => '${ENGINE_MEM_LIMIT:-512m}',
        'horizon' => '${HORIZON_MEM_LIMIT:-512m}',
        'scheduler' => '${SCHEDULER_MEM_LIMIT:-256m}',
    ];

    foreach ($services as $name => $service) {
        expect($service['logging']['driver'] ?? null)->toBe('json-file', $name)
            ->and($service['logging']['options']['max-size'] ?? null)->toBe('10m', $name)
            ->and($service['logging']['options']['max-file'] ?? null)->toBe('3', $name)
            ->and($service['logging']['options']['compress'] ?? null)->toBe('true', $name)
            ->and($service['mem_limit'] ?? null)->toBe($memoryLimits[$name], $name);
    }
});

it('requires the internal token for the app and engine', function () {
    $services = productionCompose()['services'];
    $requirement = '${ENGINE_INTERNAL_TOKEN:?ENGINE_INTERNAL_TOKEN is required}';

    expect($services['app']['environment']['ENGINE_INTERNAL_TOKEN'] ?? null)->toBe($requirement)
        ->and($services['engine']['environment']['ENGINE_INTERNAL_TOKEN'] ?? null)->toBe($requirement);
});

it('sources the production live gate from one interpolated value', function () {
    $services = productionCompose()['services'];

    expect(productionLiveGateParity($services))->toBeTrue()
        ->and(config('trading.live_enabled'))->toBeFalse();

    $mismatch = $services;
    $mismatch['horizon']['environment']['TRADING_LIVE_ENABLED'] = 'true';

    expect(productionLiveGateParity($mismatch))->toBeFalse();
})->group('live-readiness');

it('publishes only the web port and keeps migrations on the app service', function () {
    $services = productionCompose()['services'];

    foreach ($services as $name => $service) {
        if ($name === 'nginx') {
            expect($service['ports'] ?? [])->not->toBeEmpty();

            continue;
        }

        expect($service['ports'] ?? [])->toBeEmpty($name);
    }

    expect($services['app']['environment']['RUN_MIGRATIONS'] ?? null)->toBe('true')
        ->and($services['horizon']['environment']['RUN_MIGRATIONS'] ?? null)->toBe('false')
        ->and($services['scheduler']['environment']['RUN_MIGRATIONS'] ?? null)->toBe('false');
});
