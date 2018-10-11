<?php

namespace PHPModelGenerator\Tests\Objects;

/**
 * Class StringPropertyTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
class StringPropertyTest extends AbstractPHPModelGeneratorTest
{
    public function testSimpleStringProperty()
    {
        $object = $this->generateObject('{"type":"object","properties":{"property":{"type":"string"}}}', []);

        $this->assertTrue(is_callable([$object, 'getProperty']));
        $this->assertTrue(is_callable([$object, 'setProperty']));
    }
}
