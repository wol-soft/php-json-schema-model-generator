<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class BasicSchemaGenerationTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class BasicSchemaGenerationTest extends AbstractPHPModelGeneratorTest
{
    public function testInvalidJsonSchemaFileThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageRegExp('/^Invalid JSON-Schema file (.*)\.json$/');

        $this->generateObjectFromFile('InvalidJSONSchema.json');
    }

    public function testJsonSchemaWithoutObjectSpecificationThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageRegExp('/^JSON-Schema doesn\'t provide an object(.*)$/');

        $this->generateObjectFromFile('JSONSchemaWithoutObject.json');
    }
}
