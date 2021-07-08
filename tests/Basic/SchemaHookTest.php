<?php

namespace PHPModelGenerator\Tests\Basic;

use Exception;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Number\MinimumException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\Hook\ConstructorAfterValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\ConstructorBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\GetterHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SerializationHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SetterAfterValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class SchemaHookTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class SchemaHookTest extends AbstractPHPModelGeneratorTest
{
    public function testConstructorBeforeValidationHookIsResolved(): void
    {
        $this->addSchemaHook(new class () implements ConstructorBeforeValidationHookInterface {
            public function getCode(): string
            {
                return 'throw new \Exception("ConstructorBeforeValidationHook");';
            }
        });

        $className = $this->generateClassFromFile('BasicSchema.json');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("ConstructorBeforeValidationHook");

        new $className(['name' => false]);
    }

    /**
     * @dataProvider constructorAfterValidationHookDataProvider
     *
     * @param $value
     * @param string $expectedException
     * @param string $expectedExceptionMessage
     */
    public function testConstructorAfterValidationHookIsResolved(
        $value,
        string $expectedException,
        string $expectedExceptionMessage
    ): void {
        $this->addSchemaHook(new class () implements ConstructorAfterValidationHookInterface {
            public function getCode(): string
            {
                return 'throw new \Exception("ConstructorAfterValidationHook");';
            }
        });

        $className = $this->generateClassFromFile('BasicSchema.json');

        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedExceptionMessage);

        new $className(['name' => $value]);
    }

    public function constructorAfterValidationHookDataProvider(): array
    {
        return [
            'Invalid value' => [
                false,
                InvalidTypeException::class,
                'Invalid type for name. Requires string, got boolean',
            ],
            'Valid value' => [
                'Hannes',
                Exception::class,
                'ConstructorAfterValidationHook',
            ],
        ];
    }

    public function testGetterHookIsResolved(): void
    {
        $this->addSchemaHook(new class () implements GetterHookInterface {
            public function getCode(PropertyInterface $property): string
            {
                return $property->getName() === 'age' ? 'throw new \Exception("GetterHook");' : '';
            }
        });

        $className = $this->generateClassFromFile('BasicSchema.json');

        $object = new $className(['name' => 'Albert', 'age' => 35]);

        $this->assertSame('Albert', $object->getName());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("GetterHook");

        $object->getAge();
    }

    public function testSetterBeforeValidationHookIsResolved(): void
    {
        $this->addSchemaHook(new class () implements SetterBeforeValidationHookInterface {
            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
            {
                return $property->getName() === 'age' ? 'throw new \Exception("SetterBeforeValidationHook");' : '';
            }
        });

        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['name' => 'Albert', 'age' => 35]);

        $this->assertSame('Hannes', $object->setName('Hannes')->getName());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("SetterBeforeValidationHook");

        $object->setAge(-12);
    }


    /**
     * @dataProvider setterAfterValidationHookDataProvider
     *
     * @param $value
     * @param string $expectedException
     * @param string $expectedExceptionMessage
     */
    public function testSetterAfterValidationHookIsResolved(
        $value,
        string $expectedException,
        string $expectedExceptionMessage
    ): void {
        $this->addSchemaHook(new class () implements SetterAfterValidationHookInterface {
            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
            {
                return $property->getName() === 'age' ? 'throw new \Exception("SetterAfterValidationHook");' : '';
            }
        });

        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false)
        );

        $object = new $className(['name' => 'Albert', 'age' => 35]);

        $this->assertSame('Hannes', $object->setName('Hannes')->getName());

        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $object->setAge($value);
    }

    public function setterAfterValidationHookDataProvider(): array
    {
        return [
            'Invalid value' => [
                -12,
                MinimumException::class,
                'Value for age must not be smaller than 0',
            ],
            'Valid value' => [
                12,
                Exception::class,
                'SetterAfterValidationHook',
            ],
        ];
    }

    public function testSerializationHookIsResolved(): void
    {
        $this->addSchemaHook(new class () implements SerializationHookInterface {
            public function getCode(): string
            {
                return 'throw new \Exception("SerializationHookInterface");';
            }
        });

        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setSerialization(true)
        );

        $object = new $className(['name' => 'Albert', 'age' => 35]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("SerializationHookInterface");

        $object->toArray();
    }

    protected function addSchemaHook(SchemaHookInterface $schemaHook): void
    {
        $this->modifyModelGenerator = function (ModelGenerator $modelGenerator) use ($schemaHook): void {
            $modelGenerator->addPostProcessor(new class ($schemaHook) extends PostProcessor {
                private $schemaHook;

                public function __construct(SchemaHookInterface $schemaHook)
                {
                    $this->schemaHook = $schemaHook;
                }
                public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
                {
                    $schema->addSchemaHook($this->schemaHook);
                }
            });
        };
    }
}
