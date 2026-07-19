<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ComposedValue\AllOfException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #167: a property whose allOf branches are themselves scalar compositions
 * (anyOf/oneOf of scalar types) validated nothing. The nested composition validators were stripped
 * on the assumption that an object class re-validates the composition on instantiation, but a
 * scalar composition branch has no such class, so every invalid value slipped through silently.
 */
class Issue167Test extends AbstractIssueTestCase
{
    public function testValidValueSatisfyingEveryScalarCompositionIsAccepted(): void
    {
        $className = $this->generateClassFromFile('ScalarNestedComposition.json');

        // 7 satisfies the anyOf (integer) and the oneOf (integer only, not boolean).
        $object = new $className(['p' => 7]);

        $this->assertSame(7, $object->getP());
    }

    #[DataProvider('invalidValueDataProvider')]
    public function testValueViolatingAScalarNestedCompositionIsRejected(
        int|string|bool $value,
        string $expectedMessage,
    ): void {
        $this->expectException(AllOfException::class);
        $this->expectExceptionMessage($expectedMessage);

        $className = $this->generateClassFromFile('ScalarNestedComposition.json');

        new $className(['p' => $value]);
    }

    public static function invalidValueDataProvider(): array
    {
        return [
            // Satisfies the oneOf (boolean) but not the anyOf (neither string nor integer).
            'boolean' => [
                true,
                <<<ERROR
                Invalid value for p declined by composition constraint.
                  Requires to match all composition elements but matched 1 elements.
                  - Composition element #1: Failed
                    * Invalid value for p declined by composition constraint.
                  Requires to match at least one composition element.
                  - Composition element #1: Failed
                    * Invalid type for p. Requires string, got boolean
                  - Composition element #2: Failed
                    * Invalid type for p. Requires int, got boolean
                  - Composition element #2: Valid
                ERROR,
            ],
            // Satisfies neither composition: too short for the string branch and not an integer.
            'string shorter than the minimum' => [
                'ab',
                <<<ERROR
                Invalid value for p declined by composition constraint.
                  Requires to match all composition elements but matched 0 elements.
                  - Composition element #1: Failed
                    * Invalid value for p declined by composition constraint.
                  Requires to match at least one composition element.
                  - Composition element #1: Failed
                    * Value for p must not be shorter than 5
                  - Composition element #2: Failed
                    * Invalid type for p. Requires int, got string
                  - Composition element #2: Failed
                    * Invalid value for p declined by composition constraint.
                  Requires to match one composition element but matched 0 elements.
                  - Composition element #1: Failed
                    * Invalid type for p. Requires int, got string
                  - Composition element #2: Failed
                    * Invalid type for p. Requires bool, got string
                ERROR,
            ],
            // Satisfies the anyOf (long enough string) but not the oneOf (neither integer nor boolean).
            'string satisfying only the anyOf' => [
                'abcdef',
                <<<ERROR
                Invalid value for p declined by composition constraint.
                  Requires to match all composition elements but matched 1 elements.
                  - Composition element #1: Valid
                  - Composition element #2: Failed
                    * Invalid value for p declined by composition constraint.
                  Requires to match one composition element but matched 0 elements.
                  - Composition element #1: Failed
                    * Invalid type for p. Requires int, got string
                  - Composition element #2: Failed
                    * Invalid type for p. Requires bool, got string
                ERROR,
            ],
        ];
    }
}
