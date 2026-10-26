<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\RecordingLogger;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A composition branch which declares no `type` inherits the type of the enclosing schema (the root of a schema
 * always counts as an object). A branch whose `enum`/`const` lists no value of that type can never be satisfied. For
 * an `allOf`, where every branch has to hold, the whole composition is unsatisfiable and rejected during the
 * generation. For the other compositions the branch is merely dead and reported with a warning.
 */
#[ApplicableDrafts]
class InheritedTypeConflictTest extends AbstractPHPModelGeneratorTestCase
{
    private const string WARNING_MESSAGE = "Composition branch {branch} for '{property}' is forced to type {type} by"
        . " the inherited parent type, but its 'enum'/'const' contains no value of that type - the branch can never"
        . ' be satisfied';

    /**
     * The root of the schema is an object and the enum of the second branch lists no object, so no instance can
     * satisfy both branches of the `allOf`: every input is rejected by the generated class.
     *
     * The reported position is the one of the offending branch, not the one of the composition or the schema root.
     */
    public function testRootLevelAllOfBranchWithoutObjectValueIsRejected(): void
    {
        $schemaFile = 'RootLevelAllOfObjectTypeInjectionUnsatisfiableEnum.json';

        // The fixture helpers write the schema re-encoded (plus a title) into one line, so the branch is found in
        // the re-encoded schema.
        $encodedSchema = json_encode(json_decode(file_get_contents($this->getSchemaFilePath($schemaFile)), true));

        try {
            $this->generateClassFromFile($schemaFile);
            $this->fail('Expected the allOf branch which can never hold an object to be rejected');
        } catch (SchemaException $exception) {
            $this->assertMatchesRegularExpression(
                self::allOfRejectionPattern(2, '[A-Za-z0-9_]+', "'object'"),
                $exception->getMessage(),
            );
            $this->assertSame(1, $exception->getSourceLine());
            $this->assertSame(strpos($encodedSchema, '{"enum":[1,2,3]}') + 1, $exception->getSourceColumn());
        }
    }

    public static function unsatisfiableAllOfBranchDataProvider(): array
    {
        return [
            'string type, integer values' => ['string', ['enum' => [1, 2]], 'value', "'string'"],
            'string type, null const' => ['string', ['const' => null], 'value', "'string'"],
            'integer type, fractional const' => ['integer', ['const' => 1.5], 'value', "'integer'"],
            'number type, numeric string values' => ['number', ['enum' => ['1']], 'value', "'number'"],
            'boolean type, string const' => ['boolean', ['const' => 'true'], 'value', "'boolean'"],
            'array type, object value' => ['array', ['enum' => [['name' => 'a']]], 'value', "'array'"],
            'null type, integer const' => ['null', ['const' => 0], 'value', "'null'"],
            'type list without a matching member' => [
                ['string', 'null'],
                ['enum' => [1, 2]],
                'value',
                "['string', 'null']",
            ],
            // The enum alone could be satisfied, the const rules out its only string
            'enum satisfiable, const not' => ['string', ['enum' => ['a', 1], 'const' => 1], 'value', "'string'"],
            // The property of the nested object schema is named after the generated class
            'nested object, scalar const' => ['object', ['const' => 5], '[A-Za-z0-9_]+', "'object'"],
            'nested object, list value' => ['object', ['enum' => [[1, 2]]], '[A-Za-z0-9_]+', "'object'"],
        ];
    }

    /**
     * An `allOf` requires every branch to hold for the same value. The branch inherits the type of the property, so
     * a branch which lists no value of that type contradicts the property itself, whatever the type is.
     */
    #[DataProvider('unsatisfiableAllOfBranchDataProvider')]
    public function testAllOfBranchWhichCannotSatisfyTheInheritedTypeIsRejected(
        string | array $inheritedType,
        array $branch,
        string $propertyPattern,
        string $expectedType,
    ): void {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(self::allOfRejectionPattern(1, $propertyPattern, $expectedType));

        $this->generateClassFromFileTemplate(
            'ListCompositionBranchUnderTypedProperty.json',
            [json_encode($inheritedType), 'allOf', json_encode($branch)],
            escape: false,
        );
    }

