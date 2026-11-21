<?php

namespace App\Logging;

use Monolog\LogRecord;

/**
 * Removes credential-bearing values from log records before they are written.
 */
class RedactSecrets
{
    private const REDACTED = '[REDACTED]';

    /**
     * Keys (compared case-insensitively) whose values must never reach a log.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'api_key',
        'apikey',
        'api_secret',
        'apisecret',
        'secret',
        'secret_key',
        'password',
        'passphrase',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'signature',
        'private_key',
        'privatekey',
        'credential',
        'credentials',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redactArray($value);
            }
        }

        return $data;
    }

    private function redactString(string $value): string
    {
        $pattern = '/\b(api[_-]?key|api[_-]?secret|secret|password|passphrase|token|authorization|signature)\b\s*[:=]\s*["\']?([^\s,;"\']+)/i';

        return preg_replace($pattern, '$1='.self::REDACTED, $value) ?? $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }
}
