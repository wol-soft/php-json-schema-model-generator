<?php

namespace PHPModelGenerator\Tests\Model\Validator;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPUnit\Framework\TestCase;

/**
 * Class PropertyTemplateValidatorTest
 *
 * @package PHPModelGenerator\Tests\Model\Validator
 */
class PropertyTemplateValidatorTest extends TestCase
{
    public function testInvalidRenderExceptionIsConverted(): void
    {
        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('Can\'t render property validation template UnknownTemplate');

        (new PropertyTemplateValidator(
            new Property('DummyProperty', new PropertyType('string'), new JsonSchema('', [])),
            'UnknownTemplate',
            ['myAssigns' => 1337],
            InvalidTypeException::class,
            [true]
        ))->getCheck();
    }
}
