<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Tests that a property defined in root `properties` is not widened by anyOf/oneOf branches.
 */
class BasePropertyPrecedenceTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Root defines `age: integer`, both anyOf branches define `age: integer` — type is unchanged.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRootPropertyIsNotWidenedByAnyOfBranchWhenTypesAgree(): void
    {
        $className = $this->generateClassFromFile(
            'RootAndAnyOfSameType.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `age: integer`, one anyOf branch defines `age: string` — root type wins, no widening.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRootPropertyIsNotWidenedByAnyOfBranch(): void
    {
        $className = $this->generateClassFromFile(
            'RootAndAnyOfConflictingType.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `age: integer`, one oneOf branch defines `age: string` — root type wins, no widening.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRootPropertyIsNotWidenedByOneOfBranch(): void
    {
        $className = $this->generateClassFromFile(
            'RootAndOneOfConflictingType.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `age: string`; anyOf branch 1 only has `name: integer`, branch 2 has `age: string`.
     * The `exclusiveBranchPropertyNeedsWidening` path must not wipe the root type to `mixed`.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testRootPropertyExclusiveToOneBranchIsNotWidenedToMixed(): void
    {
        $className = $this->generateClassFromFile(
            'RootPropertyExclusiveToOneBranch.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * No root property; anyOf branches define `age: integer` and `age: string` — widening still occurs (regression).
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testCompositionOnlyPropertyIsStillWidened(): void
    {
        $className = $this->generateClassFromFile(
            'OnlyCompositionBranches.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'string', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `age: integer`, allOf branch also defines `age: integer` — no conflict, no exception.
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function testAllOfSameTypeAsRootIsAccepted(): void
    {
        $className = $this->generateClassFromFile(
            'RootAndAllOfSameType.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getParameterTypeNames($className, 'setAge'),
        );
        $this->assertEqualsCanonicalizing(
            ['int', 'null'],
            $this->getReturnTypeNames($className, 'getAge'),
        );
    }

    /**
     * Root defines `age: integer`, allOf branch defines `age: string` — conflicting allOf must throw SchemaException.
     *
     * @throws FileSystemException
     * @throws RenderException
     */
    public function testAllOfConflictWithRootThrowsSchemaException(): void
    {
        $this->expectException(SchemaException::class);

        $this->generateClassFromFile('RootAndAllOfConflictingType.json');
    }
}
