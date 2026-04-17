<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Issue #116: When an external schema file contains a $ref pointing to another schema file and
 * both are processed together via RecursiveDirectoryProvider, the generator creates a duplicate
 * nested class for the referenced schema instead of reusing the already-generated top-level class.
 *
 * The duplicate is a different PHP type, so instanceof checks fail and type-safe interfaces break.
 * The bug only manifests when the referencing schema is discovered (and processed) before the
 * referenced schema — i.e. it is order-dependent.
 */
class Issue116Test extends AbstractIssueTestCase
{
    private function config(string $namespace): GeneratorConfiguration
    {
        return (new GeneratorConfiguration())
            ->setNamespacePrefix($namespace)
            ->setOutputEnabled(false)
            ->setCollectErrors(false);
    }

    // -------------------------------------------------------------------------
    // Basic $ref — property type must be the standalone class (both orderings)
    // -------------------------------------------------------------------------

    /**
     * ForecastPoint.json sorts before ForecastRange.json alphabetically, so the referencing schema
     * is processed first. This is the ordering that triggers the bug: the $ref resolution fires
     * before ForecastRange is registered, causing a duplicate nested class to be generated.
     */
    public function testReferencingSchemaProcessedFirstProducesCorrectType(): void
    {
        $this->generateDirectory('ExternalRefOrderAB', $this->config('Ns116RefAB'));

        $pointClass = '\\Ns116RefAB\\ForecastPoint';
        $rangeClass = '\\Ns116RefAB\\ForecastRange';

        // Return type must be the canonical ForecastRange, not a nested duplicate
        $this->assertContains(
            ltrim($rangeClass, '\\'),
            $this->getReturnTypeNames($pointClass, 'getBaseline'),
            'getBaseline() return type must be the standalone ForecastRange class',
        );

        $point = new $pointClass(['baseline' => ['mid' => 3.5]]);

        $this->assertInstanceOf(
            $rangeClass,
            $point->getBaseline(),
            'getBaseline() must return an instance of the standalone ForecastRange class',
        );
        $this->assertSame(3.5, $point->getBaseline()->getMid());
    }

    /**
     * AAForecastRange.json sorts before ZZForecastPoint.json, so the referenced schema is
     * processed first. This ordering does not trigger the bug and serves as a baseline.
     */
    public function testReferencedSchemaProcessedFirstProducesCorrectType(): void
    {
        $this->generateDirectory('ExternalRefOrderBA', $this->config('Ns116RefBA'));

        $pointClass = '\\Ns116RefBA\\ZZForecastPoint';
        $rangeClass = '\\Ns116RefBA\\AAForecastRange';

        $this->assertContains(
            ltrim($rangeClass, '\\'),
            $this->getReturnTypeNames($pointClass, 'getBaseline'),
            'getBaseline() return type must be the standalone AAForecastRange class',
        );

        $point = new $pointClass(['baseline' => ['mid' => 7.0]]);

        $this->assertInstanceOf(
            $rangeClass,
            $point->getBaseline(),
            'getBaseline() must return an instance of the standalone AAForecastRange class',
        );
        $this->assertSame(7.0, $point->getBaseline()->getMid());
    }

    // -------------------------------------------------------------------------
    // $ref in additionalProperties
    // -------------------------------------------------------------------------

