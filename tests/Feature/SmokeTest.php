<?php

it('boots the application with live trading disabled', function () {
    expect(app())->not->toBeNull()
        ->and(config('trading.live_enabled'))->toBeFalse()
        ->and(config('trading.default_exchange'))->toBe('binance');
});

it('serves the health endpoint', function () {
    $this->get('/up')->assertOk();
});
