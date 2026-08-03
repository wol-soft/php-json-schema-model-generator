<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use ReflectionClass;

/**
 * Issue #179: AbstractComposedPropertyValidator::setupBranchDefaultHelpers() iterates a
 * composition branch's nested schema properties without excluding internal bookkeeping
 * properties (isInternal() === true). When serialization is enabled and implicit null is
 * disallowed, every generated branch class carries its own internal
 * `_skipNotProvidedPropertiesMap` property with a non-null default value ([]), so it gets
 * misread as a real branch property and leaks into both componentDefaultValueMap and
 * propertyAccessors of the containing class's `_getModifiedValues_*` helper - even though no
 * corresponding getter is ever generated for it.
 *
 * Issue169Test.php fixed the same class of bug for allBranchDefaultAttributeMap; this covers
 * the residual gap in the two structures that fix did not cover.
 */
class Issue179Test extends AbstractIssueTestCase
{
    public function testGetModifiedValuesHelperExcludesInternalBookkeepingProperties(): void
    {
        $className = $this->generateClassFromFile(
            'ComposedBranchSerialization.json',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('\\Issue179Test')
                ->setSerialization(true),
            false,
            false,
        );

        $classContent = file_get_contents(
            (new ReflectionClass('\\Issue179Test\\' . $className))->getFileName(),
        );

        preg_match(
            '/private function _getModifiedValues_\w+\([^)]*\): array \{.*?\n    \}/s',
            $classContent,
            $matches,
        );

        $this->assertNotEmpty($matches, 'Expected a _getModifiedValues_* helper method to be generated');

        $this->assertStringNotContainsString(
            'skipNotProvidedPropertiesMap',
            $matches[0],
            "The _getModifiedValues_* helper must not reference the internal bookkeeping property "
                . "'_skipNotProvidedPropertiesMap' of a composition branch's own generated class - it is never "
                . 'transferred onto the containing object and has no corresponding getter.',
        );
    }
}
