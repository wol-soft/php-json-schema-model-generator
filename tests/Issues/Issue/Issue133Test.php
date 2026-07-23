<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue133Test extends AbstractIssueTestCase
{
    /**
     * oneOf branches define the same property name (metric_id) with conflicting constraints:
     * branch 0 (vendor scope) uses an enum, branch 1 (standard scope) uses minLength/pattern.
     *
     * The outer merged property must not have branch-0's enum filter applied unconditionally —
     * a standard-scope input with a valid string metric_id must be accepted.
     */
    public function testOneOfBranchSpecificEnumNotAppliedToOuterProperty(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };

        $className = $this->generateClassFromFile('oneOfWithMetricIdConflict.json');

        // standard scope: metric_id satisfies minLength/pattern (branch 1), must not be
        // rejected by branch-0's enum validator
        $object = new $className([
            'committed_metrics' => [
                ['scope' => 'standard', 'metric_id' => 'custom_metric'],
            ],
        ]);

        $items = $object->getCommittedMetrics();
        $this->assertCount(1, $items);
        $this->assertSame('standard', $items[0]->getScope());
        $this->assertSame('custom_metric', $items[0]->getMetricId());

        // vendor scope: metric_id must still be a valid enum value (validated inside branch 0)
        $object = new $className([
            'committed_metrics' => [
                ['scope' => 'vendor', 'metric_id' => 'spend'],
            ],
        ]);

        $items = $object->getCommittedMetrics();
        $this->assertCount(1, $items);
        $this->assertSame('vendor', $items[0]->getScope());
    }

    /**
     * Same bug as the original but with enum on branch 1 (reversed order) to confirm the fix
     * is not branch-index-dependent.
     */
    public function testOneOfBranchSpecificEnumOnBranch1NotAppliedToOuterProperty(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };

        $className = $this->generateClassFromFile('oneOfEnumOnBranch1.json');

        // standard scope (branch 0): metric_id is a free-form string — must not be filtered by
        // branch-1's vendor enum
        $object = new $className([
            'committed_metrics' => [
                ['scope' => 'standard', 'metric_id' => 'custom_metric'],
            ],
        ]);

        $items = $object->getCommittedMetrics();
        $this->assertCount(1, $items);
        $this->assertSame('standard', $items[0]->getScope());
        $this->assertSame('custom_metric', $items[0]->getMetricId());

        // vendor scope (branch 1): metric_id must still satisfy the enum
        $object = new $className([
            'committed_metrics' => [
                ['scope' => 'vendor', 'metric_id' => 'clicks'],
            ],
        ]);

        $items = $object->getCommittedMetrics();
        $this->assertCount(1, $items);
        $this->assertSame('vendor', $items[0]->getScope());
    }

    /**
     * anyOf equivalent: branch 0 defines an enum on 'value', branch 1 allows any string.
     * A dynamic-kind value must not be filtered by the fixed-kind's enum.
     */
    public function testAnyOfBranchSpecificEnumNotAppliedToOuterProperty(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };

        $className = $this->generateClassFromFile('anyOfWithConflictingConstraints.json');

        // dynamic kind: value is a free-form string — must not be filtered by enum from branch 0
        $object = new $className([
            'items' => [
                ['kind' => 'dynamic', 'value' => 'any_custom_value'],
            ],
        ]);

        $items = $object->getItems();
        $this->assertCount(1, $items);
        $this->assertSame('dynamic', $items[0]->getKind());
        $this->assertSame('any_custom_value', $items[0]->getValue());

        // fixed kind: value must still satisfy the enum
        $object = new $className([
            'items' => [
                ['kind' => 'fixed', 'value' => 'high'],
            ],
        ]);

        $items = $object->getItems();
        $this->assertCount(1, $items);
        $this->assertSame('fixed', $items[0]->getKind());
        $this->assertSame('high', $items[0]->getValue());
    }

    /**
     * allOf: both branches define the same property 'name' with independent constraints.
     * Both constraints (minLength from branch 0, maxLength from branch 1) must apply to the
     * outer merged property without leaking branch-specific validator state.
     */
    public function testAllOfBranchConstraintsAppliedCorrectlyToMergedProperty(): void
    {
        $className = $this->generateClassFromFile('allOfWithSameProperty.json');

        // Valid: satisfies both minLength >= 1 and maxLength <= 64
        $object = new $className(['name' => 'hello']);
        $this->assertSame('hello', $object->getName());

        // Too short: violates minLength from branch 0
        try {
            new $className(['name' => '']);
            $this->fail('Expected ValidationException for empty string');
        } catch (ValidationException $exception) {
            $this->assertMatchesRegularExpression(
                <<<'REGEX'
                /^Invalid value for '\S+' declined by composition constraint
                  Requires to match all composition elements but matched 1 element$/m
                REGEX,
                $exception->getMessage(),
            );
        }

        // Too long: violates maxLength from branch 1
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches(
            <<<'REGEX'
            /^Invalid value for '\S+' declined by composition constraint
              Requires to match all composition elements but matched 1 element$/m
            REGEX,
        );
        new $className(['name' => str_repeat('a', 65)]);
    }

    /**
     * if/then/else: 'value' appears in both then and else branches with different constraints.
     * Branch-specific constraints must only be applied when the corresponding branch is active.
     */
    public function testIfThenElseBranchConstraintsAreNotMergedOntoOuterProperty(): void
    {
        $className = $this->generateClassFromFile('ifThenElseWithSameProperty.json');

        // if condition met (mode=strict): then branch active, value must have minLength 4
        $object = new $className(['mode' => 'strict', 'value' => 'long_value']);
        $this->assertSame('long_value', $object->getValue());

        // if condition not met (mode=other): else branch active, value must have maxLength 32
        $object = new $className(['mode' => 'other', 'value' => 'short']);
        $this->assertSame('short', $object->getValue());
    }

    /**
     * Top-level property combined with oneOf branches: top-level minLength must apply, and
     * branch-specific constraints must remain scoped to their branch.
     */
    public function testTopLevelPropertyWithBranchConstraintsAppliedCorrectly(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };

        $className = $this->generateClassFromFile('oneOfWithTopLevelPropertyAndBranchConstraints.json');

        // vendor scope: metric_id must pass top-level minLength 4 and be a valid enum value
        $object = new $className([
            'committed_metrics' => [
                ['scope' => 'vendor', 'metric_id' => 'spend'],
            ],
        ]);
        $this->assertSame('spend', $object->getCommittedMetrics()[0]->getMetricId());

        // standard scope: metric_id must pass top-level minLength 4 and branch-1's constraints
        $object = new $className([
            'committed_metrics' => [
                ['scope' => 'standard', 'metric_id' => 'cpu_usage'],
            ],
        ]);
        $this->assertSame('cpu_usage', $object->getCommittedMetrics()[0]->getMetricId());

        // standard scope: metric_id too short for top-level minLength 4 — must be rejected
        $this->expectException(ValidationException::class);
        new $className([
            'committed_metrics' => [
                ['scope' => 'standard', 'metric_id' => 'ab'],
            ],
        ]);
    }
}
