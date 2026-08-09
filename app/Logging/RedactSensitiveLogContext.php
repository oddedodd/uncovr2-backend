<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Stringable;
use Throwable;

final class RedactSensitiveLogContext
{
    private const SENSITIVE_KEY = '/(^|_)(authorization|cookie|password|secret|token|api_key|access_key|payload|body|recipient|subject|email_address|reply_to|from|to)($|_)/i';

    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            message: $this->sanitizeString($record->message),
            context: $this->sanitizeArray($record->context),
            extra: $this->sanitizeArray($record->extra),
        ));
    }

    /** @param array<mixed> $values @return array<mixed> */
    private function sanitizeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $values[$key] = '[redacted]';

                continue;
            }

            $values[$key] = $this->sanitizeValue($value);
        }

        return $values;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->sanitizeArray($value);
        }

        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => $this->sanitizeString($value->getMessage()),
                'file' => basename($value->getFile()),
                'line' => $value->getLine(),
            ];
        }

        if ($value instanceof Stringable) {
            return $this->sanitizeString((string) $value);
        }

        if (is_object($value)) {
            return ['class' => $value::class];
        }

        return is_string($value) ? $this->sanitizeString($value) : $value;
    }

    private function sanitizeString(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $value) ?? $value;
        $value = preg_replace('/\b(?:whsec_|re_|sb_secret_)[A-Za-z0-9_\-]+\b/', '[redacted-secret]', $value) ?? $value;
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $value) ?? $value;
        $value = preg_replace('/([?&](?:token|key|secret|signature)=)[^&\s]+/i', '$1[redacted]', $value) ?? $value;

        return $value;
    }
}
