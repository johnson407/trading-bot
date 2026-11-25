<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Supplies the real Go plan evaluator to the PHP suite. Prefers a running
 * engine (Compose's `engine` service or ENGINE_PLAN_URL); otherwise starts
 * `go run . plan-server` from engine/ when Go is installed. There is no
 * hand-written PHP grid calculator in the test path.
 */
final class EnginePlanServer
{
    private static ?string $url = null;

    private static ?Process $process = null;

    public static function ensure(?string $configuredUrl): ?string
    {
        if (self::$url !== null) {
            return self::$url;
        }

        $candidates = array_values(array_unique(array_filter([
            $configuredUrl !== null ? rtrim($configuredUrl, '/') : null,
            'http://127.0.0.1:18080',
        ])));

        foreach ($candidates as $candidate) {
            if (self::healthy($candidate)) {
                return self::$url = $candidate;
            }
        }

        if (! self::goAvailable()) {
            return null;
        }

        $process = new Process(
            ['go', 'run', '.', 'plan-server'],
            base_path('engine'),
            [
                'ENGINE_LISTEN_ADDR' => '127.0.0.1:18080',
                'ENGINE_INTERNAL_TOKEN' => (string) config('engine.internal_token', ''),
            ],
        );
        $process->setTimeout(null);
        $process->start();

        register_shutdown_function(static function () use ($process): void {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        });

        $deadline = microtime(true) + 60;

        while (microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                return null;
            }

            if (self::healthy('http://127.0.0.1:18080')) {
                self::$process = $process;

                return self::$url = 'http://127.0.0.1:18080';
            }

            usleep(100_000);
        }

        $process->stop(1);

        return null;
    }

    private static function healthy(string $url): bool
    {
        try {
            return Http::timeout(0.5)->get($url.'/livez')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function goAvailable(): bool
    {
        $process = new Process(['go', 'version']);
        $process->setTimeout(5);

        try {
            return $process->run() === 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
