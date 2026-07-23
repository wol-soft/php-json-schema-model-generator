<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

#[ApplicableDrafts]
class BooleanContainsSchemaTest extends AbstractPHPModelGeneratorTestCase
{
    public function testContainsFalseAllowsAbsentProperty(): void
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            'FalseContains.json',
            (new GeneratorConfiguration())->setLogger($recordingLogger),
        );

        $this->assertTrue(
            $this->hasLogEntry(
                $recordingLogger->getEntries(),
                'warning',
                "contains: false for property '{property}' can never be satisfied; any array will fail",
                ['property' => 'property'],
            ),
            'Expected a "contains: false" warning for the property.',
        );

        $object = new $className([]);
        $this->assertNull($object->getProperty());
    }

    #[DataProvider('containsFalseDataProvider')]
    public function testContainsFalseRejectsAnyArray(
        GeneratorConfiguration $configuration,
        array $value,
    ): void {
        $this->expectValidationError(
            $configuration,
            "No item in array 'property' matches the 'contains' constraint",
        );

        $className = $this->generateClassFromFile('FalseContains.json', $configuration);
        new $className(['property' => $value]);
    }

    public static function containsFalseDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'empty array'     => [[]],
                'non-empty array' => [[1, 2, 3]],
                'mixed array'     => [['hello', true, null]],
            ],
        );
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testContainsTrueAcceptsNonEmptyArray(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('TrueContains.json', $configuration);

        $object = new $className(['property' => [1, 'hello', true]]);
        $this->assertSame([1, 'hello', true], $object->getProperty());
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testContainsTrueRejectsEmptyArray(GeneratorConfiguration $configuration): void
    {
        $this->expectValidationError(
            $configuration,
            "No item in array 'property' matches the 'contains' constraint",
        );

        $className = $this->generateClassFromFile('TrueContains.json', $configuration);

        new $className(['property' => []]);
    }
}
