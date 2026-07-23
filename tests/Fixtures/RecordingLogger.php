<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory PSR-3 logger used by tests to assert on the structured (level, message, context)
 * tuple actually handed to the logger, rather than on formatted STDOUT text.
 */
class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    private array $entries = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->entries[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return array<int, array{level: string, message: string, context: array}>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
