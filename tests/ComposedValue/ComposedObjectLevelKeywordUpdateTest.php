<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * A composition of the schema remembers the result of each branch and evaluates a branch again only if an update
 * changes one of the properties the branch declares. A branch with an object-level keyword (maxProperties,
 * additionalProperties, patternProperties, propertyNames, dependencies, a nested composition, ...) depends on keys it
 * does not declare. Such a branch must be evaluated again on every update, otherwise an update the constructor
 * rejects is accepted.
 *
 * The reference for each update is the constructor: an update must be rejected with the same exception the
 * constructor throws for the resulting model data.
 */
class ComposedObjectLevelKeywordUpdateTest extends AbstractPHPModelGeneratorTestCase
{
    public static function objectLevelKeywordProvider(): array
    {
        return [
            'maxProperties' => [', "maxProperties": 1', false, false, true],
            'maxProperties (collect errors)' => [', "maxProperties": 1', false, true, true],
            'additionalProperties' => [', "additionalProperties": false', false, false, true],
            'implicitly denied additionalProperties' => ['', true, false, true],
            'patternProperties' => [
                ', "patternProperties": {"^(note|extra)$": {"type": "integer"}}',
                false,
                false,
                true,
            ],
            'propertyNames' => [', "propertyNames": {"pattern": "^kind$"}', false, false, true],
            'schema dependency' => [', "dependencies": {"kind": {"maxProperties": 1}}', false, false, true],
            'nested composition' => [', "allOf": [{"maxProperties": 1}]', false, false, true],
        ];
    }

    #[DataProvider('objectLevelKeywordProvider')]
    public function testUpdateViolatingObjectLevelKeywordOfBranchIsRejectedLikeTheConstructor(
        string $branchKeyword,
        bool $denyAdditionalProperties,
        bool $collectErrors,
        bool $additionalPropertyViolatesBranch,
    ): void {
        $this->addUpdatePostProcessors();

        $className = $this->generateClassFromFileTemplate(
            'BranchWithObjectLevelKeyword.json',
            [$branchKeyword],
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors($collectErrors)
                ->setDenyAdditionalProperties($denyAdditionalProperties),
            false,
        );

        $this->assertUpdatesAreRejectedLikeTheConstructor($className, $additionalPropertyViolatesBranch);
    }

