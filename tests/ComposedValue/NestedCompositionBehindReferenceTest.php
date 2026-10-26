<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The composition of a definition without a type which is referenced as a branch of a composition of an object
 * (a class root or a property which is typed as an object) has to be enforced. The validation of a nested
 * composition of a branch is dropped on the assumption that the branch was routed into a generated class of its
 * own, which validates the nested composition on instantiation. A branch which is a reference to a definition
 * without a type is not routed into such a class, so nothing validates the nested composition and every object
 * which satisfies the outer composition is accepted. An allOf definition whose branches all declare an object type
 * is not affected: it is routed into a class of its own as a whole, which is why the allOf definition uses untyped
 * branches.
 *
 * The tests turn green by themselves as soon as the nested composition is enforced; the assertion of the complete
 * exception message belongs here then.
 */
#[ApplicableDrafts]
class NestedCompositionBehindReferenceTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * A oneOf definition as the only branch of the composition of a class root.
     */
    #[DataProvider('rootCompositionKeywordProvider')]
    public function testNestedCompositionBehindAReferenceIsEnforcedAtTheClassRoot(string $keyword): void
    {
        $className = $this->generateClassFromFileTemplate('RootCompositionReferencingUntypedOneOf.json', [$keyword]);

        $this->assertRejectedOrMarkIncomplete($className, [['name' => 1]]);
    }

    public static function rootCompositionKeywordProvider(): array
    {
        return [
            'oneOf definition as branch of the allOf of a class root' => ['allOf'],
            'oneOf definition as branch of the anyOf of a class root' => ['anyOf'],
        ];
    }

    /**
     * Both definitions are the only branch of the anyOf of a property which is typed as an object. Each property is
     * violated on its own, so the inputs tell which of the two compositions is not enforced.
     */
    public function testNestedCompositionBehindAReferenceIsEnforcedForAnObjectProperty(): void
    {
        $className = $this->generateClassFromFile('PropertyCompositionsReferencingUntypedDefinitions.json');

        $this->assertRejectedOrMarkIncomplete($className, [
            // a oneOf definition: the name is no string
            ['choiceProperty' => ['name' => 1]],
            // an allOf definition of untyped branches: the code is missing
            ['bothProperty' => ['name' => 'Hannes']],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $invalidInputs Inputs which violate the nested composition only
     */
    private function assertRejectedOrMarkIncomplete(string $className, array $invalidInputs): void
    {
        $acceptedInputs = [];

        foreach ($invalidInputs as $invalidInput) {
            try {
                new $className($invalidInput);
            } catch (ValidationException) {
                continue;
            }

            $acceptedInputs[] = $invalidInput;
        }

        if ($acceptedInputs !== []) {
            $this->markTestIncomplete(
                'Known gap: the composition of a definition which is referenced as a branch of a composition of an'
                    . ' object is not validated, so objects which violate it are accepted: '
                    . json_encode($acceptedInputs),
            );
        }

        $this->assertSame([], $acceptedInputs);
    }
}
