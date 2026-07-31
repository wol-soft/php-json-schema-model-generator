<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A composition that defines its own generated PHP class (file root, a cross-file $ref target)
 * must resolve to a definite object - see SchemaProcessor::checkObjectRepresentability(). Named
 * object properties, array items, and schema-dependency targets always force an explicit
 * `type: object` before reaching that check, so they can never trigger the rejection regardless
 * of their own composition branches; see the "PreImmunizedSites" test below.
 */
class ComposedObjectShapeValidationTest extends AbstractPHPModelGeneratorTestCase
{
    public function testRootCompositionOfBareDescribingBranchesIsRejectedByDefault(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for '.*' in file '.*\\.json' does not resolve to a definite object and cannot be"
                . ' represented as a generated class/',
        );

        $this->generateClassFromFile('RootOneOfBareDescribingBranches.json');
    }

    public function testRootCompositionOfBareDescribingBranchesIsAcceptedWhenImplicitObjectCompositionIsAllowed(): void
    {
        $className = $this->generateClassFromFile(
            'RootOneOfBareDescribingBranches.json',
            (new GeneratorConfiguration())->setImplicitObjectComposition(true),
        );

        $object = new $className(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());
    }

    public function testExplicitObjectTypeAtRootAcceptsDescribingBranchesRegardlessOfConfig(): void
    {
        $className = $this->generateClassFromFile('RootExplicitObjectWithDescribingOneOf.json');

        $object = new $className(['code' => 42]);
        $this->assertSame(42, $object->getCode());
    }

    #[DataProvider('multiTypeBranchConfigDataProvider')]
    public function testRootCompositionWithMultiTypeBranchIsAlwaysRejected(GeneratorConfiguration $configuration): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for '.*' in file '.*\\.json' does not resolve to a definite object and cannot be"
                . ' represented as a generated class/',
        );

        $this->generateClassFromFile('RootOneOfMultiTypeBranch.json', $configuration);
    }

    public static function multiTypeBranchConfigDataProvider(): array
    {
        return [
            'default config' => [new GeneratorConfiguration()],
            'implicit object composition allowed' => [
                (new GeneratorConfiguration())->setImplicitObjectComposition(true),
            ],
        ];
    }

    public function testRootIfThenElseWithFullBranchCoverageIsAccepted(): void
    {
        $className = $this->generateClassFromFile('RootIfThenElseFullCoverage.json');

        $object = new $className(['name' => 'Hannes']);
        $this->assertSame('Hannes', $object->getName());
    }

    public function testRootIfThenElseWithPartialBranchCoverageIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for '.*' in file '.*\\.json' does not resolve to a definite object and cannot be"
                . ' represented as a generated class/',
        );

        $this->generateClassFromFile('RootIfThenElsePartialCoverage.json');
    }

    /**
     * A named object property and an array item can each independently have their own
     * describing-only oneOf, unresolvable via ObjectShapeResolver alone - but both sites always
     * force `type: object` onto the JSON before it ever reaches the representability check
     * (createObjectProperty() is only ever called by callers that already set it), so neither
     * site can trigger a rejection here.
     */
    public function testNamedPropertyAndArrayItemClassBoundariesAreNeverRejected(): void
    {
        $className = $this->generateClassFromFile('PreImmunizedSites.json');

        $object = new $className([
            'namedObject' => ['code' => 42],
            'arrayOfObjects' => [['name' => 'Hannes'], ['code' => 7]],
        ]);

        $this->assertSame(42, $object->getNamedObject()->getCode());
        $this->assertSame('Hannes', $object->getArrayOfObjects()[0]->getName());
        $this->assertSame(7, $object->getArrayOfObjects()[1]->getCode());
    }

    /**
     * A $ref to a file within the schema provider's base directory makes that file its own
     * class-defining schema, parsed eagerly via SchemaProcessor::processTopLevelSchema() - the
     * same choke point as the referencing file's own root, and (unlike createObjectProperty())
     * with no caller forcing `type: object` first. An ambiguous composition in that file must be
     * rejected there, not silently accepted. Uses generateDirectory() rather than
     * generateClassFromFile(): the latter only ever writes a single schema file into the base
     * directory, which cannot exercise the "reference to a file the provider will also discover
     * on its own" path this test targets.
     */
    public function testCrossFileReferenceTargetIsRejectedWhenAmbiguous(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Composition for '.*' in file '.*Ambiguous\\.json' does not resolve to a definite object and cannot"
                . ' be represented as a generated class/',
        );

        $this->generateDirectory('CrossFileReference', new GeneratorConfiguration());
    }
}
