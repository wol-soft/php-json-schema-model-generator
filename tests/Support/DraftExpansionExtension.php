<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Support;

use PHPUnit\Metadata\Parser\Registry;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;

/**
 * PHPUnit extension that causes #[ApplicableDrafts]-annotated test methods to run as separate
 * PHPUnit entries — one per applicable JSON Schema draft.
 *
 * Quick mode (default, PHPUNIT_FULL_DRAFT_COVERAGE unset or ≠ '1'):
 *   Each annotated test runs once using the latest applicable draft.
 *
 * Full mode (PHPUNIT_FULL_DRAFT_COVERAGE=1):
 *   Each annotated test runs once per draft in the declared range.
 *
 * When a test method also carries a #[DataProvider], the drafts and data-provider entries are
 * combined into a Cartesian product so the test runs draft × data-provider-entry times.
 *
 * The draft dimension is communicated through DraftRunContext, keyed by $this->dataName(), and
 * read in AbstractPHPModelGeneratorTestCase::setUp() into $this->currentConfiguration.
 *
 * Register in phpunit.xml:
 *   <extensions>
 *     <bootstrap class="PHPModelGenerator\Tests\Support\DraftExpansionExtension"/>
 *   </extensions>
 */
final class DraftExpansionExtension implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        $registryReflection = new ReflectionClass(Registry::class);
        $instanceProperty   = $registryReflection->getProperty('instance');

        $originalParser = Registry::parser();
        $instanceProperty->setValue(null, new ApplicableDraftsMetadataParser($originalParser));
    }
}
