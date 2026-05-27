<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Issue #141: When a property uses allOf with a $ref to a string enum definition, the EnumPostProcessor's
 * Enum::filter transforms the input string to a UnitEnum object. The composed property validator's
 * _getModifiedValues_* helper is then called with $originalModelData still being the original string
 * (set before the filter runs), but the helper expects array. This causes a TypeError.
 *
 * The fix adds `&& is_array($originalModelData)` to the guard in ComposedItem.phptpl so that
 * _getModifiedValues is only called when the original data was an array (i.e., a nested object),
 * not when a filter transformed a scalar to an object (e.g., string enum to UnitEnum).
 */
class Issue141Test extends AbstractIssueTestCase
{
    /**
     * Construction with a valid enum string value must not throw TypeError when
     * EnumPostProcessor transforms the string to a UnitEnum object via Enum::filter.
     */
    public function testValidEnumValueTriggersNoTypeError(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };

        $className = $this->generateClassFromFile('allOfWithRefEnum.json');

        $object = new $className(['goal_type' => 'clicks']);
        $this->assertNotNull($object);
    }

    /**
     * An invalid enum value must be rejected with a ValidationException.
     */
    public function testInvalidEnumValueIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('allOfWithRefEnum.json');

        new $className(['goal_type' => 'nonexistent']);
    }

    /**
     * Absent or explicit null must be accepted (optional property).
     */
    public function testNullIsAccepted(): void
    {
        $className = $this->generateClassFromFile('allOfWithRefEnum.json');

        $this->assertNull((new $className([]))->getGoalType());
        $this->assertNull((new $className(['goal_type' => null]))->getGoalType());
    }
}
