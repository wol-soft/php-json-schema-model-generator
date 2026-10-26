<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use ReflectionClass;

/**
 * A schema which carries object-constraining keywords without a `type` is object-describing: it is accepted and the
 * generator warns, as the missing type is easy to forget and lets every non-object value pass. The warning names the
 * location of the offending schema. It is not emitted where an enclosing schema already asserts an object (the branches
 * of an object-typed owner, the schema of a dependency, the target of a class-defining reference): nothing is implied
 * there, which holds for a referenced definition exactly as it does for an inline schema.
 */
#[ApplicableDrafts]
class ObjectDescribingSchemaWarningTest extends AbstractPHPModelGeneratorTestCase
{
    private const DESCRIBING_WARNING = "Property '{property}' at '{pointer}' in file '{file}' carries"
        . " object-constraining keywords (eg. 'properties', 'required') without a 'type' declaration and does not"
        . ' constrain non-object values';

    /**
     * Every site which creates a property for a subschema reports the schema it found, at its own location. The two
     * branches of the composition are told apart by their pointers.
     */
    public function testEveryValueSiteWarnsOnceAtItsOwnLocation(): void
    {
        [$className, $warnings] = $this->generateRecordingDescribingWarnings('ValueSites.json');

        $this->assertSame(
            [
                'additional property @ /additionalProperties',
                'choice @ /properties/choice/oneOf/0',
                'choice @ /properties/choice/oneOf/1',
                'containing @ /properties/containing/contains',
                'list @ /properties/list/items',
                'pattern property @ /patternProperties/^x',
                'property @ /properties/property',
            ],
            $this->describeLocations($warnings),
        );
        $this->assertWarningsNameTheSchemaFile($warnings, $className);

        // The schemas are accepted as described: a non-object passes, an object is validated.
        new $className(['property' => 42, 'list' => [42]]);
        new $className(['property' => ['name' => 'Hannes']]);

        try {
            new $className(['property' => []]);
            $this->fail('Expected a NestedObjectException for the object without a name');
        } catch (NestedObjectException $exception) {
            $this->assertSame(
                <<<'ERROR'
                Invalid nested object for property 'property':
                  - Missing required value for 'name'
                ERROR,
                $exception->getMessage(),
            );
        }
    }

    /**
     * The branches of an object-typed owner validate the value which the owner already asserts to be an object. An
     * inline bare branch is silent for that reason, so a reference to the same bare schema has to be silent as well:
     * directly, behind another reference, and when the definition is an untyped composition of bare branches. The
     * assertion is made for the drafts which apply the siblings of a reference and for the draft which ignores them
     * (where the type the generator hands to a branch is lost next to the reference).
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testReferencedBareDefinitionInAnAssertedContextIsSilent(): void
    {
        $this->assertReferencedBareDefinitionsInAssertedContextsAreSilent();
    }

    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testReferencedBareDefinitionInAnAssertedContextIsSilentWhereTheReferenceIgnoresSiblings(): void
    {
        $this->assertReferencedBareDefinitionsInAssertedContextsAreSilent();
    }

    private function assertReferencedBareDefinitionsInAssertedContextsAreSilent(): void
    {
        // property level: allOf / anyOf / not / if-then-else under an object-typed property
        // class root: a branch of the root composition and the schema of a dependency
        [$className, $warnings] = $this->generateRecordingDescribingWarnings('AssertedContexts.json');

        $this->assertSame([], $this->describeLocations($warnings));

        // The generated code is unchanged: the allOf reaches the bare schema through a chain of references and still
        // validates it. (The anyOf property is not used for the rejection: the composition behind its third branch is
        // a known gap, tracked by NestedCompositionBehindReferenceTest.)
        new $className(['name' => 'Hannes', 'trigger' => 'value', 'allOfProperty' => ['name' => 'Hannes']]);
        $this->assertRejected($className, ['name' => 'Hannes', 'allOfProperty' => ['name' => 1]]);

        // The branch of the root composition validates as well: the input is valid below the root, so the missing name
        // of the bare schema is rejected by the root alone.
        $this->assertRejected($className, ['allOfProperty' => ['name' => 'Hannes']]);

        // a class-defining reference
        [$className, $warnings] = $this->generateRecordingDescribingWarnings('ClassRootReference.json');

        $this->assertSame([], $this->describeLocations($warnings));

        new $className(['name' => 'Hannes']);
        $this->assertRejected($className, []);
    }

    /**
     * The context belongs to the reference site, not to the definition: a definition which is referenced by the
     * schema of a dependency (asserted) and by a plain property warns for the plain use only, whichever use comes
     * first. A dependency is evaluated while the properties are processed, so both orders can be arranged in one
     * schema (the properties of one schema share the properties which are created for a definition).
     *
     * The assertion covers the value the branch validates and nothing below it: a property of a referenced
     * definition is a value site of its own and is judged as such, also when the definition is used in an asserted
     * context.
     */
    public function testWarningsFollowTheContextOfEachUseOfADefinition(): void
    {
        [, $warnings] = $this->generateRecordingDescribingWarnings('MixedUse.json');

        $this->assertSame(
            [
                // a value site below the branch of an object-typed property
                'inner @ /definitions/holder/properties/inner',
                // the dependency comes first, the plain property follows
                'lateUse @ /definitions/assertedFirst',
                // the plain property comes first, the dependency follows
                'plainUse @ /definitions/plainFirst',
            ],
            $this->describeLocations($warnings),
        );
    }

