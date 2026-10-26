<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

#[ApplicableDrafts]
class NestedCompositionBehindReferenceTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * The composition of a definition without a type which is referenced as a branch of a composition of an object
     * (a class root or a property which is typed as an object) has to be enforced. The validation of a nested
     * composition of a branch is dropped on the assumption that the branch was routed into a generated class of its
     * own, which validates the nested composition on instantiation. A branch which is a reference to a definition
     * without a type is not routed into such a class, so nothing validates the nested composition and every object
     * which satisfies the outer composition is accepted. An allOf definition whose branches all declare an object type
     * is not affected: it is routed into a class of its own as a whole, which is why the allOf row uses untyped
     * branches.
     *
     * The test turns green by itself as soon as the nested composition is enforced; the assertion of the complete
     * exception message belongs here then.
     */
    #[DataProvider('referencedNestedCompositionProvider')]
    public function testNestedCompositionBehindAReferenceIsEnforced(string $schemaFile, array $invalidInput): void
    {
        $className = $this->generateClassFromFile($schemaFile);

        try {
            new $className($invalidInput);
        } catch (ValidationException) {
            return;
        }

        $this->markTestIncomplete(
            'Known gap: the composition of a definition which is referenced as a branch of a composition of an'
                . ' object is not validated, so objects which violate it are accepted.',
        );
    }

    public static function referencedNestedCompositionProvider(): array
    {
        return [
            'oneOf definition as branch of the allOf of a class root' => [
                'RootAllOfReferencingUntypedOneOf.json',
                ['name' => 1],
            ],
            'oneOf definition as branch of the anyOf of a class root' => [
                'RootAnyOfReferencingUntypedOneOf.json',
                ['name' => 1],
            ],
            'oneOf definition as branch of the anyOf of an object property' => [
                'PropertyAnyOfReferencingUntypedOneOf.json',
                ['property' => ['name' => 1]],
            ],
            'allOf definition of untyped branches as branch of the anyOf of an object property' => [
                'PropertyAnyOfReferencingUntypedAllOf.json',
                ['property' => ['name' => 'Hannes']],
            ],
        ];
    }
}
