<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PatternPropertiesAccessorPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class PatternPropertiesTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
#[ApplicableDrafts]
class PatternPropertiesTest extends AbstractPHPModelGeneratorTestCase
{
    public function testInvalidPatternThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches("/Invalid pattern 'ab\[c' for pattern property in file .*\.json/");

        $this->generateClassFromFileTemplate('PatternProperty.json', ['ab[c']);
    }

    #[DataProvider('invalidTypedPatternPropertyDataProvider')]
    public function testTypedPatternPropertyWithInvalidInputThrowsAnException(
        GeneratorConfiguration $configuration,
        $propertyValue,
    ): void {
        $expectedMessage = 'Invalid type for pattern property. Requires string, got ' .
            (is_object($propertyValue) ? $propertyValue::class : gettype($propertyValue));

        $className = $this->generateClassFromFile('TypedPatternProperty.json', $configuration);

        try {
            new $className(['S_invalid' => $propertyValue]);
            $this->fail('Expected exception for invalid typed pattern property');
        } catch (ErrorRegistryException | InvalidPatternPropertiesException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());

            // collectErrors(true) wraps the pattern properties exception in an ErrorRegistryException.
            $innerException = $exception instanceof ErrorRegistryException
                ? $exception->getErrors()[0]
                : $exception;

            $this->assertInstanceOf(InvalidPatternPropertiesException::class, $innerException);
            $this->assertSame('/patternProperties/^S_', $innerException->getJsonPointer()->pointer);
        }
    }

    public static function invalidTypedPatternPropertyDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'int' => [1],
                'float' => [0.92],
                'bool' => [true],
                'array' => [[]],
                'object' => [new stdClass()],
            ],
        );
    }

    #[DataProvider('validTypedPatternPropertyDataProvider')]
    public function testTypedPatternPropertyWithValidInputIsValid(string $propertyValue): void
    {
        $className = $this->generateClassFromFile('TypedPatternProperty.json');
        $object = new $className(['S_valid' => $propertyValue]);

        $this->assertSame(['S_valid' => $propertyValue], $object->meta()->rawInput());
    }

    public static function validTypedPatternPropertyDataProvider(): array
    {
        return [
            'empty string' => [''],
            'spaces' => ['    '],
            'only non numeric chars' => ['abc'],
            'mixed string' => ['1234a'],
        ];
    }

    public function testForbiddenPatternPropertyIsAcceptedWhenNoMatchingKeyIsProvided(): void
    {
        $className = $this->generateClassFromFile('ForbiddenPatternProperty.json');
        $object = new $className(['other' => 'value']);

        $this->assertSame(['other' => 'value'], $object->meta()->rawInput());
    }

    #[DataProvider('validationMethodDataProvider')]
    public function testForbiddenPatternPropertyThrowsAnException(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ForbiddenPatternProperty.json', $configuration);

        try {
            new $className(['S~/invalid' => 'value']);
            $this->fail('Expected exception for forbidden pattern property');
        } catch (ErrorRegistryException | InvalidPatternPropertiesException $exception) {
            $this->assertStringContainsString(
                "invalid property 'S~/invalid' matching pattern '^S~/'",
                $exception->getMessage(),
            );

            // collectErrors(true) wraps the pattern properties exception in an ErrorRegistryException.
            $innerException = $exception instanceof ErrorRegistryException
                ? $exception->getErrors()[0]
                : $exception;

            $this->assertInstanceOf(InvalidPatternPropertiesException::class, $innerException);
            // Pattern '^S~/' contains '~' and '/' which encode to '~0' and '~1' respectively.
            $this->assertSame('/patternProperties/^S~0~1', $innerException->getJsonPointer()->pointer);

            $deniedPropertyException = $innerException->getNestedExceptions()['S~/invalid'][0];
            $this->assertSame('/patternProperties/^S~0~1', $deniedPropertyException->getJsonPointer()->pointer);
        }
    }

    public function testMultipleTransformingFiltersForPatternPropertiesAndObjectPropertyThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Applying multiple transforming filters for property alpha is not supported',
        );

        $this->generateClassFromFile('MultipleTransformingFilters.json');
    }

    /**
     * https://github.com/wol-soft/php-json-schema-model-generator/issues/65
     */
    public function testPatternEscaping(): void
    {
        $className = $this->generateClassFromFileTemplate('PatternProperty.json', ['a/(b|c)']);
        $object = new $className(['a/b' => 'Hello']);

        $this->assertSame(['a/b' => 'Hello'], $object->meta()->rawInput());
    }

    public function testNumericPatternProperties(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PatternPropertiesAccessorPostProcessor(),);
        };

        $className = $this->generateClassFromFileTemplate(
            'PatternProperty.json',
            ['^[0-9]+$'],
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $object = new $className([10 => 'Hello', '12' => 'World']);

        $this->assertSame(['10' => 'Hello', '12' => 'World'], $object->toArray());
        $this->assertSame(['10' => 'Hello', '12' => 'World'], $object->patternProperties()->get('^[0-9]+$'));
    }

    public function testDeclaredPropertyTypeIsNarrowedByPatternConstraint(): void
    {
        $className = $this->generateClassFromFile('DeclaredPropertyNarrowedByPattern.json');

        $this->assertSame(['int'], $this->getReturnTypeNames($className, 'getAlpha'));

        $object = new $className(['alpha' => 42]);
        $this->assertSame(42, $object->getAlpha());
    }

    public function testDeclaredPropertyConflictingWithPatternThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Property 'alpha' has type string but the matching patternProperties pattern '\\^a' requires type int/",
        );

        $this->generateClassFromFile('DeclaredPropertyConflictsWithPattern.json');
    }

    public function testDeclaredPropertyMatchingPatternExactlyIsUnchanged(): void
    {
        $className = $this->generateClassFromFile('DeclaredPropertyMatchesPatternExactly.json');

        $this->assertSame(['int'], $this->getReturnTypeNames($className, 'getAlpha'));

        $object = new $className(['alpha' => 20]);
        $this->assertSame(20, $object->getAlpha());
    }

    public function testDeclaredPropertyNotMatchingPatternIsUnaffected(): void
    {
        $className = $this->generateClassFromFile('DeclaredPropertyNotMatchingPattern.json');

        $this->assertSame(['string'], $this->getReturnTypeNames($className, 'getBeta'));
    }

    public function testPatternWithNoTypeDoesNotAffectDeclaredPropertyType(): void
    {
        $className = $this->generateClassFromFile('PatternWithNoType.json');

        $this->assertSame(['string'], $this->getReturnTypeNames($className, 'getAlpha'));
    }

    public function testCompositionPropertyTypeIsNarrowedByPatternConstraint(): void
    {
        $className = $this->generateClassFromFile('CompositionPropertyNarrowedByPattern.json');

        // anyOf-transferred properties are always non-required, so the return type includes null.
        $this->assertEqualsCanonicalizing(['int', 'null'], $this->getReturnTypeNames($className, 'getAlpha'));

        $object = new $className(['alpha' => 42]);
        $this->assertSame(42, $object->getAlpha());
    }

    public function testCompositionPropertyConflictingWithPatternThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/Property 'alpha' has type int\|string but the matching patternProperties pattern"
                . " '\\^a' requires type bool/",
        );

        $this->generateClassFromFile('CompositionPropertyConflictsWithPattern.json');
    }

    public function testPatternWithNonTransformingFilterStillAppliesIntersection(): void
    {
        $className = $this->generateClassFromFile('PatternWithNonTransformingFilterNarrows.json');

        // A non-transforming filter (trim) does not change the PHP type, so the intersection
        // check must still run. Both the declared and pattern type are string, so the type is
        // unchanged — but crucially no false conflict is raised and the schema generates cleanly.
        $this->assertSame(['string'], $this->getReturnTypeNames($className, 'getAlpha'));
    }

    public function testPatternWithTransformingFilterSkipsIntersectionCheck(): void
    {
        $className = $this->generateClassFromFile('PatternWithTransformingFilterSkipsIntersection.json');

        // A transforming filter (dateTime) changes the PHP type of the pattern property.
        // The intersection check is skipped; transferPatternPropertiesFilterToProperty runs and
        // updates the declared property type to DateTime (the filter's output type).
        $this->assertEqualsCanonicalizing(['DateTime', 'null'], $this->getReturnTypeNames($className, 'getAlpha'));
    }

    public function testObjectTypedPatternPropertiesAreValidated(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PatternPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile('PatternPropertiesObjectType.json');

        $object = new $className([
            'person_alice' => ['name' => 'Alice', 'age' => 30],
            'person_bob' => ['name' => 'Bob'],
        ]);

        $instances = $object->patternProperties()->get('^person_');
        $this->assertCount(2, $instances);

        $alice = $instances['person_alice'];
        $this->assertSame('Alice', $alice->getName());
        $this->assertSame(30, $alice->getAge());
        $this->assertClassHasJsonPointer($alice, '/patternProperties/^person_');
        $this->assertPropertyHasJsonPointer($alice, 'name', '/patternProperties/^person_/properties/name');

        $bob = $instances['person_bob'];
        $this->assertSame('Bob', $bob->getName());
        $this->assertNull($bob->getAge());
    }
}
