<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #137: When oneOf branches use a $ref to an enum definition, the generated PHP type hint
 * must include the enum class name alongside the scalar type. For example, a oneOf of
 * "$ref: MediaBuyStatus" | "type: array, items: $ref: MediaBuyStatus" should produce type hints
 * like "string|MediaBuyStatus|string[]|MediaBuyStatus[]|null".
 */
class Issue137Test extends AbstractIssueTestCase
{
    /**
     * The @var and @return annotations for status_filter must include the MediaBuyStatus enum
     * class name in addition to string and array.
     */
    public function testTypeHintIncludesEnumClassName(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };

        $className = $this->generateClassFromFile(
            'oneOfWithArrayDef.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className([]);

        // JSON key "status_filter" maps to camelCase property name "statusFilter"
        $propertyAnnotation = $this->getPropertyTypeAnnotation($object, 'statusFilter');
        $returnAnnotation = $this->getReturnTypeAnnotation($object, 'getStatusFilter');

        // Must reference the MediaBuyStatus enum class
        $this->assertStringContainsString(
            'MediaBuyStatus',
            $propertyAnnotation,
            '@var annotation must include MediaBuyStatus enum class',
        );

        $this->assertStringContainsString(
            'MediaBuyStatus',
            $returnAnnotation,
            '@return annotation must include MediaBuyStatus enum class',
        );
    }

    /**
     * A valid string enum value must be accepted.
     */
    public function testValidSingleEnumValueIsAccepted(): void
    {
        $className = $this->generateClassFromFile('oneOfWithArrayDef.json');

        $object = new $className(['status_filter' => 'active']);
        $this->assertSame('active', $object->getStatusFilter());
    }

    /**
     * A valid array of enum values must be accepted.
     */
    public function testValidArrayOfEnumValuesIsAccepted(): void
    {
        $className = $this->generateClassFromFile('oneOfWithArrayDef.json');

        $object = new $className(['status_filter' => ['active', 'paused']]);
        $this->assertSame(['active', 'paused'], $object->getStatusFilter());
    }

    /**
     * An invalid value (not in the enum) must be rejected.
     */
    public function testInvalidEnumValueIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('oneOfWithArrayDef.json');

        new $className(['status_filter' => 'nonexistent']);
    }

    /**
     * An empty array must be rejected (minItems: 1).
     */
    public function testEmptyArrayIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('oneOfWithArrayDef.json');

        new $className(['status_filter' => []]);
    }

    /**
     * Null must be accepted (optional property).
     */
    public function testNullIsAccepted(): void
    {
        $className = $this->generateClassFromFile('oneOfWithArrayDef.json');

        $this->assertNull((new $className([]))->getStatusFilter());
        $this->assertNull((new $className(['status_filter' => null]))->getStatusFilter());
    }
}
