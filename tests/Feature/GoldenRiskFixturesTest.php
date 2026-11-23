<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\GoldenRiskFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(GoldenRiskFixtures::CLOCK);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('matches the committed PHP risk golden fixtures', function () {
    $harness = new GoldenRiskFixtures;
    $fixtures = $harness->capture();

    if (getenv('GOLDEN_CAPTURE') === '1') {
        $harness->write($fixtures);
    }

    foreach (GoldenRiskFixtures::FILES as $name) {
        $path = GoldenRiskFixtures::path($name);

        expect(is_file($path))->toBeTrue("Missing risk fixture [{$path}].");
        expect(file_get_contents($path))->toBe(GoldenRiskFixtures::encode($fixtures[$name]));
        expect(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR))->toBe($fixtures[$name]);
    }
});

it('decrypts the committed credential stub with the documented test-only key', function () {
    $harness = new GoldenRiskFixtures;
    $path = GoldenRiskFixtures::path(GoldenRiskFixtures::CIPHERTEXT_FILE);

    if (! is_file($path)) {
        if (getenv('GOLDEN_CAPTURE') !== '1' && getenv('GOLDEN_CAPTURE_CIPHERTEXT') !== '1') {
            $this->fail("Missing credential stub [{$path}]. Run with GOLDEN_CAPTURE=1 to generate it once.");
        }

        $harness->write($harness->captureCiphertext());
    }

    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $metadata = $fixture['metadata'];

    expect($metadata['format'])->toBe(GoldenRiskFixtures::CIPHERTEXT_FORMAT)
        ->and($metadata['cipher'])->toBe('AES-256-CBC')
        ->and($metadata['php_serialized'])->toBeFalse()
        ->and($metadata['test_key'])->toBe(GoldenRiskFixtures::TEST_KEY)
        ->and($metadata['test_previous_key'])->toBe(GoldenRiskFixtures::TEST_PREVIOUS_KEY)
        ->and($metadata['key_source'])->toContain('never .env');

    $key = base64_decode(substr(GoldenRiskFixtures::TEST_KEY, 7), true);
    $previousKey = base64_decode(substr(GoldenRiskFixtures::TEST_PREVIOUS_KEY, 7), true);

    expect($key)->toBeString()->toHaveLength(32)
        ->and($previousKey)->toBeString()->toHaveLength(32)
        ->and($key)->not->toBe($previousKey);

    $encrypter = new Encrypter($key, 'AES-256-CBC');

    foreach ($fixture['cases'] as $case) {
        expect(array_keys($case['payload']))->toEqualCanonicalizing(['iv', 'value', 'mac', 'tag'])
            ->and($case['payload_keys'])->toEqualCanonicalizing(['iv', 'value', 'mac', 'tag'])
            ->and($case['openssl_result_is_plaintext'])->toBeTrue()
            ->and($case['starts_with_php_serialize_prefix'])->toBeFalse()
            ->and($encrypter->decryptString($case['ciphertext']))->toBe($case['plaintext'])
            ->and($case['decrypt_string_round_trip'])->toBe($case['plaintext']);
    }

    $rotation = $fixture['rotation'];

    expect(fn () => $encrypter->decryptString($rotation['encrypted_with_previous_key']))
        ->toThrow(DecryptException::class, 'The MAC is invalid.');

    $rotated = (new Encrypter($key, 'AES-256-CBC'))->previousKeys([$previousKey]);

    expect($rotated->decryptString($rotation['encrypted_with_previous_key']))
        ->toBe(GoldenRiskFixtures::ROTATION_PLAINTEXT)
        ->and($rotation['decrypt_with_current_key_only']['exception'])->toBe('DecryptException')
        ->and($rotation['decrypt_with_current_and_previous_keys'])->toBe(GoldenRiskFixtures::ROTATION_PLAINTEXT);

    // Current PHP still produces the same payload shape with encryptString.
    $fresh = $encrypter->encryptString('golden-shape-check');
    $payload = json_decode(base64_decode($fresh, true), true, 512, JSON_THROW_ON_ERROR);

    expect(array_keys($payload))->toEqualCanonicalizing(['iv', 'value', 'mac', 'tag'])
        ->and($payload['tag'])->toBe('')
        ->and($encrypter->decryptString($fresh))->toBe('golden-shape-check');
});
