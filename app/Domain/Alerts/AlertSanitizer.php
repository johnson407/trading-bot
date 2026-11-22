<?php

namespace App\Domain\Alerts;

class AlertSanitizer
{
    private const REDACTED = '[REDACTED]';

    /**
     * Sensitive fragments, checked after normalizing the key. Matching
     * fragments instead of exact names catches prefixed keys such as
     * `binance_api_key`, `client_secret`, and `X-MBX-APIKEY`.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_FRAGMENTS = [
        'apikey',
        'apisecret',
        'secret',
        'password',
        'passphrase',
        'token',
        'authorization',
        'signature',
        'privatekey',
        'credential',
    ];

    private const KEY_PATTERN = 'api[_\-\s]?key|api[_\-\s]?secret|secret(?:[_\-\s]?key)?|password|passphrase|access[_\-\s]?token|refresh[_\-\s]?token|token|authorization|signature|private[_\-\s]?key|credentials?';

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public function context(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->context($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->text($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public function text(string $text): string
    {
        $pattern = '/(?i)(["\']?)[a-z0-9._\-]*(?:'.self::KEY_PATTERN.')[a-z0-9._\-]*\1\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\r\n,;}]+)/';

        return preg_replace($pattern, self::REDACTED, $text) ?? $text;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', '', strtolower($key)) ?? '';

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