    public function testAdditionalPropertiesRefGeneratesCorrectTypes(): void
    {
        $namespace = 'AdditionalPropertiesRef116';
        $this->generateDirectory('AdditionalPropertiesRef', $this->config($namespace));

        $pointClass = "\\{$namespace}\\ForecastPoint";
        $rangeClass = "\\{$namespace}\\ForecastRange";

        // Instantiation must succeed — validates that the generated class is usable
        $point = new $pointClass(['metrics' => ['q1' => ['mid' => 2.0], 'q2' => ['mid' => 4.0]]]);
        $this->assertNotNull($point->getMetrics());

        // The @return annotation on getMetrics() must reference the canonical ForecastRange class
        // (inside the generated Metrics container doc block), not a nested duplicate.
        // We inspect the Metrics container class directly: it is the non-null return type of getMetrics().
        $metricsTypeName = $this->getReturnTypeNames($pointClass, 'getMetrics');
        $metricsClassName = null;
        foreach ($metricsTypeName as $name) {
            if ($name !== 'null') {
                $metricsClassName = $name;
                break;
            }
        }

        $this->assertNotNull($metricsClassName, 'getMetrics() must have a non-null return type');

        // The doc comment on the additionalProperties property inside the Metrics class must
        // reference the canonical ForecastRange, not the nested duplicate
        $additionalPropertiesAnnotation = $this->getPropertyTypeAnnotation($metricsClassName, 'additionalProperties');

        // The annotation uses the short class name (imports handle the FQCN); check for it.
        $shortRangeClass = substr($rangeClass, strrpos($rangeClass, '\\') + 1);
        $this->assertStringContainsString(
            $shortRangeClass,
            $additionalPropertiesAnnotation,
            'additionalProperties @var annotation must reference the standalone ForecastRange class',
        );
        // Must NOT reference a nested duplicate (e.g. ForecastPoint_AdditionalProperty<hash>)
        $this->assertStringNotContainsString(
            'ForecastPoint_',
            $additionalPropertiesAnnotation,
            '_additionalProperties @var annotation must not reference a nested duplicate class',
        );
    }

    // -------------------------------------------------------------------------
    // Multiple schemas referencing the same $ref target — only one class generated
    // -------------------------------------------------------------------------

    public function testMultipleReferrersProduceSingleTargetClass(): void
    {
        $namespace = 'MultipleReferrers116';
        $this->generateDirectory('MultipleReferrers', $this->config($namespace));

        $pointClass   = "\\{$namespace}\\ForecastPoint";
        $summaryClass = "\\{$namespace}\\WeatherSummary";
        $rangeClass   = "\\{$namespace}\\ForecastRange";

        $point   = new $pointClass(['baseline' => ['mid' => 1.5]]);
        $summary = new $summaryClass(['current' => ['mid' => 2.5]]);

        $this->assertInstanceOf($rangeClass, $point->getBaseline());
        $this->assertInstanceOf($rangeClass, $summary->getCurrent());

        // Both getters must return instances of the exact same class
        $this->assertSame(
            $point->getBaseline()::class,
            $summary->getCurrent()::class,
            'ForecastPoint::getBaseline() and WeatherSummary::getCurrent() must share the same ForecastRange class',
        );
    }

    // -------------------------------------------------------------------------
    // $ref in array items
    // -------------------------------------------------------------------------

    public function testArrayItemsRefTypeIsTheStandaloneClass(): void
    {
        $namespace = 'ArrayItemsRef116';
        $this->generateDirectory('ArrayItemsRef', $this->config($namespace));

        $collectionClass = "\\{$namespace}\\ForecastCollection";
        $rangeClass      = "\\{$namespace}\\ForecastRange";

        $collection = new $collectionClass([
            'forecasts' => [
                ['mid' => 1.0],
                ['mid' => 2.0],
            ],
        ]);

        $this->assertNotNull($collection->getForecasts());
        foreach ($collection->getForecasts() as $item) {
            $this->assertInstanceOf(
                $rangeClass,
                $item,
                'Array item must be an instance of the standalone ForecastRange class',
            );
        }
    }

    // -------------------------------------------------------------------------
    // Fragment on external ref — two properties referencing different fragments
    // of the same file must share a single parsed class
    // -------------------------------------------------------------------------

    public function testFragmentRefTwoPropertiesShareSameClass(): void
    {
        $namespace = 'FragmentRef116';
        $this->generateDirectory('FragmentRef', $this->config($namespace));

        $pointClass = "\\{$namespace}\\ForecastPoint";

        $point = new $pointClass([
            'baseline' => ['mid' => 1.0],
            'peak'     => ['mid' => 5.0],
        ]);

        $this->assertSame(
            $point->getBaseline()::class,
            $point->getPeak()::class,
            'baseline and peak must share the same class when both ref the same fragment',
        );
    }

    public function testFragmentRefValueIsCorrect(): void
    {
        $namespace = 'FragmentRef116b';
        $this->generateDirectory('FragmentRef', $this->config($namespace));

        $pointClass = "\\{$namespace}\\ForecastPoint";

        $point = new $pointClass([
            'baseline' => ['mid' => 1.5],
            'peak'     => ['mid' => 9.0],
        ]);

        $this->assertSame(1.5, $point->getBaseline()->getMid());
        $this->assertSame(9.0, $point->getPeak()->getMid());
    }
}