    public static function satisfiableAllOfBranchDataProvider(): array
    {
        return [
            'number type, integer value' => ['number', ['enum' => [1]]],
            'number type, float value' => ['number', ['const' => 1.5]],
            'type list with a matching member' => [['string', 'null'], ['enum' => [null]]],
            'array type, list value' => ['array', ['enum' => [[1, 2]]]],
            // json_decode() maps {} and [] to the same empty array, so it may be either of them
            'array type, empty array value' => ['array', ['enum' => [[]]]],
            'boolean type, boolean const' => ['boolean', ['const' => true]],
            'enum and const share a matching value' => ['string', ['enum' => ['a', 1], 'const' => 'a']],
            'one matching value among others' => ['string', ['enum' => [1, 'a', null]]],
        ];
    }

    /**
     * Control for the rejection: a branch which lists a value of the inherited type is satisfiable and must neither
     * be rejected nor reported.
     */
    #[DataProvider('satisfiableAllOfBranchDataProvider')]
    public function testAllOfBranchWhichCanSatisfyTheInheritedTypeIsAccepted(
        string | array $inheritedType,
        array $branch,
    ): void {
        $logger = new RecordingLogger();

        $this->generateClassFromFileTemplate(
            'ListCompositionBranchUnderTypedProperty.json',
            [json_encode($inheritedType), 'allOf', json_encode($branch)],
            (new GeneratorConfiguration())->setLogger($logger),
            escape: false,
        );

        $this->assertSame([], $this->collectInheritedTypeWarnings($logger));
    }

    /**
     * Control for the type matching: JSON Schema treats a number without a fractional part as an integer, so the
     * schema is satisfiable and must not be rejected on the grounds of the PHP type of the decoded float.
     *
     * The schema is generated with its original class names: otherwise the fixture helpers re-encode the schema, and
     * the JSON literal 2.0 would silently become the integer 2.
     */
    public function testAllOfWholeFloatConstUnderIntegerTypeIsAccepted(): void
    {
        $logger = new RecordingLogger();

        $this->generateClassFromFile(
            'AllOfWholeFloatConstUnderIntegerProperty.json',
            (new GeneratorConfiguration())->setLogger($logger),
            originalClassNames: true,
        );

        $this->assertSame([], $this->collectInheritedTypeWarnings($logger));
    }

    public function testObjectValueInTheEnumOfAnAllOfBranchUnderTheRootTypeIsAccepted(): void
    {
        $logger = new RecordingLogger();

        $this->generateClassFromFile(
            'RootLevelAllOfObjectTypeInjectionSatisfiableEnum.json',
            (new GeneratorConfiguration())->setLogger($logger),
        );

        $this->assertSame([], $this->collectInheritedTypeWarnings($logger));
    }

    /**
     * Only a branch which really inherits a type is checked: without a type on the property nothing is injected, so
     * an `enum` of any values stays what it is.
     */
    public function testAllOfBranchOfAPropertyWithoutATypeIsAccepted(): void
    {
        $logger = new RecordingLogger();

        $this->generateClassFromFile(
            'AllOfEnumBranchUnderUntypedProperty.json',
            (new GeneratorConfiguration())->setLogger($logger),
        );

        $this->assertSame([], $this->collectInheritedTypeWarnings($logger));
    }

    /**
     * An empty enum is a defect of its own, which is reported by the enum with a message about the empty enum - the
     * inherited type has nothing to do with it.
     */
    public function testEmptyEnumOfAnAllOfBranchKeepsItsOwnDiagnostic(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/^Empty enum property value in file .+\.json at line \d+, column \d+$/');

        $this->generateClassFromFileTemplate(
            'ListCompositionBranchUnderTypedProperty.json',
            [json_encode('string'), 'allOf', json_encode(['enum' => []])],
            escape: false,
        );
    }

