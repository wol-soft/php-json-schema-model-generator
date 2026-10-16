<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use Exception;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\ErrorRegistryException;
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
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Class SchemaHookTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
#[ApplicableDrafts]
class SchemaHookTest extends AbstractPHPModelGeneratorTestCase
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

    #[DataProvider('constructorAfterValidationHookDataProvider')]
    public function testConstructorAfterValidationHookIsResolved(
        bool|string $value,
        string $expectedException,
        string $expectedExceptionMessage,
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

    public static function constructorAfterValidationHookDataProvider(): array
    {
        return [
            'Invalid value' => [
                false,
                InvalidTypeException::class,
                "Invalid type for 'name': requires 'string', got 'boolean'",
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
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['name' => 'Albert', 'age' => 35]);

        $this->assertSame('Hannes', $object->setName('Hannes')->getName());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("SetterBeforeValidationHook");

        $object->setAge(-12);
    }


    /**
     * The model state changed by a custom setter hook is part of the update: a rejected update restores it. A setter
     * without any before validation hook code throws before changing the model state and is rendered without the
     * rollback handling.
     */
    #[DataProvider('collectErrorsDataProvider')]
    public function testModelStateChangedBySetterBeforeValidationHookIsRestoredForRejectedUpdate(
        bool $collectErrors,
        string $expectedException,
    ): void {
        $this->addSchemaHook(new class () implements SetterBeforeValidationHookInterface {
            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
            {
                return $property->getName() === 'age' ? '$this->_rawModelDataInput[\'hookExecuted\'] = true;' : '';
            }
        });

        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors($collectErrors),
        );

        $object = new $className(['name' => 'Albert', 'age' => 35]);

        $exception = $this->catchException(static fn() => $object->setAge(-12));
        $this->assertInstanceOf($expectedException, $exception, 'Expected the minimum violation of age');
        $this->assertSame("Value for 'age' must not be smaller than 0", $exception->getMessage());

        $this->assertSame(35, $object->getAge());
        $this->assertSame(['name' => 'Albert', 'age' => 35], $object->meta()->rawInput());

        $object = new $className(['name' => 'Albert', 'age' => 35]);
        $object->setAge(40);
        $this->assertSame(['name' => 'Albert', 'age' => 40, 'hookExecuted' => true], $object->meta()->rawInput());

        $source = file_get_contents((new ReflectionClass($className))->getFileName());
        preg_match('/function setAge\(.*?\n    }\n/s', $source, $setAge);
        preg_match('/function setName\(.*?\n    }\n/s', $source, $setName);
        $this->assertStringContainsString('_restoreModelState', $setAge[0]);
        $this->assertStringNotContainsString('_restoreModelState', $setName[0]);
    }

    public static function collectErrorsDataProvider(): array
    {
        return [
            'exception on the first violation' => [false, MinimumException::class],
            'collect errors' => [true, ErrorRegistryException::class],
        ];
    }

    #[DataProvider('setterAfterValidationHookDataProvider')]
    public function testSetterAfterValidationHookIsResolved(
        int $value,
        string $expectedException,
        string $expectedExceptionMessage,
    ): void {
        $this->addSchemaHook(new class () implements SetterAfterValidationHookInterface {
            public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
            {
                return $property->getName() === 'age' ? 'throw new \Exception("SetterAfterValidationHook");' : '';
            }
        });

        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['name' => 'Albert', 'age' => 35]);

        $this->assertSame('Hannes', $object->setName('Hannes')->getName());

        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $object->setAge($value);
    }

    public static function setterAfterValidationHookDataProvider(): array
    {
        return [
            'Invalid value' => [
                -12,
                MinimumException::class,
                "Value for 'age' must not be smaller than 0",
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
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $object = new $className(['name' => 'Albert', 'age' => 35]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("SerializationHookInterface");

        $object->toArray();
    }

    protected function addSchemaHook(SchemaHookInterface $schemaHook): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator) use ($schemaHook): void {
            $modelGenerator->addPostProcessor(new class ($schemaHook) extends PostProcessor {
                public function __construct(private readonly SchemaHookInterface $schemaHook)
                {}

                public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
                {
                    $schema->addSchemaHook($this->schemaHook);
                }
            });
        };
    }
}
