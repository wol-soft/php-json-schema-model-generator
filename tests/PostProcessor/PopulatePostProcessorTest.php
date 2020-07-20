<?php

namespace PHPModelGenerator\Tests\PostProcessor;

use Exception;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\InvalidAdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPropertyNamesException;
use PHPModelGenerator\Exception\Object\MaxPropertiesException;
use PHPModelGenerator\Exception\String\PatternException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

class PopulatePostProcessorTest extends AbstractPHPModelGeneratorTest
{
    public function setUp(): void
    {
        parent::setUp();

        $this->modifyModelGenerator = function (ModelGenerator $generator) {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };
    }

    public function testPopulateMethod(): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setSerialization(true)
        );
        $object = new $className(['name' => 'Albert']);

        $this->assertTrue(is_callable([$object, 'populate']));

        // test an empty populate call doesn't change the internal behaviour
        $object->populate([]);
        $this->assertSame(['name' => 'Albert'], $object->getRawModelDataInput());
        $this->assertSame(['name' => 'Albert', 'age' => null], $object->toArray());

        // test adding an additional property to the model
        $object->populate(['birthdate' => '10.10.1990']);
        $this->assertSame(['name' => 'Albert', 'birthdate' => '10.10.1990'], $object->getRawModelDataInput());
        $this->assertSame(['name' => 'Albert', 'age' => null], $object->toArray());

        // test overwriting a single property
        $object->populate(['age' => 30]);
        $this->assertSame(
            ['name' => 'Albert', 'birthdate' => '10.10.1990', 'age' => 30],
            $object->getRawModelDataInput()
        );
        $this->assertSame(['name' => 'Albert', 'age' => 30], $object->toArray());

        // test overwriting multiple properties
        $object->populate(['age' => 26, 'name' => 'Harry']);
        $this->assertSame(
            ['name' => 'Harry', 'birthdate' => '10.10.1990', 'age' => 26],
            $object->getRawModelDataInput()
        );
        $this->assertSame(['name' => 'Harry', 'age' => 26], $object->toArray());
    }

    /**
     * @dataProvider invalidPopulateDataProvider
     */
    public function testInvalidPopulateThrowsAnException(
        array $data,
        bool $collectErrors,
        string $expectedException,
        string $expectedMessage
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedMessage);

        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)
        );
        $object = new $className(['name' => 'Albert', 'age' => 30]);

        try {
            $object->populate($data);
            $this->fail('No exception thrown');
        } catch (Exception $exception) {
            // test if the internal state hasn't been changed
            $this->assertSame(['name' => 'Albert', 'age' => 30], $object->getRawModelDataInput());

            throw $exception;
        }
    }

    public function invalidPopulateDataProvider(): array
    {
        return [
            'No error collection - multiple violations' => [
                ['name' => 'Anne-Marie', 'age' => false],
                false,
                PatternException::class,
                "Value for name doesn't match pattern ^[a-zA-Z]*$"
            ],
            'Error collection - multiple violations' => [
                ['name' => 'Anne-Marie', 'age' => false],
                true,
                ErrorRegistryException::class,
                "Value for name doesn't match pattern ^[a-zA-Z]*$
Value for name must not be longer than 8
Invalid type for age. Requires int, got boolean"
            ],
            'Invalid additional property' => [
                ['numeric' => 9],
                false,
                InvalidAdditionalPropertiesException::class,
                "contains invalid additional properties.
  - invalid additional property 'numeric'
    * Invalid type for additional property. Requires string, got integer"
            ],
            'Invalid additional property name' => [
                ['invalid name' => 'Hi'],
                false,
                InvalidPropertyNamesException::class,
                "contains properties with invalid names.
  - invalid property 'invalid name'
    * Value for property name doesn't match pattern ^[a-zA-Z]*$"
            ],
            'Too many properties' => [
                ['additional' => 'Hi', 'another' => 'Ho'],
                false,
                MaxPropertiesException::class,
                "must not contain more than 3 properties"
            ],
        ];
    }
}
