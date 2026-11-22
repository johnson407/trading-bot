<?php

use App\Domain\Alerts\AlertSanitizer;

it('removes sensitive context keys including prefixed and header variants', function () {
    $sanitizer = new AlertSanitizer;

    $context = $sanitizer->context([
        'loss' => '150',
        'binance_api_key' => 'abc',
        'X-MBX-APIKEY' => 'def',
        'client_secret' => 'ghi',
        'nested' => [
            'refreshToken' => 'jkl',
            'safe' => 'value',
        ],
    ]);

    expect($context)->toBe([
        'loss' => '150',
        'nested' => ['safe' => 'value'],
    ]);
});

it('redacts free-text secret assignments', function (string $text) {
    $sanitized = (new AlertSanitizer)->text($text);

    expect($sanitized)->not->toContain('abc')
        ->and($sanitized)->not->toContain('def');
})->with([
    'prefixed key' => ['binance_api_key=abc'],
    'client secret' => ['client_secret=abc'],
    'camel case' => ['camelApiKey=abc'],
    'colon form' => ['token: abc'],
    'json form' => ['{"secret":"abc"}'],
    'bearer token' => ['Authorization: Bearer abc-def'],
]);
