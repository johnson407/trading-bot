<?php

namespace App\Console\Commands;

use App\Domain\Risk\LiveCredentialPolicy;
use App\Services\Engine\EngineHeartbeat;
use App\Services\Engine\HorizonStatus;
use App\Services\Engine\SchedulerHeartbeat;
use Illuminate\Console\Command;

class LivePreflight extends Command
{
    protected $signature = 'live:preflight
        {--pre-enable : Expect the live gate to be closed before enablement}';

    protected $description = 'Run read-only checks required before enabling live trading.';

    /**
     * @var list<array{label: string, ok: bool, message: string, remediation: string}>
     */
    private array $checks = [];

    public function handle(
        EngineHeartbeat $heartbeat,
        SchedulerHeartbeat $scheduler,
        LiveCredentialPolicy $credentials,
        HorizonStatus $horizonStatus,
    ): int {
        $preEnable = (bool) $this->option('pre-enable');
        $appEnabled = (bool) config('trading.live_enabled', false);

        $this->addCheck(
            'Application live gate',
            $preEnable ? ! $appEnabled : $appEnabled,
            $preEnable
                ? 'Expected the live gate closed before enablement.'
                : 'Expected the live gate enabled for the final preflight.',
            'Set TRADING_LIVE_ENABLED to the value required for this preflight mode.',
        );

        $status = $heartbeat->status();
        $engineAvailable = (bool) ($status['available'] ?? false);
        $engineEnabled = $status['live_trading'] ?? null;

        $this->addCheck(
            'Engine heartbeat',
            $engineAvailable,
            'The engine heartbeat is missing or expired.',
            'Start the engine and confirm its heartbeat is fresh.',
        );

        if ($engineAvailable) {
            $expectedEngine = $preEnable ? false : true;
            $engineMatchesMode = is_bool($engineEnabled) && $engineEnabled === $expectedEngine;

            $this->addCheck(
                'Engine live flag',
                $engineMatchesMode,
                $preEnable
                    ? 'Expected the engine live gate closed before enablement.'
                    : 'Expected the engine live gate enabled for the final preflight.',
                'Align TRADING_LIVE_ENABLED in the engine environment and restart it.',
            );

            $this->addCheck(
                'Application and engine gate match',
                is_bool($engineEnabled) && $engineEnabled === $appEnabled,
                'The application and engine report different live-gate values.',
                'Source both services from the same TRADING_LIVE_ENABLED value and restart them.',
            );
        }

        $mailer = (string) config('mail.default', '');
        $from = strtolower(trim((string) config('mail.from.address', '')));
        $fromConfigured = filter_var($from, FILTER_VALIDATE_EMAIL) !== false
            && ! str_ends_with($from, '@example.com');
        $this->addCheck(
            'Mailer delivery',
            ! in_array($mailer, ['log', 'array'], true) && $fromConfigured,
            'Alert email is not deliverable with the current mailer or a placeholder from address.',
            'Configure a real mailer and a non-placeholder MAIL_FROM_ADDRESS.',
        );

        $url = (string) config('app.url', '');
        $needsHttps = ! app()->environment(['local', 'testing']);
        $this->addCheck(
            'HTTPS application URL',
            ! $needsHttps || str_starts_with($url, 'https://'),
            'APP_URL must use HTTPS outside local/testing.',
            'Terminate TLS at the proxy and set APP_URL to the HTTPS origin.',
        );

        $this->addCheck(
            'Horizon queue worker',
            $horizonStatus->running(),
            'Horizon is not running.',
            'Start Horizon and wait for its status to report running.',
        );

        $this->addCheck(
            'Scheduler heartbeat',
            $scheduler->fresh(),
            'The scheduler heartbeat is missing or older than three minutes.',
            'Run schedule:work and confirm scheduler:heartbeat runs every minute.',
        );

        $conflicts = $credentials->conflictingCredentialIds();
        $this->addCheck(
            'Live credential conflicts',
            $conflicts === [],
            $conflicts === []
                ? 'No credential has more than one active live bot.'
                : 'Credentials with multiple active live bots: '.implode(', ', $conflicts),
            'Stop the extra live bots before enabling or starting another one.',
        );

        $failed = false;

        foreach ($this->checks as $check) {
            $this->components->twoColumnDetail(
                $check['label'],
                $check['ok'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
            );

            if (! $check['ok']) {
                $failed = true;
                $this->line('  '.$check['message']);
                $this->line('  Remediation: '.$check['remediation']);
            }
        }

        $this->newLine();
        $this->line('Runbook: docs/live-trading.md');

        if ($failed) {
            $this->components->error('Live preflight failed. Do not enable live trading.');

            return self::FAILURE;
        }

        $this->components->info('Live preflight passed.');

        return self::SUCCESS;
    }

    private function addCheck(string $label, bool $ok, string $message, string $remediation): void
    {
        $this->checks[] = compact('label', 'ok', 'message', 'remediation');
    }
}