    /**
     * Where the siblings of a reference apply, an object type next to the reference asserts the object for the
     * referenced definition.
     */
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testObjectTypeNextToAReferenceAssertsTheReferencedDefinition(): void
    {
        [, $warnings] = $this->generateRecordingDescribingWarnings('RefWithObjectTypeSibling.json');

        $this->assertSame([], $this->describeLocations($warnings));
    }

    /**
     * A draft which ignores the siblings of a reference ignores a type next to it as well, so nothing asserts the
     * object and the referenced definition is judged on its own. Counting the ignored type would hide the warning
     * for a schema which really lets every non-object value pass.
     */
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testObjectTypeNextToAReferenceIsIgnoredWhereTheReferenceIgnoresSiblings(): void
    {
        [, $warnings] = $this->generateRecordingDescribingWarnings('RefWithObjectTypeSibling.json');

        $this->assertSame(['property @ /definitions/person'], $this->describeLocations($warnings));
    }

    /**
     * @return array{0: string, 1: array<int, array<string, mixed>>} the generated class and the contexts of the
     *                                                                warnings about an object-describing schema
     */
    private function generateRecordingDescribingWarnings(string $file): array
    {
        $recordingLogger = new RecordingLogger();

        $className = $this->generateClassFromFile(
            $file,
            (new GeneratorConfiguration())->setCollectErrors(false)->setLogger($recordingLogger),
        );

        $warnings = [];

        foreach ($recordingLogger->getEntries() as $entry) {
            if ($entry['level'] === 'warning' && $entry['message'] === self::DESCRIBING_WARNING) {
                $warnings[] = $entry['context'];
            }
        }

        return [$className, $warnings];
    }

    /**
     * @param array<int, array<string, mixed>> $warnings
     *
     * @return string[]
     */
    private function describeLocations(array $warnings): array
    {
        $locations = array_map(
            static fn(array $context): string => $context['property'] . ' @ ' . $context['pointer'],
            $warnings,
        );

        sort($locations);

        return $locations;
    }

    /**
     * @param array<int, array<string, mixed>> $warnings
     */
    private function assertWarningsNameTheSchemaFile(array $warnings, string $className): void
    {
        $schemaFile = (new ReflectionClass($className))->getShortName() . '.json';

        foreach ($warnings as $context) {
            $this->assertStringEndsWith(DIRECTORY_SEPARATOR . $schemaFile, $context['file']);
        }
    }

    private function assertRejected(string $className, array $input): void
    {
        try {
            new $className($input);
            $this->fail('Expected the input to be rejected: ' . json_encode($input));
        } catch (ValidationException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
