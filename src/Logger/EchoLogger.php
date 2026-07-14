<?php

declare(strict_types=1);

namespace PHPModelGenerator\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Default logger of the generator. Echoes every log entry to STDOUT, reproducing the library's
 * historic output, and additionally raises a native PHP warning for warning-level entries so CI
 * pipelines can detect generation-time problems via PHP's own warning channel without parsing
 * STDOUT or wiring a custom PSR-3 logger.
 */
class EchoLogger extends AbstractLogger
{
    private const PREFIXED_LEVELS = [
        LogLevel::WARNING,
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $interpolatedMessage = $this->interpolate((string) $message, $context);

        echo (in_array($level, self::PREFIXED_LEVELS, true) ? ucfirst((string) $level) . ': ' : '')
            . $interpolatedMessage
            . "\n";

        // Scoped to exactly warning: escalating error-and-above to E_USER_ERROR would abort the
        // generation run; downgrading them to E_USER_WARNING would misrepresent their severity.
        if ($level === LogLevel::WARNING) {
            trigger_error($interpolatedMessage, E_USER_WARNING);
        }
    }

    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements["{{$key}}"] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
