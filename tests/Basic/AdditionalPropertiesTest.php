<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Interfaces\JSONModelInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class AdditionalPropertiesTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class AdditionalPropertiesTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('additionalPropertiesDataProvider')]
    public function testAdditionalPropertiesAreIgnoredByDefault(array $propertyValue): void
    {
        $className = $this->generateClassFromFile('AdditionalPropertiesNotDefined.json');

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
        $this->assertSame($propertyValue['age'] ?? null, $object->getAge());
    }

    #[DataProvider('additionalPropertiesDataProvider')]
    public function testAdditionalPropertiesAreIgnoredWhenSetToTrue(array $propertyValue): void
    {
        $className = $this->generateClassFromFileTemplate(
            'AdditionalProperties.json',
            ['true'],
            // make sure the deny additional properties setting doesn't affect specified additional properties
            (new GeneratorConfiguration())->setDenyAdditionalProperties(true),
        );

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
        $this->assertSame($propertyValue['age'] ?? null, $object->getAge());
    }

    public static function additionalPropertiesDataProvider(): array
    {
        return [
            'all properties plus additional property' => [['name' => 'test', 'age' => 24, 'additional' => 'ignored']],
            'some properties plus additional property' => [['age' => 24, 'additional' => 'ignored']],
            'only additional property' => [['additional' => 'ignored']]
        ];
    }

    #[DataProvider('definedPropertiesDataProvider')]
    public function testDefinedPropertiesAreAcceptedWhenSetToFalse(array $propertyValue): void
    {
        $className = $this->generateClassFromFileTemplate('AdditionalProperties.json', ['false']);

        $object = new $className($propertyValue);
        $this->assertSame($propertyValue['name'] ?? null, $object->getName());
        $this->assertSame($propertyValue['age'] ?? null, $object->getAge());
    }

    public static function definedPropertiesDataProvider(): array
    {
        return [
            'all properties' => [['name' => 'test', 'age' => 24]],
            'some properties' => [['age' => 24]],
            'no property' => [[]]
        ];
    }

    #[DataProvider('additionalPropertiesDataProvider')]
    public function testAdditionalPropertiesThrowAnExceptionWhenSetToFalse(array $propertyValue): void
    {
        $className = $this->generateClassFromFileTemplate('AdditionalProperties.json', ['false']);

        try {
            new $className($propertyValue);
            $this->fail('Expected AdditionalPropertiesException');
        } catch (AdditionalPropertiesException $exception) {
            $this->assertMatchesRegularExpression(
                "/Provided JSON for .* contains not allowed additional properties \['additional'\]/",
                $exception->getMessage(),
            );
            $this->assertSame('/additionalProperties', $exception->getJsonPointer()->pointer);
        }
    }

    #[DataProvider('additionalPropertiesDataProvider')]
    public function testAdditionalPropertiesThrowAnExceptionWhenNotDefinedAndDeniedByGeneratorConfiguration(
        array $propertyValue,
    ): void {
        $className = $this->generateClassFromFile(
            'AdditionalPropertiesNotDefined.json',
            (new GeneratorConfiguration())->setDenyAdditionalProperties(true)->setCollectErrors(false),
        );

        try {
            new $className($propertyValue);
            $this->fail('Expected AdditionalPropertiesException');
        } catch (AdditionalPropertiesException $exception) {
            $this->assertMatchesRegularExpression(
                "/Provided JSON for .* contains not allowed additional properties \['additional'\]/",
                $exception->getMessage(),
            );
            // denyAdditionalProperties() synthesizes the check without a literal "additionalProperties"
            // keyword in the schema; the pointer still points at the synthetic root location.
            $this->assertSame('/additionalProperties', $exception->getJsonPointer()->pointer);
        }
    }

    #[DataProvider('validTypedAdditionalPropertiesDataProvider')]
    public function testValidTypedAdditionalPropertiesAreValid(
        GeneratorConfiguration $generatorConfiguration,
        array $propertyValue,
    ): void {
        $className = $this->generateClassFromFile('AdditionalPropertiesTyped.json', $generatorConfiguration);

        $object = new $className($propertyValue);

        $this->assertEquals($propertyValue['id'] ?? null, $object->getId());
        foreach ($propertyValue as $key => $value) {
            $this->assertSame($value, $object->meta()->rawInput()[$key]);
        }
    }

    public static function validTypedAdditionalPropertiesDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'no provided values' => [[]],
                'only defined property' => [['id' => 12]],
                'only additional properties' => [['additional1' => 'AB', 'additional2' => '12345']],
                'defined and additional properties' => [['id' => 10, 'additional1' => 'AB', 'additional2' => '12345']],
            ],
        );
    }

    #[DataProvider('invalidTypedAdditionalPropertiesDataProvider')]
    public function testInvalidTypedAdditionalPropertiesThrowsAnException(
        GeneratorConfiguration $generatorConfiguration,
        array $propertyValue,
        string $errorMessage,
    ): void {
        $className = $this->generateClassFromFile('AdditionalPropertiesTyped.json', $generatorConfiguration);

        try {
            new $className($propertyValue);
            $this->fail('Expected exception for invalid typed additional property');
        } catch (ErrorRegistryException | InvalidAdditionalPropertiesException $exception) {
            $this->assertStringContainsString($errorMessage, $exception->getMessage());

            // collectErrors(true) wraps the additional properties exception in an ErrorRegistryException.
            $innerException = $exception instanceof ErrorRegistryException
                ? $exception->getErrors()[0]
                : $exception;

            $this->assertInstanceOf(InvalidAdditionalPropertiesException::class, $innerException);
            $this->assertSame('/additionalProperties', $innerException->getJsonPointer()->pointer);
        }
    }

    public static function invalidTypedAdditionalPropertiesDataProvider(): array
    {
        $exception = <<<ERROR
        contains invalid additional properties
          - invalid additional property 'additional1'
            * %s
        ERROR;

        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'invalid type for additional property (null)' => [
                    ['additional1' => null, 'additional2' => 'Hello'],
                    sprintf($exception, "Invalid type for 'additional property': requires 'string', got 'NULL'")
                ],
                'invalid type for additional property (int)' => [
                    ['additional1' => 1, 'additional2' => 'Hello'],
                    sprintf(
                        $exception,
                        "Invalid type for 'additional property': requires 'string', got 'integer'",
                    )
                ],
                'invalid type for additional property (float)' => [
                    ['additional1' => 0.92, 'additional2' => 'Hello'],
                    sprintf(
                        $exception,
                        "Invalid type for 'additional property': requires 'string', got 'double'",
                    )
                ],
                'invalid type for additional property (bool)' => [
                    ['additional1' => true, 'additional2' => 'Hello'],
                    sprintf(
                        $exception,
                        "Invalid type for 'additional property': requires 'string', got 'boolean'",
                    )
                ],
                'invalid type for additional property (array)' => [
                    ['additional1' => [], 'additional2' => 'Hello'],
                    sprintf($exception, "Invalid type for 'additional property': requires 'string', got 'array'")
                ],
                'invalid type for additional property (object)' => [
                    ['additional1' => new stdClass(), 'additional2' => 'Hello'],
                    sprintf(
                        $exception,
                        "Invalid type for 'additional property': requires 'string', got 'stdClass'",
                    )
                ],
                'empty short string' => [
                    ['additional1' => '', 'additional2' => 'Hello'],
                    sprintf($exception, "Value for 'additional property' must not be shorter than 2")
                ],
                'too short string' => [
                    ['additional1' => '1', 'additional2' => 'Hello'],
                    sprintf($exception, "Value for 'additional property' must not be shorter than 2")
                ],
                'too long string' => [
                    ['additional1' => '12345678', 'additional2' => 'Hello'],
                    sprintf($exception, "Value for 'additional property' must not be longer than 5")
                ],
            ],
        );
    }

    #[DataProvider('validAdditionalPropertiesObjectsDataProvider')]
    public function testValidAdditionalPropertiesObjectsAreValid(
        GeneratorConfiguration $generatorConfiguration,
        array $propertyValue,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile('AdditionalPropertiesObject.json', $generatorConfiguration);

        $object = new $className($propertyValue);

        $this->assertEquals($propertyValue['id'] ?? null, $object->getId());
        foreach ($propertyValue as $key => $value) {
            $this->assertSame($value, $object->meta()->rawInput()[$key]);
        }

        // Verify JSON pointer for additional property object instances when present
        $additionalInstance = $object->additionalProperties()->get('additional1')
            ?? $object->additionalProperties()->get('additional2')
            ?? null;
        if ($additionalInstance instanceof JSONModelInterface) {
            $this->assertClassHasJsonPointer($additionalInstance, '/additionalProperties');
            $this->assertPropertyHasJsonPointer($additionalInstance, 'name', '/additionalProperties/properties/name');
        }
    }

    public static function validAdditionalPropertiesObjectsDataProvider(): array
    {
        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'no provided values' => [[]],
                'only defined property' => [['id' => 12]],
                'only additional properties' => [[
                    'additional1' => ['name' => 'AB'],
                    'additional2' => ['name' => 'AB', 'age' => 12],
                ]],
                'defined and additional properties' => [[
                    'id' => 10,
                     'additional1' => ['name' => 'AB'],
                     'additional2' => ['name' => 'AB', 'age' => 12],
                ]],
            ],
        );
    }

    #[DataProvider('invalidAdditionalPropertiesObjectsDataProvider')]
    public function testInvalidAdditionalPropertiesObjectsThrowsAnException(
        GeneratorConfiguration $generatorConfiguration,
        array $propertyValue,
        string $errorMessage,
    ): void {
        $className = $this->generateClassFromFile('AdditionalPropertiesObject.json', $generatorConfiguration);

        try {
            new $className($propertyValue);
            $this->fail('Expected exception for invalid additional property object');
        } catch (ErrorRegistryException | InvalidAdditionalPropertiesException $exception) {
            $this->assertStringContainsString($errorMessage, $exception->getMessage());

            // collectErrors(true) wraps the additional properties exception in an ErrorRegistryException.
            $innerException = $exception instanceof ErrorRegistryException
                ? $exception->getErrors()[0]
                : $exception;

            $this->assertInstanceOf(InvalidAdditionalPropertiesException::class, $innerException);
            $this->assertSame('/additionalProperties', $innerException->getJsonPointer()->pointer);
        }
    }

    public static function invalidAdditionalPropertiesObjectsDataProvider(): array
    {
        $exception = <<<ERROR
        contains invalid additional properties
          - invalid additional property 'additional1'
            * %s
        ERROR;

        return self::combineDataProvider(
            self::validationMethodDataProvider(),
            [
                'invalid type for additional property (null)' => [
                    ['additional1' => null, 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, "Invalid type for 'additional property': requires 'object', got 'NULL'")
                ],
                'invalid type for additional property (int)' => [
                    ['additional1' => 1, 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf(
                        $exception,
                        "Invalid type for 'additional property': requires 'object', got 'integer'",
                    )
                ],
                'invalid type for additional property (float)' => [
                    ['additional1' => 0.92, 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf(
                        $exception,
                        "Invalid type for 'additional property': requires 'object', got 'double'",
                    )
                ],
                'invalid type for additional property (bool)' => [
                    ['additional1' => true, 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf(
                        $exception,
                        "Invalid type for 'additional property': requires 'object', got 'boolean'",
                    )
                ],
                'invalid type for additional property (object)' => [
                    ['additional1' => 'Hello', 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, "Invalid type for 'additional property': requires 'object', got 'string'")
                ],
                'Missing required name' => [
                    ['additional1' => ['age' => 12], 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, "Missing required value for 'name'")
                ],
                "Invalid type for 'name'" => [
                    ['additional1' => ['name' => 12], 'additional2' => ['name' => 'AB', 'age' => 12]],
                    sprintf($exception, "Invalid type for 'name': requires 'string', got 'integer'")
                ],
                'Multiple violations' => [
                    ['additional1' => ['name' => 12], 'additional2' => ['name' => 'AB', 'age' => '12']],
                    <<<ERROR
                    contains invalid additional properties
                      - invalid additional property 'additional1'
                        * Invalid type for 'name': requires 'string', got 'integer'
                      - invalid additional property 'additional2'
                        * Invalid type for 'age': requires 'int', got 'string'
                    ERROR,
                ],
            ],
        );
    }
}
