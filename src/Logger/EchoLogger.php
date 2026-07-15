<?php

declare(strict_types=1);

namespace PHPModelGenerator\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Default logger of the generator. Echoes every log entry to STDOUT.
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
