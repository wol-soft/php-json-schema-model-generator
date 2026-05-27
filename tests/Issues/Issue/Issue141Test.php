<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Issue #141: When a property uses allOf with a $ref to a string enum definition, the EnumPostProcessor's
 * Enum::filter transforms the input string to a UnitEnum object.
 *
 * Two bugs were fixed:
 * 1. The composed property validator's _getModifiedValues_* helper was called with $originalModelData
 *    still being the original string (set before the filter runs), but the helper expects array. Fixed
 *    by adding `&& is_array($originalModelData)` to the guard in ComposedItem.phptpl.
 * 2. The getter's PHP return type was not updated to include the enum class name, causing a TypeError
 *    when calling the getter. Fixed by propagating the enum output type from composed properties to
 *    the parent property in EnumPostProcessor::processNestedEnumProperties.
 */
class Issue141Test extends AbstractIssueTestCase
{
    private function enableEnumPostProcessor(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };
    }

    /**
     * Construction, getter, null, and invalid-value rejection must all work without
     * TypeError when EnumPostProcessor is active. All assertions share one generated
     * class because generating multiple times with the same EnumPostProcessor would
     * cause a fatal error (PHP cannot redeclare the enum class).
     */
    public function testEnumPostProcessorWorkflow(): void
    {
        $this->enableEnumPostProcessor();

        $className = $this->generateClassFromFile('allOfWithRefEnum.json');

        // Construction must not throw TypeError when Enum filter transforms string to enum object
        $object = new $className(['goal_type' => 'clicks']);
        $this->assertNotNull($object);

        // Calling the getter must not throw TypeError — the return type must include the enum class
        $value = $object->getGoalType();
        $this->assertNotNull($value);

        // Null must be accepted (optional property)
        $this->assertNull((new $className([]))->getGoalType());
        $this->assertNull((new $className(['goal_type' => null]))->getGoalType());

        // Invalid value must be rejected
        $this->expectException(ValidationException::class);
        new $className(['goal_type' => 'nonexistent']);
    }
}
