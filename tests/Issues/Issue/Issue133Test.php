<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #133: When two oneOf branches define the same property name (e.g., metric_id) with
 * different constraints (enum vs pattern), validators from one branch must not leak into
 * the merged parent property. Each branch must validate its own property independently.
 *
 * Schema: oneOfWithMetricIdConflict.json
 *   Branch 1 (scope=vendor): metric_id enum ["impressions", "spend", "clicks", "ctr"]
 *   Branch 2 (scope=standard): metric_id string with minLength=1, maxLength=64, pattern
 *
 * Input {"scope": "standard", "metric_id": "custom_metric"} must be valid because it
 * matches branch 2 — the enum constraint from branch 1 must not be applied.
 */
class Issue133Test extends AbstractIssueTestCase
{
    /**
     * Valid input matching branch 1 (vendor scope with known metric_id enum).
     */
    #[DataProvider('validBranch1InputProvider')]
    public function testValidBranch1InputIsAccepted(array $input): void
    {
        $className = $this->generateClassFromFile('oneOfWithMetricIdConflict.json');

        $object = new $className($input);
        $this->assertNotNull($object->getOneOfWithMetricIdConflict());
    }

    public static function validBranch1InputProvider(): array
    {
        return [
            'impressions' => [['oneOfWithMetricIdConflict' => ['scope' => 'vendor', 'metric_id' => 'impressions']]],
            'clicks'      => [['oneOfWithMetricIdConflict' => ['scope' => 'vendor', 'metric_id' => 'clicks']]],
        ];
    }

    /**
     * Valid input matching branch 2 (standard scope with custom metric_id matching pattern).
     * This is the regression test: the enum from branch 1 must not leak into branch 2.
     */
    #[DataProvider('validBranch2InputProvider')]
    public function testValidBranch2InputIsAccepted(array $input): void
    {
        $className = $this->generateClassFromFile('oneOfWithMetricIdConflict.json');

        $object = new $className($input);
        $this->assertNotNull($object->getOneOfWithMetricIdConflict());
    }

    public static function validBranch2InputProvider(): array
    {
        return [
            'custom_metric' => [['oneOfWithMetricIdConflict' => ['scope' => 'standard', 'metric_id' => 'custom_metric']]],
            'simple_name'   => [['oneOfWithMetricIdConflict' => ['scope' => 'standard', 'metric_id' => 'a']]],
        ];
    }

    /**
     * Invalid input that violates oneOf (matches both or neither) must be rejected.
     */
    #[DataProvider('invalidInputProvider')]
    public function testInvalidInputIsRejected(array $input): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('oneOfWithMetricIdConflict.json');

        new $className($input);
    }

    public static function invalidInputProvider(): array
    {
        return [
            'both branches (violates oneOf)'       => [['oneOfWithMetricIdConflict' => ['scope' => 'vendor', 'metric_id' => 'impressions', 'minLength' => 'x']]],
            'no branch matches (missing scope)'    => [['oneOfWithMetricIdConflict' => ['metric_id' => 'custom']]],
            'branch 2 fails pattern (invalid char)' => [['oneOfWithMetricIdConflict' => ['scope' => 'standard', 'metric_id' => 'UPPERCASE']]],
            'branch 2 fails minLength'              => [['oneOfWithMetricIdConflict' => ['scope' => 'standard', 'metric_id' => '']]],
        ];
    }

    /**
     * Absent or null oneOfWithMetricIdConflict must be accepted.
     */
    public function testNullValueIsAccepted(): void
    {
        $className = $this->generateClassFromFile('oneOfWithMetricIdConflict.json');

        $this->assertNull((new $className([]))->getOneOfWithMetricIdConflict());
        $this->assertNull((new $className(['oneOfWithMetricIdConflict' => null]))->getOneOfWithMetricIdConflict());
    }
}
