<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use Exception;
use PHPModelGenerator\Exception\ErrorRegistryException;
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
use PHPModelGenerator\SchemaProcessor\Hook\SetterRollbackHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /** @var int[] The values which the rollback hook restored */
    public static array $restoredAges = [];

    #[DataProvider('collectErrorsDataProvider')]
    public function testSetterRollbackHookRestoresTheStateOnlyIfTheUpdateIsRejected(
        bool $collectErrors,
        string $expectedException,
    ): void {
        self::$restoredAges = [];

        $this->addSchemaHook(new class () implements SetterRollbackHookInterface {
            public function getBackupCode(PropertyInterface $property): string
            {
                return $property->getName() === 'age' ? '$previousAge = $this->age;' : '';
            }

            public function getRestoreCode(PropertyInterface $property): string
            {
                return $property->getName() === 'age'
                    ? '\\' . SchemaHookTest::class . '::$restoredAges[] = $previousAge;'
                    : '';
            }
        });

        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors($collectErrors),
        );

        $object = new $className(['name' => 'Albert', 'age' => 35]);

        // An accepted update and updates of other properties don't restore anything.
        $this->assertSame(40, $object->setAge(40)->getAge());
        $this->assertSame('Hannes', $object->setName('Hannes')->getName());
        $this->assertSame([], self::$restoredAges);

        // The rejected update restores the state and the exception is passed on unchanged.
        try {
            $object->setAge(-12);
            $this->fail('Expected the minimum violation of age');
        } catch (Exception $exception) {
            $this->assertInstanceOf($expectedException, $exception);
        }

        $this->assertSame([40], self::$restoredAges);
        $this->assertSame(40, $object->getAge());
    }

    public static function collectErrorsDataProvider(): array
    {
        return [
            'exception on the first violation' => [false, MinimumException::class],
            'collect errors' => [true, ErrorRegistryException::class],
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
