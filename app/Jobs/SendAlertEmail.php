<?php

namespace App\Jobs;

use App\Domain\Alerts\AlertSanitizer;
use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAlertEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $alertId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $alert = Alert::query()->with(['user', 'bot'])->find($this->alertId);

        if (! $alert instanceof Alert) {
            return;
        }

        $user = $alert->user;

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            Log::warning('alert.email_skipped_unverified_owner', [
                'alert_id' => $alert->id,
            ]);

            return;
        }

        try {
            Mail::to($user->email)->send(new AlertMail($alert));
        } catch (Throwable $exception) {
            Log::error('alert.email_failed', [
                'alert_id' => $alert->id,
                'error' => app(AlertSanitizer::class)->text($exception->getMessage()),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('alert.email_failed_terminal', [
            'alert_id' => $this->alertId,
            'error' => $exception === null
                ? null
                : app(AlertSanitizer::class)->text($exception->getMessage()),
        ]);
    }
}