    public function testUpdateViolatingObjectLevelKeywordOfReferencedBranchIsRejectedLikeTheConstructor(): void
    {
        $this->addUpdatePostProcessors();

        $className = $this->generateClassFromFile(
            'ReferencedBranchWithObjectLevelKeyword.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $this->assertUpdatesAreRejectedLikeTheConstructor($className, true);
    }

    public static function removalProvider(): array
    {
        return [
            'minProperties' => ['BranchWithObjectLevelKeyword.json', [', "minProperties": 3'], false],
            'minProperties (collect errors)' => ['BranchWithObjectLevelKeyword.json', [', "minProperties": 3'], true],
            // the dependency of the declared property note requires the additional property extra
            'property dependency' => ['BranchWithPropertyDependency.json', [], false],
        ];
    }

    /**
     * Removing an additional property changes keys a branch with an object-level keyword depends on: the removal
     * must be rejected like the constructor rejects the remaining data, and leave the model unchanged.
     */
    #[DataProvider('removalProvider')]
    public function testRemovalViolatingObjectLevelKeywordOfBranchIsRejectedLikeTheConstructor(
        string $schemaFile,
        array $templateValues,
        bool $collectErrors,
    ): void {
        $this->addUpdatePostProcessors();

        $className = $this->generateClassFromFileTemplate(
            $schemaFile,
            $templateValues,
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors($collectErrors),
            false,
        );

        $initialData = ['kind' => 'B', 'note' => 'n', 'extra' => 'e'];
        $remainingData = ['kind' => 'B', 'note' => 'n'];

        $expectedException = $this->catchException(static fn() => new $className($remainingData));
        $this->assertNotNull($expectedException, 'The constructor must reject the remaining data');

        $object = new $className($initialData);
        $actualException = $this->catchException(static fn() => $object->additionalProperties()->remove('extra'));

        $this->assertNotNull($actualException, 'The removal must be rejected');
        $this->assertSame($expectedException::class, $actualException::class);
        $this->assertSame($expectedException->getMessage(), $actualException->getMessage());

        $this->assertSame($initialData, $object->meta()->rawInput());
        $this->assertSame(['extra' => 'e'], $object->additionalProperties()->getAll());

        // a removal which keeps the data valid is accepted
        $object = new $className($initialData + ['other' => 'o']);
        $this->assertTrue($object->additionalProperties()->remove('other'));
        $this->assertSame($initialData, $object->meta()->rawInput());
    }

    public static function patternPropertyMatchingDeclaredPropertyProvider(): array
    {
        return [
            'branch with object-level keyword' => ['', ', "maxProperties": 1'],
            'branch declaring the property' => [', "note": {"type": "string", "maxLength": 0}', ''],
        ];
    }

    /**
     * The setter of a property matching a pattern property executes the base validators to store the pattern
     * property. The composition must be validated only once by such a setter, otherwise each failing composition is
     * reported twice with collected errors.
     */
    #[DataProvider('patternPropertyMatchingDeclaredPropertyProvider')]
    public function testUpdateOfPropertyMatchingPatternPropertyIsRejectedLikeTheConstructor(
        string $branchProperty,
        string $branchKeyword,
    ): void {
        $this->addUpdatePostProcessors();

        $className = $this->generateClassFromFileTemplate(
            'PatternPropertyMatchingDeclaredProperty.json',
            [$branchProperty, $branchKeyword],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(true),
            false,
        );

        $this->assertUpdatesAreRejectedLikeTheConstructor($className, false);
    }

    /**
     * A post processor may add properties after the composition validation was set up. The setter of such a property
     * must validate a composition which depends on all properties as well.
     *
     * Mapping the setters when the composition validation is set up was rejected: a property added by a later post
     * processor isn't part of the schema at that time, its setter accepted data the constructor rejects.
     */
    public function testSetterOfPropertyAddedByLaterPostProcessorIsRejectedLikeTheConstructor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new class () extends PostProcessor {
                public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
                {
                    // only the schema containing the composition, not the classes of its branches
                    if ($schema->getCompositionValidatorKeys() === []) {
                        return;
                    }

                    $schema->addProperty(
                        new Property(
                            'added',
                            new PropertyType('string'),
                            new JsonSchema(__FILE__, ['type' => 'string']),
                        ),
                    );
                }
            });
        };

        $className = $this->generateClassFromFileTemplate(
            'BranchWithObjectLevelKeyword.json',
            [', "maxProperties": 1'],
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
            false,
        );

        $expectedException = $this->catchException(static fn() => new $className(['kind' => 'B', 'added' => 'a']));
        $this->assertNotNull($expectedException, 'The constructor must reject the data');

        $object = new $className(['kind' => 'B']);
        $actualException = $this->catchException(static fn() => $object->setAdded('a'));

        $this->assertNotNull($actualException, 'The setter must be rejected');
        $this->assertSame($expectedException::class, $actualException::class);
        $this->assertSame($expectedException->getMessage(), $actualException->getMessage());
        $this->assertSame(['kind' => 'B'], $object->meta()->rawInput());
    }

    /**
     * A branch which only constrains the properties it declares keeps the cached result: the setter of a property no
     * branch declares doesn't execute the composition validation, and the cached result of a branch is used as long as
     * none of its properties is updated.
     */
    public function testBranchWithDeclaredPropertiesOnlyKeepsCachedValidation(): void
    {
        $this->addUpdatePostProcessors();

        $className = $this->generateClassFromFile(
            'BranchWithDeclaredPropertiesOnly.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['kind' => 'B']);
        $object->setNote('n');
        $object->populate(['note' => 'm']);
        $this->assertSame(['kind' => 'B', 'note' => 'm'], $object->meta()->rawInput());

        $source = file_get_contents((new ReflectionClass($className))->getFileName());

        $this->assertMatchesRegularExpression('/function setNote\(.*?\n    }\n/s', $source);
        preg_match('/function setNote\(.*?\n    }\n/s', $source, $setNote);
        $this->assertStringNotContainsString('_validateComposition_', $setNote[0]);

        preg_match('/function setKind\(.*?\n    }\n/s', $source, $setKind);
        $this->assertStringContainsString('_validateComposition_', $setKind[0]);

        // both branches keep the check for a cached result
        $this->assertSame(2, substr_count($source, 'array_intersect('));
    }

    /**
     * Each update of a model with kind 'B' must be rejected exactly like the constructor rejects the resulting data.
     */
    private function assertUpdatesAreRejectedLikeTheConstructor(
        string $className,
        bool $additionalPropertyViolatesBranch,
    ): void {
        $updates = [
            'setter' => [static fn(object $object) => $object->setNote('n'), ['kind' => 'B', 'note' => 'n']],
            'populate' => [
                static fn(object $object) => $object->populate(['note' => 'n']),
                ['kind' => 'B', 'note' => 'n'],
            ],
        ];

        if ($additionalPropertyViolatesBranch) {
            $updates['additional property'] = [
                static fn(object $object) => $object->additionalProperties()->set('extra', 'e'),
                ['kind' => 'B', 'extra' => 'e'],
            ];
        }

        foreach ($updates as $update => [$executeUpdate, $resultingData]) {
            $expectedException = $this->catchException(static fn() => new $className($resultingData));
            $this->assertNotNull($expectedException, "The constructor must reject the data of the $update");

            $object = new $className(['kind' => 'B']);
            $actualException = $this->catchException(static fn() => $executeUpdate($object));

            $this->assertNotNull($actualException, "The $update must be rejected");
            $this->assertSame($expectedException::class, $actualException::class, $update);
            $this->assertSame($expectedException->getMessage(), $actualException->getMessage(), $update);

            // the rejected update didn't change the model
            $this->assertSame(['kind' => 'B'], $object->meta()->rawInput(), $update);
            $this->assertNull($object->getNote(), $update);
            $this->assertSame([], $object->additionalProperties()->getAll(), $update);
        }
    }

    private function addUpdatePostProcessors(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator
                ->addPostProcessor(new PopulatePostProcessor())
                ->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor(true));
        };
    }
}
