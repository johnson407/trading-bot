<?php

use App\Logging\RedactSecrets;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

it('redacts credentials from structured log records', function () {
    $path = storage_path('framework/testing/redaction.log');
    @mkdir(dirname($path), 0777, true);
    @unlink($path);

    $logger = new Logger('test', [
        (new StreamHandler($path))->setFormatter(new JsonFormatter),
    ]);
    $logger->pushProcessor(new RedactSecrets);

    $logger->info('Connecting with api_secret=super-secret-value', [
        'api_key' => 'plain-key',
        'nested' => ['password' => 'hunter2', 'symbol' => 'BTC/USDT'],
    ]);

    $contents = file_get_contents($path);

    expect($contents)
        ->not->toContain('super-secret-value')
        ->not->toContain('plain-key')
        ->not->toContain('hunter2')
        ->toContain('[REDACTED]')
        ->toContain('BTC/USDT');
});
