<?php

namespace PHPModelGenerator\Tests\Model\Validator;

use PHPModelGenerator\Exception\InvalidArgumentException;
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
        $this->expectExceptionMessageRegExp(
            '/Can\'t render property validation template UnknownTemplate with values(.*)/'
        );

        (new PropertyTemplateValidator(
            InvalidArgumentException::class,
            'Something went wrong',
            'UnknownTemplate',
            ['myAssigns' => 1337]
        ))->getCheck();
    }
}
