<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model\Validator;

use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PassThroughTypeCheckValidator;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PassThroughTypeCheckValidator::getTypes().
 */
class PassThroughTypeCheckValidatorTest extends TestCase
{
    public function testGetTypesMergesInnerValidatorTypesWithPassThroughType(): void
    {
        $property           = new Property('test', new PropertyType('int'), new JsonSchema('', []));
        $typeCheckValidator = new TypeCheckValidator('int', $property, false);

        $validator = new PassThroughTypeCheckValidator(['string'], $property, $typeCheckValidator);

        // getTypes() must return the inner validator's types plus the pass-through type name.
        $this->assertSame(['int', 'string'], $validator->getTypes());
    }

    public function testGetTypesDeduplicate(): void
    {
        $property           = new Property('test', new PropertyType('string'), new JsonSchema('', []));
        $typeCheckValidator = new TypeCheckValidator('string', $property, false);

        $validator = new PassThroughTypeCheckValidator(['string'], $property, $typeCheckValidator);

        // Duplicate between inner validator type and pass-through type is removed.
        $this->assertSame(['string'], $validator->getTypes());
    }
}
