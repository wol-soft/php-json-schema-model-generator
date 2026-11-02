<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A composition branch which leads back to the schema it composes without descending into the value
 * (via $ref and the branches of allOf, anyOf, oneOf, not and if/then/else) requires the schema for the
 * very same value again: the validation could never terminate. Such a schema is rejected when the model
 * is generated. A reference which descends into the value (items, properties, contains, ...) validates a
 * smaller value and stays valid.
 */
#[ApplicableDrafts]
class CompositionCycleTest extends AbstractPHPModelGeneratorTestCase
{
    protected const EXTERNAL_JSON_DIRECTORIES = ['../CompositionCycleTest_external'];

    /**
     * The references of a definition restate a failure inside the definition, the cause stays available as the
     * previous exception: every message but the innermost one is the restating "Unresolved Reference" message.
     */
    #[DataProvider('cycleProvider')]
    public function testCompositionWhichLeadsBackToItsOwnSchemaIsRejected(
        string $schemaFile,
        string $propertyNamePattern,
        string $cyclePattern,
    ): void {
        try {
            $this->generateClassFromFile($schemaFile);
        } catch (SchemaException $exception) {
            $restatingMessages = [];
            $cause = $exception;

            while ($cause->getPrevious() !== null) {
                $restatingMessages[] = $cause->getMessage();
                $cause = $cause->getPrevious();
            }

            foreach ($restatingMessages as $restatingMessage) {
                $this->assertMatchesRegularExpression(
                    '/^Unresolved Reference .+ in file .+\.json$/',
                    $restatingMessage,
                );
            }

            $this->assertMatchesRegularExpression(
                "/^Composition of property '$propertyNamePattern' in file .+\.json references itself without "
                    . "descending into the value: $cyclePattern at line \d+, column \d+$/",
                $cause->getMessage(),
            );

            return;
        }

        $this->fail('Expected a SchemaException for a composition which leads back to its own schema');
    }

    public static function cycleProvider(): array
    {
        $node = preg_quote("'#/definitions/node'", '/');

        return [
            'array allOf' => ['ArrayAllOfReferencingItself.json', 'value', "$node -> $node"],
            'array allOf with unevaluatedItems' => [
                'ArrayAllOfReferencingItselfWithUnevaluatedItems.json',
                'tags',
                preg_quote("'#/definitions/recursive' -> '#/definitions/recursive'", '/'),
            ],
            'array anyOf' => ['ArrayAnyOfReferencingItself.json', 'value', "$node -> $node"],
            'array oneOf' => ['ArrayOneOfReferencingItself.json', 'value', "$node -> $node"],
            'array not' => ['ArrayNotReferencingItself.json', 'value', "$node -> $node"],
            'array if condition' => ['ArrayIfConditionReferencingItself.json', 'value', "$node -> $node"],
            'array then branch' => ['ArrayThenBranchReferencingItself.json', 'value', "$node -> $node"],
            'array else branch' => ['ArrayElseBranchReferencingItself.json', 'value', "$node -> $node"],
            'string allOf' => ['StringAllOfReferencingItself.json', 'value', "$node -> $node"],
            'untyped allOf' => ['UntypedAllOfReferencingItself.json', 'value', "$node -> $node"],
            'object allOf (class of its own)' => [
                'ObjectAllOfReferencingItself.json',
                '[0-9a-zA-Z_]+',
                "$node -> $node",
            ],
            'composition nested in a composition' => [
                'NestedCompositionReferencingTheOwningSchema.json',
                'value',
                "$node -> $node",
            ],
            'allOf through an alias definition' => [
                'AllOfThroughAliasReferencingItself.json',
                'value',
                "$node -> " . preg_quote("'#/definitions/alias'", '/') . " -> $node",
            ],
            'two definitions requiring each other' => [
                'MutuallyReferencingAllOf.json',
                'value',
                preg_quote("'#/definitions/first' -> '#/definitions/second' -> '#/definitions/first'", '/'),
            ],
            'three definitions requiring each other through different compositions' => [
                'ThreeSchemasReferencingEachOtherThroughDifferentCompositions.json',
                'value',
                preg_quote(
                    "'#/definitions/first' -> '#/definitions/second' -> '#/definitions/third' -> '#/definitions/first'",
                    '/',
                ),
            ],
            'property schema and definition requiring each other' => [
                'PropertyAndDefinitionReferencingEachOther.json',
                'value',
                preg_quote("'#/properties/value' -> '#/definitions/node' -> '#/properties/value'", '/'),
            ],
            'branch referencing the document root' => [
                'AllOfBranchReferencingTheDocumentRoot.json',
                '[0-9a-zA-Z_]+',
                preg_quote("'#' -> '#/definitions/node' -> '#'", '/'),
            ],
            'external schema referencing itself through its root' => [
                'AllOfReferencingAnExternalSchemaWhichReferencesItself.json',
                'value',
                preg_quote("'#' -> '#/definitions/other' -> '#'", '/'),
            ],
            'external schemas requiring each other' => [
                'AllOfReferencingExternalSchemasWhichReferenceEachOther.json',
                'value',
                preg_quote("'#' -> '", '/') . '.+Second\.json#' . preg_quote("' -> '#'", '/'),
            ],
        ];
    }

    /**
     * References which descend into the value (the items of an array, the properties of an object, the contained
     * item) validate a smaller value in each step, a schema which is shared by several compositions without leading
     * back to itself is no cycle. Each schema is generated and a value which uses the recursion is validated.
     */
    #[DataProvider('acceptedRecursionProvider')]
    public function testReferenceWhichDoesNotLeadBackWithoutDescendingIntoTheValueIsAccepted(
        string $schemaFile,
        array $input,
    ): void {
        $className = $this->generateClassFromFile($schemaFile);

        $object = new $className($input);

        $this->assertSame($input, $object->meta()->rawInput());
    }

    public static function acceptedRecursionProvider(): array
    {
        return [
            'items referencing the owning schema' => [
                'ItemsReferencingTheOwningSchema.json',
                ['value' => [[], [[]]]],
            ],
            'anyOf branch with items referencing the owning schema' => [
                'AnyOfBranchWithItemsReferencingTheOwningSchema.json',
                ['value' => [[], [[]]]],
            ],
            'items composed of the owning schema' => [
                'ItemsComposedOfTheOwningSchema.json',
                ['value' => ['a', ['b', ['c']]]],
            ],
            'property composed of the owning schema' => [
                'PropertyComposedOfTheOwningSchema.json',
                ['value' => ['child' => ['child' => []]]],
            ],
            'contains composed of the owning schema' => [
                'ContainsComposedOfTheOwningSchema.json',
                ['value' => ['a']],
            ],
            'allOf branches referencing the same schema' => [
                'AllOfBranchesReferencingTheSameSchema.json',
                ['value' => 'x'],
            ],
            'compositions sharing a leaf schema' => [
                'CompositionsSharingALeafSchema.json',
                ['value' => 'x'],
            ],
        ];
    }
}
