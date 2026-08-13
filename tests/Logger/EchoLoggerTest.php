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
        $this->expectOutputString($expectedPrefix . "a message\n");

        (new EchoLogger())->log($level, 'a message');
    }

    public function testWarningHelperMethodDelegatesToLog(): void
    {
        $this->expectOutputString("Warning: plain warning\n");

        (new EchoLogger())->warning('plain warning');
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
}
