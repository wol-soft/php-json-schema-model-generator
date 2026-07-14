<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Logger;

use PHPModelGenerator\Logger\EchoLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Stringable;

class EchoLoggerTest extends TestCase
{
    public static function levelProvider(): array
    {
        return [
            'debug' => [LogLevel::DEBUG, ''],
            'info' => [LogLevel::INFO, ''],
            'notice' => [LogLevel::NOTICE, ''],
            'warning' => [LogLevel::WARNING, 'Warning: '],
            'error' => [LogLevel::ERROR, 'Error: '],
            'critical' => [LogLevel::CRITICAL, 'Critical: '],
            'alert' => [LogLevel::ALERT, 'Alert: '],
            'emergency' => [LogLevel::EMERGENCY, 'Emergency: '],
        ];
    }

    #[DataProvider('levelProvider')]
    public function testLogPrefixesStdoutForWarningLevelsAndAboveOnly(string $level, string $expectedPrefix): void
    {
        $triggeredErrors = $this->captureTriggeredErrors(
            static function () use ($level): void {
                (new EchoLogger())->log($level, 'a message');
            },
        );

        $this->expectOutputString($expectedPrefix . "a message\n");
        $this->assertSame(
            $level === LogLevel::WARNING ? [[E_USER_WARNING, 'a message']] : [],
            $triggeredErrors,
            'trigger_error() must fire exactly for LogLevel::WARNING and no other level.',
        );
    }

    public function testWarningHelperMethodDelegatesToLog(): void
    {
        $triggeredErrors = $this->captureTriggeredErrors(
            static function (): void {
                (new EchoLogger())->warning('plain warning');
            },
        );

        $this->expectOutputString("Warning: plain warning\n");
        $this->assertSame([[E_USER_WARNING, 'plain warning']], $triggeredErrors);
    }

    public function testTriggeredPhpWarningUsesUnprefixedInterpolatedMessage(): void
    {
        $triggeredErrors = $this->captureTriggeredErrors(
            static function (): void {
                (new EchoLogger())->log(
                    LogLevel::WARNING,
                    'Property {property} is suspicious',
                    ['property' => 'name'],
                );
            },
        );

        $this->expectOutputString("Warning: Property name is suspicious\n");
        $this->assertSame([[E_USER_WARNING, 'Property name is suspicious']], $triggeredErrors);
    }

    public function testInterpolatesPresentPlaceholdersAndLeavesUnknownOnesUntouched(): void
    {
        $this->expectOutputString("Property 'name' in {file} value {count}\n");

        (new EchoLogger())->log(
            LogLevel::INFO,
            "Property '{property}' in {file} value {count}",
            ['property' => 'name', 'count' => ['not', 'stringable']],
        );
    }

    public function testInterpolatesStringableContextValues(): void
    {
        $stringableValue = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        $this->expectOutputString("value: stringable-value\n");

        (new EchoLogger())->log(LogLevel::INFO, 'value: {value}', ['value' => $stringableValue]);
    }

    /**
     * @return array<int, array{0: int, 1: string}>
     */
    private function captureTriggeredErrors(callable $callback): array
    {
        $capturedErrors = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$capturedErrors): bool {
            $capturedErrors[] = [$errno, $errstr];

            return true;
        });

        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $capturedErrors;
    }
}
