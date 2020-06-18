<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class ExplicitNullTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class ExplicitNullTest extends AbstractPHPModelGeneratorTest
{
    public function testNullForOptionalValueWithoutImplicitNullThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid type for age. Requires int, got NULL');

        $className = $this->generateClassFromFile('ImplicitNull.json', null, false, false);

        new $className(['name' => 'Hannes', 'age' => null]);
    }

    public function testNullForRequiredValueWithoutImplicitNullThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid type for name. Requires string, got NULL');

        $className = $this->generateClassFromFile('ImplicitNull.json', null, false, false);

        new $className(['name' => null]);
    }
    public function testNullForOptionalValueWithImplicitNullIsValid(): void
    {
        $className = $this->generateClassFromFile('ImplicitNull.json');

        $object = new $className(['name' => 'Hannes', 'age' => null]);
        $this->assertNull($object->getAge());
    }

    public function testNullForRequiredValueWithImplicitNullThrowsAnException(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid type for name. Requires string, got NULL');

        $className = $this->generateClassFromFile('ImplicitNull.json');

        new $className(['name' => null]);
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     */
    public function testNullForOptionalValueWithExplicitNullIsValid(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile('ExplicitNull.json', null, false, $implicitNull);

        $object = new $className(['name' => 'Hannes', 'age' => null]);
        $this->assertSame('Hannes', $object->getName());
        $this->assertNull($object->getAge());
    }

    /**
     * @dataProvider implicitNullDataProvider
     *
     * @param bool $implicitNull
     */
    public function testNullForRequiredValueWithExplicitNullIsValid(bool $implicitNull): void
    {
        $className = $this->generateClassFromFile('ExplicitNull.json', null, false, $implicitNull);

        $object = new $className(['name' => null, 'age' => 31]);
        $this->assertNull($object->getName());
        $this->assertSame(31, $object->getAge());
    }
}