    public static function deadObjectBranchDataProvider(): array
    {
        return [
            'anyOf' => ['RootLevelAnyOfObjectTypeInjectionUnsatisfiableEnum.json', ['#2']],
            'oneOf' => ['RootLevelOneOfObjectTypeInjectionUnsatisfiableConst.json', ['#2']],
            'not' => ['RootLevelNotObjectTypeInjectionUnsatisfiableEnum.json', ["'not'"]],
            'if, then and else' => [
                'RootLevelIfObjectTypeInjectionUnsatisfiableEnum.json',
                ["'if'", "'then'", "'else'"],
            ],
        ];
    }

    /**
     * Only the `allOf` is unsatisfiable as a whole. A dead branch of any other composition keeps the schema
     * satisfiable (another branch of the `anyOf`/`oneOf` matches, a `not` of a dead branch holds for every instance,
     * an `if` which never matches selects the `else` branch), so the generation proceeds and warns about the branch.
     *
     * @param string[] $expectedBranchLabels
     */
    #[DataProvider('deadObjectBranchDataProvider')]
    public function testDeadBranchOfTheRootObjectIsWarnedAbout(string $schemaFile, array $expectedBranchLabels): void
    {
        $logger = new RecordingLogger();

        $className = $this->generateClassFromFile($schemaFile, (new GeneratorConfiguration())->setLogger($logger));

        $this->assertSame(
            array_map(
                static fn(string $branchLabel): array => [
                    'branch' => $branchLabel,
                    'property' => $className,
                    'type' => "'object'",
                ],
                $expectedBranchLabels,
            ),
            $this->collectInheritedTypeWarnings($logger),
        );
    }

    public static function deadBranchOfTypedPropertyDataProvider(): array
    {
        return [
            'anyOf, single type' => ['string', 'anyOf', "'string'"],
            'oneOf, type list' => [['string', 'null'], 'oneOf', "['string', 'null']"],
        ];
    }

    #[DataProvider('deadBranchOfTypedPropertyDataProvider')]
    public function testDeadBranchOfATypedPropertyIsWarnedAbout(
        string | array $inheritedType,
        string $compositionKeyword,
        string $expectedType,
    ): void {
        $logger = new RecordingLogger();

        $this->generateClassFromFileTemplate(
            'ListCompositionBranchUnderTypedProperty.json',
            [json_encode($inheritedType), $compositionKeyword, json_encode(['enum' => [1, 2]])],
            (new GeneratorConfiguration())->setLogger($logger),
            escape: false,
        );

        $this->assertSame(
            [['branch' => '#1', 'property' => 'value', 'type' => $expectedType]],
            $this->collectInheritedTypeWarnings($logger),
        );
    }

    private static function allOfRejectionPattern(int $branchNumber, string $propertyPattern, string $type): string
    {
        return '/^'
            . preg_quote("Composition branch #$branchNumber for '", '/')
            . $propertyPattern
            . preg_quote("' in file '", '/')
            . ".+\.json'"
            . preg_quote(
                " is forced to type $type by the inherited parent type, but its 'enum'/'const' contains no value"
                    . ' of that type: the allOf composition can never be satisfied',
                '/',
            )
            . ' at line \d+, column \d+$/';
    }

    /**
     * @return array<int, array<string, mixed>> The context of every warning about a branch which can't satisfy the
     *                                          type it inherits, in the order the warnings were emitted.
     */
    private function collectInheritedTypeWarnings(RecordingLogger $logger): array
    {
        return array_values(array_map(
            static fn(array $entry): array => $entry['context'],
            array_filter(
                $logger->getEntries(),
                static fn(array $entry): bool => $entry['level'] === 'warning'
                    && $entry['message'] === self::WARNING_MESSAGE,
            ),
        ));
    }
}
