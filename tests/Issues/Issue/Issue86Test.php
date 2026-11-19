<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Dependency\InvalidSchemaDependencyException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

#[ApplicableDrafts]
class Issue86Test extends AbstractIssueTestCase
{
    #[DataProvider('validRefDataProvider')]
    public function testDifferentRequiredConfigurationForReferencedObject(array $input, bool $implicitNull): void
    {
        $className = $this->generateClassFromFile('ref.json', implicitNull: $implicitNull);

        $object = new $className($input);
        $this->assertSame($input['required'], $object->getRequired());
        $this->assertSame($input['optional'] ?? null, $object->getOptional());
    }

    public static function validRefDataProvider(): array
    {
        return [
            'both properties provided' => [['required' => 'Hello', 'optional' => 'World'], true],
            'optional property not provided' => [['required' => 'Hello'], false],
            'optional property null' => [['required' => 'Hello', 'optional' => null], true],
        ];
    }

    #[DataProvider('invalidRefDataProvider')]
    public function testDifferentRequiredConfigurationForReferencedObjectWithInvalidInput(
        array $input,
        bool $implicitNull,
        string $expectedException,
        string $expectedMessage,
    ): void {
        $className = $this->generateClassFromFile('ref.json', implicitNull: $implicitNull);

        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedMessage);

        new $className($input);
    }

    public static function invalidRefDataProvider(): array
    {
        return [
            'required property not provided' => [
                ['optional' => 'World'],
                true,
                RequiredValueException::class,
                "Missing required value for 'required'",
            ],
            'optional property null - implicit null disabled' => [
                ['required' => 'Hello', 'optional' => null],
                false,
                InvalidTypeException::class,
                "Invalid type for 'optional': requires 'string', got 'NULL'",
            ],
            'required property wrong type' => [
                ['required' => 1, 'optional' => 'World'],
                true,
                InvalidTypeException::class,
                "Invalid type for 'required': requires 'string', got 'integer'",
            ],
            'optional property wrong type' => [
                ['required' => 'Hello', 'optional' => 1],
                true,
                InvalidTypeException::class,
                "Invalid type for 'optional': requires 'string', got 'integer'",
            ],
        ];
    }

    #[DataProvider('validSchemaDependencyDataProvider')]
    public function testDifferentSchemaDependenciesForReferencedObject(array $input): void
    {
        $className = $this->generateClassFromFile('schemaDependency.json');

        $object = new $className($input);
        $this->assertSame($input['property1'] ?? null, $object->getProperty1());
        $this->assertSame($input['property2'] ?? null, $object->getProperty2());
        $this->assertSame($input['property3'] ?? null, $object->getProperty3());
    }

    public static function validSchemaDependencyDataProvider(): array
    {
        return [
            'No properties provided'        => [[]],
            'Property 3 without dependency' => [['property3' => true]],
            'Dependency Property 1'         => [['property1' => 'Hello', 'property3' => 'World']],
            'Dependency Property 2'         => [['property2' => 'Hello', 'property3' => 1]],
        ];
    }

    #[DataProvider('invalidSchemaDependencyDataProvider')]
    public function testDifferentSchemaDependenciesForReferencedObjectWithInvalidInput(
        array $input,
        string $expectedMessage,
    ): void {
        $className = $this->generateClassFromFile('schemaDependency.json');

        $this->expectException(InvalidSchemaDependencyException::class);
        $this->expectExceptionMessage($expectedMessage);

        new $className($input);
    }

    public static function invalidSchemaDependencyDataProvider(): array
    {
        return [
            'Dependency missing property 1' => [
                ['property1' => 'Hello'],
                <<<ERROR
                Invalid schema which is dependant on 'property1':
                  - Missing required value for 'property3'
                ERROR,
            ],
            'Dependency missing property 2' => [
                ['property2' => 'Hello'],
                <<<ERROR
                Invalid schema which is dependant on 'property2':
                  - Missing required value for 'property3'
                ERROR,
            ],
            'Dependency Property 1 invalid type' => [
                ['property1' => 'Hello', 'property3' => 1],
                <<<ERROR
                Invalid schema which is dependant on 'property1':
                  - Invalid type for 'property3': requires 'string', got 'integer'
                ERROR,
            ],
            'Dependency Property 2 invalid type' => [
                ['property2' => 'Hello', 'property3' => 'World'],
                <<<ERROR
                Invalid schema which is dependant on 'property2':
                  - Invalid type for 'property3': requires 'int', got 'string'
                ERROR,
            ],
            // the dependency schemas of property1 and property2 require disjoint types for property3, so one of them
            // always fails; the direct exception reports the first failing one
            'Both dependencies required 1' => [
                [
                    'property1' => 'Hello',
                    'property2' => 'Hello',
                    'property3' => 'World',
                ],
                <<<ERROR
                Invalid schema which is dependant on 'property2':
                  - Invalid type for 'property3': requires 'int', got 'string'
                ERROR,
            ],
            'Both dependencies required 2' => [
                [
                    'property1' => 'Hello',
                    'property2' => 'Hello',
                    'property3' => 1,
                ],
                <<<ERROR
                Invalid schema which is dependant on 'property1':
                  - Invalid type for 'property3': requires 'string', got 'integer'
                ERROR,
            ],
        ];
    }
}
