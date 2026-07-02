<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Class RequiredPropertyTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class RequiredPropertyTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     *
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('validStringPropertyValueProvider')]
    public function testRequiredPropertyIsValidIfProvided(bool $implicitNull, string $file, string $propertyValue): void
    {
        $className = $this->generateClassFromFile($file, null, false, $implicitNull);

        $object = new $className(['property' => $propertyValue]);
        $this->assertSame($propertyValue, $object->getProperty());
    }

    public static function requiredDefinitionsDataProvider(): array
    {
        return self::combineDataProvider(
            self::implicitNullDataProvider(),
            [
                'Defined property' => ['RequiredStringProperty.json'],
                'Undefined property' => ['RequiredUndefinedProperty.json'],
                'Reference in composition' => ['RequiredReferencePropertyInComposition.json'],
            ],
        );
    }

    public static function validStringPropertyValueProvider(): array
    {
        return self::combineDataProvider(
            self::requiredDefinitionsDataProvider(),
            [
                'Hello' => ['Hello'],
                'Empty string' => [''],
            ],
        );
    }

    /**
     *
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('requiredDefinitionsDataProvider')]
    public function testNotProvidedRequiredPropertyThrowsAnException(bool $implicitNull, string $file): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches("/Missing required value for property/");

        $className = $this->generateClassFromFile(
            $file,
            (new GeneratorConfiguration())->setCollectErrors(true),
            false,
            $implicitNull,
        );

        new $className([]);
    }

    #[DataProvider('requiredStringPropertyDataProvider')]
    public function testRequiredPropertyType(bool $implicitNull, string $schemaFile): void
    {
        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $returnType = $this->getReturnType($className, 'getProperty');
        $this->assertSame('string', $returnType->getName());
        $this->assertFalse($returnType->allowsNull());

        $setType = $this->getParameterType($className, 'setProperty');
        $this->assertSame('string', $setType->getName());
        $this->assertFalse($setType->allowsNull());
    }

    #[DataProvider('implicitNullDataProvider')]
    public function testUndefinedRequiredPropertyType(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile(
            'RequiredUndefinedProperty.json',
            (new GeneratorConfiguration())->setImmutable(false),
            false,
            $implicitNull,
        );

        $this->assertSame('mixed', $this->getReturnType($className, 'getProperty')->getName());
        $this->assertSame('mixed', $this->getReturnTypeAnnotation($className, 'getProperty'));

        $this->assertSame('mixed', $this->getParameterType($className, 'setProperty')->getName());
        $this->assertSame('mixed', $this->getParameterTypeAnnotation($className, 'setProperty'));
    }

    /**
     *
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    #[DataProvider('requiredStringPropertyDataProvider')]
    public function testNullProvidedForRequiredPropertyThrowsAnException(bool $implicitNull, string $schemaFile): void
    {
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches("/Invalid type for property/");

        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setCollectErrors(true),
            false,
            $implicitNull,
        );

        new $className(['property' => null]);
    }

    public static function requiredStringPropertyDataProvider(): array
    {
        return self::combineDataProvider(
            self::implicitNullDataProvider(),
            [
                'RequiredStringProperty' => ['RequiredStringProperty.json'],
                'RequiredReferencePropertyInComposition' => ['RequiredReferencePropertyInComposition.json'],
            ],
        );
    }

    public function testRequiredValueExceptionCarriesPointer(): void
    {
        $className = $this->generateClassFromFile(
            'RequiredStringProperty.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        try {
            new $className([]);
            $this->fail('Expected RequiredValueException');
        } catch (RequiredValueException $exception) {
            // The required keyword is on the parent object schema, not the property schema.
            $this->assertSame('/required', $exception->getJsonPointer()->pointer);
        }
    }

    public function testRequiredInAllOfBranchCarriesPointer(): void
    {
        // Property and required are both declared in the same allOf branch.
        // The inner RequiredValueException must point to that branch's required keyword.
        $className = $this->generateClassFromFile(
            'RequiredPropertyInAllOfBranch.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        try {
            new $className([]);
            $this->fail('Expected ErrorRegistryException');
        } catch (ErrorRegistryException $registryException) {
            /** @var AllOfException $allOfException */
            $allOfException = $registryException->getErrors()[0];
            $this->assertInstanceOf(AllOfException::class, $allOfException);

            /** @var RequiredValueException $requiredError */
            $requiredError = $allOfException->getCompositionErrorCollection()[0]->getErrors()[0];
            $this->assertInstanceOf(RequiredValueException::class, $requiredError);
            $this->assertSame('/allOf/0/required', $requiredError->getJsonPointer()->pointer);
        }
    }

    public function testRequiredCrossAllOfBranchesCarriesPointer(): void
    {
        // Property is defined in allOf branch 0 (optional there). Required is in allOf branch 1.
        // The inner RequiredValueException must point to branch 1's required keyword.
        $className = $this->generateClassFromFile(
            'RequiredPropertyCrossAllOfBranches.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        try {
            new $className([]);
            $this->fail('Expected ErrorRegistryException');
        } catch (ErrorRegistryException $registryException) {
            /** @var AllOfException $allOfException */
            $allOfException = $registryException->getErrors()[0];
            $this->assertInstanceOf(AllOfException::class, $allOfException);

            // Branch 0 succeeds (name is optional there); branch 1 fails (name is required).
            $this->assertEmpty($allOfException->getCompositionErrorCollection()[0]->getErrors());

            /** @var RequiredValueException $requiredError */
            $requiredError = $allOfException->getCompositionErrorCollection()[1]->getErrors()[0];
            $this->assertInstanceOf(RequiredValueException::class, $requiredError);
            $this->assertSame('/allOf/1/required', $requiredError->getJsonPointer()->pointer);
        }
    }

    public function testNestedObjectRequiredCarriesPointer(): void
    {
        // Required is declared on the nested object schema, not the root.
        // The ObjectInstantiationDecorator wraps the inner RequiredValueException in a
        // NestedObjectException; unwrap it to assert the inner exception's pointer.
        $className = $this->generateClassFromFile(
            'RequiredNestedObjectProperty.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        try {
            // Provide address so the nested object is instantiated, but omit 'street'.
            new $className(['address' => []]);
            $this->fail('Expected NestedObjectException');
        } catch (NestedObjectException $exception) {
            $inner = $exception->getNestedException();
            $this->assertInstanceOf(RequiredValueException::class, $inner);
            /** @var RequiredValueException $inner */
            $this->assertSame('/properties/address/required', $inner->getJsonPointer()->pointer);
        }
    }

    public function testTrueSchemaPropertyRequiredInNestedObjectCarriesPointer(): void
    {
        // A "property": true schema inside a nested object, combined with required,
        // must still compute the correct required pointer for the nested object.
        // The NestedObjectException must wrap a RequiredValueException with the nested pointer.
        $className = $this->generateClassFromFile(
            'RequiredTruePropertyInNestedObject.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        try {
            new $className(['address' => []]);
            $this->fail('Expected NestedObjectException');
        } catch (NestedObjectException $exception) {
            $inner = $exception->getNestedException();
            $this->assertInstanceOf(RequiredValueException::class, $inner);
            /** @var RequiredValueException $inner */
            $this->assertSame('/properties/address/required', $inner->getJsonPointer()->pointer);
        }
    }
}
