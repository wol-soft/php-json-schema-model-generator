<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model\Validator;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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
            [true],
        ))->getCheck();
    }

    /**
     * A validator is a base validator of the scope only if this very instance is registered, not if another validator
     * with equal properties is: the template renders the mutable base validator handling (composition state) based on
     * this flag. A loose comparison (==) was rejected: it compares whole object graphs (which may recurse deeply) and
     * only doesn't match an equal validator because setScope() changes the compared state before comparing.
     */
    public function testValidatorEqualToBaseValidatorIsNoBaseValidator(): void
    {
        $createValidator = static fn(): PropertyTemplateValidator => new PropertyTemplateValidator(
            new Property('DummyProperty', new PropertyType('string'), new JsonSchema('', [])),
            'DummyTemplate',
            [],
            InvalidTypeException::class,
        );

        $baseValidator = $createValidator();
        $equalValidator = $createValidator();
        $this->assertEquals($baseValidator, $equalValidator);

        $schema = new Schema('', '', '', new JsonSchema('', []));
        $schema->addBaseValidator($baseValidator);

        $templateValues = new ReflectionProperty(PropertyTemplateValidator::class, 'templateValues');

        $baseValidator->setScope($schema);
        $this->assertTrue($templateValues->getValue($baseValidator)['isBaseValidator']);

        $equalValidator->setScope($schema);
        $this->assertFalse($templateValues->getValue($equalValidator)['isBaseValidator']);
    }
}
