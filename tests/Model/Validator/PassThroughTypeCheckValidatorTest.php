<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model\Validator;

use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PassThroughTypeCheckValidator;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for PassThroughTypeCheckValidator::getTypes().
 */
class PassThroughTypeCheckValidatorTest extends TestCase
{
    /**
     * Returns the return type of a known method so we have a real ReflectionNamedType without
     * needing to create one from scratch.
     */
    private function getStringReturnType(): \ReflectionNamedType
    {
        // strtoupper(string $string): string — return type is 'string'
        return (new ReflectionMethod('PHPModelGenerator\PropertyProcessor\Filter\TrimFilter', 'getToken'))
            ->getReturnType();
    }

    public function testGetTypesMergesInnerValidatorTypesWithPassThroughType(): void
    {
        $property           = new Property('test', new PropertyType('int'), new JsonSchema('', []));
        $typeCheckValidator = new TypeCheckValidator('int', $property, false);
        $passThroughType    = $this->getStringReturnType(); // 'string'

        $validator = new PassThroughTypeCheckValidator($passThroughType, $property, $typeCheckValidator);

        // getTypes() must return the inner validator's types plus the pass-through type name.
        $this->assertSame(['int', 'string'], $validator->getTypes());
    }

    public function testGetTypesWithMultiTypeInnerValidator(): void
    {
        // Use a TypeCheckValidator whose inner type is 'string'; the pass-through type is 'string'
        // again — duplicates are included because PassThroughTypeCheckValidator uses array_merge.
        $property           = new Property('test', new PropertyType('string'), new JsonSchema('', []));
        $typeCheckValidator = new TypeCheckValidator('string', $property, false);
        $passThroughType    = $this->getStringReturnType(); // 'string'

        $validator = new PassThroughTypeCheckValidator($passThroughType, $property, $typeCheckValidator);

        $this->assertSame(['string', 'string'], $validator->getTypes());
    }
}
