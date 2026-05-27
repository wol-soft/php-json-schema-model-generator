<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * When a schema property uses oneOf with branches that have properties/required/additionalProperties
 * but no explicit type: object, the branches must still generate separate class files.
 *
 * This was broken in the v3ready refactoring: inheritPropertyType() returned early when the
 * parent property lacked type, so branches stayed type: 'any' → no nested class generated.
 */
class OneOfWithoutTypeTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * The item property's type annotation must reference separate branch classes
     * (e.g. OneOfWithoutTypeTest_*|OneOfWithoutTypeTest_*|null).
     */
    public function testOneOfBranchesGenerateSeparateClasses(): void
    {
        $className = $this->generateClassFromFile('OneOfWithoutType.json');

        $object = new $className([]);

        $propertyAnnotation = $this->getPropertyTypeAnnotation($object, 'item');
        $returnAnnotation = $this->getReturnTypeAnnotation($object, 'getItem');

        // Each branch should have its own class: match *|*|null pattern
        $this->assertMatchesRegularExpression(
            '/OneOfWithoutTypeTest[\w]*\|OneOfWithoutTypeTest[\w]*\|null/',
            $propertyAnnotation,
            'item property @var annotation must reference separate branch classes',
        );

        $this->assertMatchesRegularExpression(
            '/OneOfWithoutTypeTest[\w]*\|OneOfWithoutTypeTest[\w]*\|null/',
            $returnAnnotation,
            'getItem() @return annotation must reference separate branch classes',
        );
    }

    /**
     * Valid input matching the first branch (name) must be accepted.
     */
    public function testValidInputForFirstBranchIsAccepted(): void
    {
        $className = $this->generateClassFromFile('OneOfWithoutType.json');

        $object = new $className(['item' => ['name' => 'test']]);
        $this->assertNotNull($object->getItem());
        $this->assertSame('test', $object->getItem()->getName());
    }

    /**
     * Valid input matching the second branch (id) must be accepted.
     */
    public function testValidInputForSecondBranchIsAccepted(): void
    {
        $className = $this->generateClassFromFile('OneOfWithoutType.json');

        $object = new $className(['item' => ['id' => 42]]);
        $this->assertNotNull($object->getItem());
        $this->assertSame(42, $object->getItem()->getId());
    }

    /**
     * Input satisfying neither branch must be rejected.
     */
    #[DataProvider('invalidInputProvider')]
    public function testInvalidInputIsRejected(array $input): void
    {
        $this->expectException(ValidationException::class);

        $className = $this->generateClassFromFile('OneOfWithoutType.json');

        new $className($input);
    }

    public static function invalidInputProvider(): array
    {
        return [
            'empty object' => [['item' => []]],
            'both branches (violates oneOf)' => [['item' => ['name' => 'test', 'id' => 5]]],
            'wrong property name' => [['item' => ['unknown' => 'value']]],
        ];
    }

    /**
     * Null or absent item must be accepted (optional property).
     */
    public function testNullItemIsAccepted(): void
    {
        $className = $this->generateClassFromFile('OneOfWithoutType.json');

        $this->assertNull((new $className([]))->getItem());
        $this->assertNull((new $className(['item' => null]))->getItem());
    }
}
