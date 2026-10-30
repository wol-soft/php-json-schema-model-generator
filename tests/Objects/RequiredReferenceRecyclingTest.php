<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use ReflectionClass;
use TypeError;

/**
 * SchemaDefinition::resolveReference() recycles one property per definition path and metadata, so several schemas
 * (and several properties of one schema) can end up holding the same underlying property. A required check added for
 * one of them must neither appear in the class of another schema nor be rendered twice.
 */
#[ApplicableDrafts]
class RequiredReferenceRecyclingTest extends AbstractPHPModelGeneratorTestCase
{
    private const ROLE_REQUIRED_POINTER = '/allOf/0/properties/roles/items/required';

    /**
     * A $ref which is required by the referencing base schema and by an inline item schema of an allOf branch
     * shares one recycled property. Each generated class must render exactly one required check for the property,
     * carrying the pointer of its own schema.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testReferenceRequiredByTwoSchemasRendersOneCheckPerClassWithItsOwnPointer(): void
    {
        $this->generateDirectory('BothRequired', $this->directoryConfig('RequiredInBothUsages'));

        $baseClass = $this->classOf('Base');
        $roleClass = $this->classOf('Main_Role');

        $this->assertSame(['/required'], $this->requiredPointers($baseClass, 'p'));
        $this->assertSame(['/required'], $this->requiredPointers($baseClass, 'title'));
        $this->assertSame([self::ROLE_REQUIRED_POINTER], $this->requiredPointers($roleClass, 'p'));
        $this->assertSame([self::ROLE_REQUIRED_POINTER], $this->requiredPointers($roleClass, 'role'));

        // a request missing the property yields exactly one error per class
        $baseException = $this->catchException(static fn(): object => new $baseClass(['title' => 'x']));
        $this->assertSame("Missing required value for 'p'", $baseException?->getMessage());

        $roleException = $this->catchException(static fn(): object => new $roleClass(['role' => 'x']));
        $this->assertSame("Missing required value for 'p'", $roleException?->getMessage());

        $this->assertSame(['a'], (new $baseClass(['title' => 'x', 'p' => ['a']]))->getP());
        $this->assertSame(['a'], (new $roleClass(['role' => 'x', 'p' => ['a']]))->getP());
    }

    /**
     * Only one of the usages requires the property: the usages don't share a recycled property, and the optional
     * class must not receive the required check of the other one.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testReferenceRequiredByOneSchemaOnlyRendersTheCheckInThatClassOnly(): void
    {
        $this->generateDirectory('RequiredInOneOnly', $this->directoryConfig('RequiredInOneUsage'));

        $baseClass = $this->classOf('Base');
        $roleClass = $this->classOf('Main_Role');

        $this->assertSame([], $this->requiredPointers($baseClass, 'p'));
        $this->assertSame([self::ROLE_REQUIRED_POINTER], $this->requiredPointers($roleClass, 'p'));

        $this->assertNull((new $baseClass(['title' => 'x']))->getP());

        $roleException = $this->catchException(static fn(): object => new $roleClass(['role' => 'x']));
        $this->assertSame("Missing required value for 'p'", $roleException?->getMessage());
    }

    /**
     * The same $ref is used as the items of an array property, as a required plain property of the base schema and
     * as a required plain property of an inline item schema. The array item usage must not gain a required check, and
     * the plain usages must not leak into each other.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testReferenceUsedAsArrayItemsAndAsRequiredPropertyKeepsTheUsagesApart(): void
    {
        $this->generateDirectory(
            'SharedAsItemsAndProperty',
            $this->directoryConfig('SharedAsItemsAndProperty'),
        );

        $baseClass = $this->classOf('Base');
        $roleClass = $this->classOf('Main_Role');

        $this->assertSame(['/required'], $this->requiredPointers($baseClass, 'p'));
        $this->assertSame([], $this->requiredPointers($baseClass, 'list'));
        $this->assertSame([self::ROLE_REQUIRED_POINTER], $this->requiredPointers($roleClass, 'p'));

        $this->assertSame(
            2,
            substr_count($this->sourceOf($baseClass), 'RequiredValueException('),
            'Only the "title" and "p" properties are required',
        );

        $object = new $baseClass(['title' => 'x', 'p' => 'ab', 'list' => ['ab', 'cd']]);
        $this->assertSame(['ab', 'cd'], $object->getList());

        $listException = $this->catchException(
            static fn(): object => new $baseClass(['title' => 'x', 'p' => 'ab', 'list' => ['ab', 'c']]),
        );
        $this->assertNotNull($listException);

        $missingException = $this->catchException(static fn(): object => new $baseClass(['title' => 'x']));
        $this->assertSame("Missing required value for 'p'", $missingException?->getMessage());
    }

    /**
     * Two required properties of one schema referencing the same definition share the recycled property. Each of them
     * must render exactly one required check of its own name.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testTwoRequiredPropertiesReferencingTheSameDefinitionRenderOneCheckEach(): void
    {
        $this->generateDirectory(
            'TwoRequiredPropertiesSharingRef',
            $this->directoryConfig('TwoRequiredPropertiesSharingRef'),
        );

        $baseClass = $this->classOf('Base');

        $this->assertSame(['/required'], $this->requiredPointers($baseClass, 'p'));
        $this->assertSame(['/required'], $this->requiredPointers($baseClass, 'q'));

        $pException = $this->catchException(static fn(): object => new $baseClass(['q' => ['a']]));
        $this->assertSame("Missing required value for 'p'", $pException?->getMessage());

        $qException = $this->catchException(static fn(): object => new $baseClass(['p' => ['a']]));
        $this->assertSame("Missing required value for 'q'", $qException?->getMessage());
    }

    /**
     * Two required properties reference a recursive definition: the recycled property is still being resolved while
     * the required checks are attached, so neither a shared nor an unresolved property may break the generation or
     * lose a check.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRequiredPropertiesReferencingARecursiveDefinitionRenderOneCheckEach(): void
    {
        $this->generateDirectory(
            'RecursiveRequiredReference',
            $this->directoryConfig('RecursiveRequiredReference'),
        );

        $mainClass = $this->classOf('Main');

        $this->assertSame(['/required'], $this->requiredPointers($mainClass, 'first'));
        $this->assertSame(['/required'], $this->requiredPointers($mainClass, 'second'));

        $object = new $mainClass([
            'first' => ['name' => 'a', 'children' => [['name' => 'b']]],
            'second' => ['name' => 'c'],
        ]);
        $this->assertSame('b', $object->getFirst()->getChildren()[0]->getName());

        $firstException = $this->catchException(
            static fn(): object => new $mainClass(['second' => ['name' => 'c']]),
        );
        $this->assertSame("Missing required value for 'first'", $firstException?->getMessage());

        $nameException = $this->catchException(
            static fn(): object => new $mainClass([
                'first' => ['name' => 'a', 'children' => [[]]],
                'second' => ['name' => 'c'],
            ]),
        );
        $this->assertSame(
            <<<MSG
            Invalid nested object for property 'first':
              - Invalid items in array 'children':
                  - invalid item #0
                    * Missing required value for 'name'
            MSG,
            $nameException?->getMessage(),
        );
    }

    /**
     * A required property which references the definition it is declared in, where the definition itself is
     * referenced by a required property: both references share one recycled property which is still unresolved while
     * the definition's required check is attached, so the check has no resolved property to be added to.
     *
     * Known defect which does not depend on the recycling of required checks (it occurs identically when the required
     * check is added to the recycled property directly): generation fails with a TypeError instead of rendering the
     * class. The assertion documents the current behaviour. When the defect is fixed, replace it by assertions on the
     * generated class (one required check for 'next' carrying the pointer of the definition's 'required' keyword).
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRequiredPropertyReferencingItsOwnDefinitionFailsGenerationWithATypeError(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage(
            'PropertyProxy::getProperty(): Return value must be of type '
                . 'PHPModelGenerator\Model\Property\PropertyInterface, null returned',
        );

        $this->generateDirectory('RequiredSelfReference', $this->directoryConfig('RequiredSelfReference'));
    }

    private function directoryConfig(string $namespace): GeneratorConfiguration
    {
        return (new GeneratorConfiguration())
            ->setNamespacePrefix($namespace)
            ->setImmutable(true)
            ->setCollectErrors(false);
    }

    private function classOf(string $name): string
    {
        return "\\{$this->lastGeneratedNamespacePrefix}\\$name";
    }

    private function sourceOf(string $className): string
    {
        return file_get_contents((new ReflectionClass($className))->getFileName());
    }

    /**
     * The JSON pointers of all required checks rendered for the given property, in render order.
     *
     * @return string[]
     */
    private function requiredPointers(string $className, string $propertyName): array
    {
        preg_match_all(
            sprintf(
                "/RequiredValueException\(\\\$value \?\? null, \.\.\.\['%s', '([^']+)'\]\)/",
                preg_quote($propertyName, '/'),
            ),
            $this->sourceOf($className),
            $matches,
        );

        return $matches[1];
    }
}
