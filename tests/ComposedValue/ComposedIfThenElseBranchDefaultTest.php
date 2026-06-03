<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use Exception;
use PHPModelGenerator\Exception\ComposedValue\ConditionalException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Verifies that if/then/else branch-level defaults are applied from the matched branch only
 * (then when the if condition holds, else when it does not), that the non-matching branch's
 * default is absent, that user-supplied values take precedence, and that differing defaults
 * across then and else are allowed because the branches are mutually exclusive.
 */
class ComposedIfThenElseBranchDefaultTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * When the if condition matches, the then branch default applies and the else branch default
     * is absent. When the if condition does not match, the else branch default applies and the
     * then branch default is absent. User-supplied values always override branch defaults.
     *
     * Schema: kind=A triggers then (value defaults to 'default-a'); kind=B triggers else
     * (timeout defaults to 60). The two branches declare defaults on different properties so
     * only the matched branch's property gets a default.
     */
    public function testThenAndElseBranchDefaultsApplyForMatchingBranchOnly(): void
    {
        $className = $this->generateClassFromFile(
            'IfThenElseBranchDefault.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // kind=A → if condition matches → then branch → value='default-a', timeout=null.
        $branchThen = new $className(['kind' => 'A']);
        $this->assertSame('default-a', $branchThen->getValue());
        $this->assertNull($branchThen->getTimeout());
        // value came from the then-branch default; absent from raw input.
        $this->assertSame(['kind' => 'A'], $branchThen->getRawModelDataInput());

        // kind=B → if condition does not match → else branch → timeout=60, value=null.
        $branchElse = new $className(['kind' => 'B']);
        $this->assertNull($branchElse->getValue());
        $this->assertSame(60, $branchElse->getTimeout());
        // timeout came from the else-branch default; absent from raw input.
        $this->assertSame(['kind' => 'B'], $branchElse->getRawModelDataInput());

        // User-supplied value overrides the then-branch default.
        $thenUserOverride = new $className(['kind' => 'A', 'value' => 'custom']);
        $this->assertSame('custom', $thenUserOverride->getValue());
        $this->assertSame(['kind' => 'A', 'value' => 'custom'], $thenUserOverride->getRawModelDataInput());

        // User-supplied value overrides the else-branch default.
        $elseUserOverride = new $className(['kind' => 'B', 'timeout' => 5]);
        $this->assertSame(5, $elseUserOverride->getTimeout());
        $this->assertSame(['kind' => 'B', 'timeout' => 5], $elseUserOverride->getRawModelDataInput());
    }

    /**
     * then and else are mutually exclusive at runtime, so differing defaults for the same
     * property are allowed — the matched branch wins. No SchemaException must be thrown at
     * generation time; the correct per-branch default must apply at runtime.
     *
     * Schema: both then and else declare timeout, with defaults 10 and 60 respectively.
     */
    public function testDifferingThenElseDefaultsForSamePropertyAreAllowed(): void
    {
        $className = $this->generateClassFromFile(
            'IfThenElseBranchDefaultNoConflict.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // if condition matches (kind=A) → then branch → timeout=10.
        $branchThen = new $className(['kind' => 'A']);
        $this->assertSame(10, $branchThen->getTimeout());
        // timeout came from the then-branch default; absent from raw input.
        $this->assertSame(['kind' => 'A'], $branchThen->getRawModelDataInput());

        // if condition does not match (kind=B) → else branch → timeout=60.
        $branchElse = new $className(['kind' => 'B']);
        $this->assertSame(60, $branchElse->getTimeout());
        // timeout came from the else-branch default; absent from raw input.
        $this->assertSame(['kind' => 'B'], $branchElse->getRawModelDataInput());
    }

    /**
     * When only an if + else are present (no then), the conflict error must identify the branch
     * as 'else', not 'then'. The conditionBranches array is re-indexed after filtering out the
     * absent then-branch, so the else branch lands at index 0; using the index to choose the
     * label would produce 'then' instead of 'else'.
     */
    public function testIfElseOnlyConflictingDefaultLabelsElseBranch(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "#Conflicting default values for property 'value' under if/then/else composition"
                . " in file .+: root='root-default', else='else-default'\\.#",
        );

        $this->generateClassFromFile('IfElseOnlyRootDefaultConflict.json', new GeneratorConfiguration());
    }

    /**
     * When the if-condition matches but the then-branch content validation fails (due to an
     * unrelated field violating a constraint), branch-default properties that were correctly
     * applied during a previous successful validation must not be reset to null.
     *
     * Reproduces the bug where the allBranchDefaultAttributeMap foreach runs unconditionally
     * after a failed then-branch, zeroing out the branch-default property even though the
     * if-condition still matched.
     */
    public function testBranchDefaultPreservedWhenIfMatchesButThenContentValidationFails(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'IfThenBranchDefaultPreservedOnFailedValidation.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // Construct with a valid premium object: if matches, then validates — discount gets default true.
        $object = new $className(['kind' => 'premium', 'score' => 80]);
        $this->assertTrue($object->getDiscount());

        // Re-validate with a score that violates the then-branch minimum.
        // The if-condition still matches (kind='premium'), but then-validation fails.
        // The branch-default property (discount) must remain true, not be reset to null.
        $caughtException = null;
        try {
            $object->populate(['kind' => 'premium', 'score' => 10]);
        } catch (Exception $validationException) {
            $caughtException = $validationException;
        }

        $this->assertInstanceOf(ConditionalException::class, $caughtException);
        $this->assertMatchesRegularExpression(
            <<<'PATTERN'
            /^Invalid value for \S+ declined by conditional composition constraint
              - Condition: Valid
              - Conditional branch failed:
                \* Value for score must not be smaller than 50$/
            PATTERN,
            $caughtException->getMessage(),
        );

        $this->assertTrue(
            $object->getDiscount(),
            'Branch default must not be reset to null when the if-condition matched but then-validation'
            . ' failed for an unrelated field.',
        );
    }
}
