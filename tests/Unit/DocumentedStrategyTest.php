<?php

use App\Domain\Strategy\StrategyRegistry;
use Tests\Fixtures\DocumentedCustomStrategy;

it('registers and resolves the documented custom strategy', function () {
    $registry = new StrategyRegistry;
    $registry->register(new DocumentedCustomStrategy);

    expect($registry->has('documented-custom'))->toBeTrue()
        ->and($registry->get('documented-custom'))->toBeInstanceOf(DocumentedCustomStrategy::class)
        ->and($registry->keys())->toContain('documented-custom');
});
