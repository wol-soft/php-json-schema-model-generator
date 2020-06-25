<?php

namespace PHPModelGenerator\Tests\Model\Validator;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\RenderException;
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
            'UnknownTemplate',
            ['myAssigns' => 1337],
            InvalidTypeException::class,
            [true]
        ))->getCheck();
    }
}
