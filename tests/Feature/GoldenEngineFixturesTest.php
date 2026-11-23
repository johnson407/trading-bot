<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\GoldenEngineFixtures;
use Tests\Support\GoldenRiskFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(GoldenEngineFixtures::CLOCK);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('matches the committed PHP golden engine fixtures', function () {
    $capture = new GoldenEngineFixtures;
    $fixtures = $capture->capture();

    if (getenv('GOLDEN_CAPTURE') === '1') {
        $capture->write($fixtures);
    }

    $expectedFiles = array_merge(
        GoldenEngineFixtures::FILES,
        GoldenRiskFixtures::FILES,
        [GoldenRiskFixtures::CIPHERTEXT_FILE],
    );
    sort($expectedFiles);

    $actualFiles = array_map(
        fn (string $path) => basename($path, '.json'),
        glob(dirname(GoldenEngineFixtures::path('x')).'/*.json') ?: [],
    );
    sort($actualFiles);

    expect($actualFiles)->toBe($expectedFiles);

    foreach ($fixtures as $name => $data) {
        $path = GoldenEngineFixtures::path($name);

        expect(file_exists($path))->toBeTrue("Missing golden fixture [{$path}]. Run the GOLDEN_CAPTURE command documented in tests/golden/README.md.");

        $contents = file_get_contents($path);

        expect($contents)->toBe(GoldenEngineFixtures::encode($data));
        expect(json_decode($contents, true, 512, JSON_THROW_ON_ERROR))->toBe($data);
    }
});
