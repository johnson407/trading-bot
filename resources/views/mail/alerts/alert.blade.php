<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>{{ $alert->title }}</title>
    </head>
    <body style="margin: 0; padding: 24px; background: #f1f5f9; color: #0f172a; font-family: Arial, sans-serif;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px;">
            <tr>
                <td style="padding: 24px;">
                    <p style="margin: 0 0 8px; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #b91c1c;">
                        {{ $alert->severity }} alert
                    </p>

                    <h1 style="margin: 0 0 16px; font-size: 22px; line-height: 1.3;">
                        {{ $alert->title }}
                    </h1>

                    @if ($alert->bot)
                        <p style="margin: 0 0 12px; font-size: 14px; color: #475569;">
                            Bot: <strong>{{ $alert->bot->name }}</strong>
                        </p>
                    @endif

                    <p style="margin: 0 0 20px; font-size: 15px; line-height: 1.6;">
                        {{ $safeMessage }}
                    </p>

                    @if ($safeContext !== [])
                        <h2 style="margin: 0 0 8px; font-size: 14px; color: #334155;">Observed values</h2>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 20px; font-size: 14px;">
                            @foreach ($safeContext as $key => $value)
                                <tr>
                                    <td style="padding: 4px 12px 4px 0; color: #64748b; vertical-align: top; white-space: nowrap;">
                                        {{ $key }}
                                    </td>
                                    <td style="padding: 4px 0; color: #0f172a; vertical-align: top;">
                                        @if (is_array($value))
                                            {{ json_encode($value) }}
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    @endif

                    <p style="margin: 0 0 20px; font-size: 13px; color: #64748b;">
                        {{ $alert->created_at->toDayDateTimeString() }}
                    </p>

                    <p style="margin: 0;">
                        <a href="{{ $inboxUrl }}" style="display: inline-block; padding: 10px 16px; background: #0f172a; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 14px;">
                            Open the alert inbox
                        </a>
                    </p>
                </td>
            </tr>
        </table>
    </body>
</html>
