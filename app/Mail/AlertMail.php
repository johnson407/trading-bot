<?php

namespace App\Mail;

use App\Domain\Alerts\AlertSanitizer;
use App\Models\Alert;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertMail extends Mailable
{
    use SerializesModels;

    public function __construct(public Alert $alert)
    {
        $this->alert->loadMissing(['user', 'bot']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->alert->title,
        );
    }

    public function content(): Content
    {
        $sanitizer = app(AlertSanitizer::class);

        return new Content(
            view: 'mail.alerts.alert',
            with: [
                'alert' => $this->alert,
                'safeMessage' => $sanitizer->text($this->alert->message),
                'safeContext' => $sanitizer->context($this->alert->context ?? []),
                'inboxUrl' => route('alerts.index'),
            ],
        );
    }
}
