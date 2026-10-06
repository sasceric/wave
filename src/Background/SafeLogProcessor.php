<?php

namespace App\Background;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

#[AsMonologProcessor]
final class SafeLogProcessor
{
    public function __construct(private readonly \Symfony\Component\HttpFoundation\RequestStack $requests)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $id = $this->requests->getMainRequest()?->attributes->get('_wave_request_id');
        if (is_string($id)) {
            $extra['request_id'] = $id;
        }

        return $record->with(message: self::text($record->message), context: $this->values($record->context), extra: $this->values($extra));
    }

    private function values(array $values, int $depth = 0): array
    {
        if ($depth > 8) {
            return ['truncated' => true];
        }
        foreach ($values as $key => $value) {
            if (preg_match('/password|secret|token|authorization|cookie|private.?key|api.?key|endpoint|recipient|email|(?:^|_)body$|payload|raw|html|params|parameters|bindings|dsn/i', (string) $key)) {
                $values[$key] = '[redacted]';
            } elseif ($value instanceof \Throwable) {
                $values[$key] = ['class' => $value::class, 'code' => $value->getCode()];
            } elseif (is_array($value)) {
                $values[$key] = $this->values($value, $depth + 1);
            } elseif (is_object($value)) {
                $values[$key] = ['class' => $value::class];
            } elseif (is_string($value)) {
                $values[$key] = self::text($value);
            }
        }

        return $values;
    }

    public static function text(string $value): string
    {
        $value = preg_replace('/(parameters:?).*$/is', '$1 [redacted]', $value) ?? $value;
        $value = preg_replace('~([a-z][a-z0-9+.-]*://[^\\s/:@]+:)[^\\s/@]+@~i', '$1[redacted]@', $value) ?? $value;
        $value = preg_replace('/\\b(Bearer|Basic)\\s+[A-Za-z0-9._~+\\/-]+=*/i', '$1 [redacted]', $value) ?? $value;
        $value = preg_replace('/\\beyJ[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\b/', '[redacted token]', $value) ?? $value;
        $value = preg_replace('/#[a-f0-9]{64}\\b/i', '#[redacted token]', $value) ?? $value;
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i', '[redacted email]', $value) ?? $value;

        return preg_replace('~((?:password|secret|token|authorization|cookie|private_?key|api[_-]?key)\\s*["\']?\\s*[:=]\\s*)(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\\s,;}]+)~i', '$1[redacted]', $value) ?? $value;
    }
}
