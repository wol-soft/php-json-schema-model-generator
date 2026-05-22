<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue133Test extends AbstractIssueTestCase
{
    /**
     * Demonstrates the bug: oneOf branches define the same property name (metric_id)
     * with conflicting constraints (enum in branch 1, minLength/pattern in branch 2).
     *
     * ERROR:
     *   Invalid value for property metric_id denied by filter php_model_generator_enum:
     *   "custom_metric" is not a valid backing value for enum
     *   Enum\Issue133Test_..._ItemOfArrayCommitted_Metrics..._Metric_Id
     *
     * WHERE:
     *   Thrown at /tmp/PHPModelGeneratorTest/Models/Issue133Test_*.php:239
     *   Inside the enum filter validator applied to the "metric_id" property
     *
     * WHY (speculation):
     *   Both oneOf branches define "metric_id" — branch 1 with an enum validator
     *   (["impressions", "spend", "clicks", "ctr"]) and branch 2 with
     *   minLength/maxLength/pattern validators. During composition merging, the enum
     *   validator from branch 1 is incorrectly applied to the merged property regardless
     *   of which branch is active. Since "custom_metric" does not satisfy the enum constraint,
     *   validation fails even though the input matches branch 2 perfectly.
     *
     *   The root cause is that shared property names across oneOf branches lose their
     *   branch-level scoping — validators from all branches are merged/unconditionally
     *   applied instead of being kept separate per-branch.
     */
    public function testOneOfWithConflictingPropertyConstraintsCausesSerializationError(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };

        $className = $this->generateClassFromFile('oneOfWithMetricIdConflict.json');

        // Create a valid instance matching the second oneOf branch (vendor scope)
        // metric_id "custom_metric" satisfies minLength/pattern but NOT the enum in branch 1
        $object = new $className([
            'committed_metrics' => [
                ['scope' => 'standard', 'metric_id' => 'custom_metric'],
            ],
        ]);

        // Serialize back to array - this should work but currently fails
        // because the merged metric_id property loses its validators
        $serialized = $object->toArray();

        $this->assertSame(
            ['committed_metrics' => [['scope' => 'standard', 'metric_id' => 'custom_metric']]],
            $serialized,
        );
    }
}
