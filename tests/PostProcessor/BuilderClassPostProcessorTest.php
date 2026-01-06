<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use FilesystemIterator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class BuilderClassPostProcessorTest extends AbstractPHPModelGeneratorTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new BuilderClassPostProcessor());
        };
    }

    public function testBuilder(): void
    {
        $className = $this->generateClassFromFile(
            'BasicSchema.json',
            (new GeneratorConfiguration())->setSerialization(true),
            implicitNull: false,
        );

        $this->assertGeneratedBuilders(1);

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName();

        $this->assertNull($builderObject->getName());
        $this->assertNull($builderObject->getAge());

        $builderObject->setName('Albert');
        $builderObject->setAge(65);

        $this->assertSame('Albert', $builderObject->getName());
        $this->assertSame(65, $builderObject->getAge());
        $this->assertEqualsCanonicalizing(['name' => 'Albert', 'age' => 65], $builderObject->getRawModelDataInput());

        $this->assertSame('string', $this->getParameterTypeAnnotation($builderObject, 'setName'));
        $this->assertSame('int', $this->getParameterTypeAnnotation($builderObject, 'setAge'));
        $this->assertSame('string|null', $this->getReturnTypeAnnotation($builderObject, 'getName'));
        $this->assertSame('int|null', $this->getReturnTypeAnnotation($builderObject, 'getAge'));

        $returnType = $this->getReturnType($builderObject, 'getName');
        $this->assertSame('string', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $returnType = $this->getReturnType($builderObject, 'getAge');
        $this->assertSame('int', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());

        $validatedObject = $builderObject->validate();

        $this->assertInstanceOf($className, $validatedObject);
        $this->assertSame('Albert', $validatedObject->getName());
        $this->assertSame(65, $validatedObject->getAge());
        $this->assertEqualsCanonicalizing(['name' => 'Albert', 'age' => 65], $validatedObject->getRawModelDataInput());
        $this->assertEqualsCanonicalizing(['name' => 'Albert', 'age' => 65], $validatedObject->toArray());
    }

    public function testPopulateBuilderClassViaConstructor(): void
    {
        $className = $this->generateClassFromFile('BasicSchema.json');

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName(['name' => 'Albert']);

        $this->assertSame('Albert', $builderObject->getName());
        $this->assertNull($builderObject->getAge());

        $object = $builderObject->validate();

        $this->assertSame('Albert', $object->getName());
        $this->assertNull($object->getAge());
    }
    /**
     * @dataProvider validationMethodDataProvider
     */
    public function testInvalidBuilderDataThrowsAnExceptionOnValidate(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('BasicSchema.json', $configuration);

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName();

        $builderObject->setName('Al')->setAge(-2);

        $this->expectValidationErrorRegExp(
            $configuration,
            [
                '/Value for name must not be shorter than 5/',
                '/Value for age must not be smaller than 0/'
            ],
        );

        $builderObject->validate();
    }

    public function testImplicitNull(): void
    {
        $className = $this->generateClassFromFile('BasicSchema.json');

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName();

        $this->assertSame('string', $this->getParameterTypeAnnotation($builderObject, 'setName'));
        $this->assertSame('int|null', $this->getParameterTypeAnnotation($builderObject, 'setAge'));
        $this->assertSame('string|null', $this->getReturnTypeAnnotation($builderObject, 'getName'));
        $this->assertSame('int|null', $this->getReturnTypeAnnotation($builderObject, 'getAge'));
    }

    public function testNestedObject(): void
    {
        $files = $this->generateDirectory(
            'NestedObject',
            (new GeneratorConfiguration())
                ->setNamespacePrefix('MyApp\\Namespace\\')
                ->setOutputEnabled(false)
                ->setImplicitNull(true),
        );

        $this->assertCount(3, $files);
        $this->assertGeneratedBuilders(3);

        $builderClassName = 'MyApp\Namespace\NestedObjectBuilder';
        $builderObject = new $builderClassName();

        $expectedTypeHint = "Address|AddressBuilder|array|null";
        $this->assertSame($expectedTypeHint, $this->getParameterTypeAnnotation($builderObject, 'setAddress'));
        $this->assertSame($expectedTypeHint, $this->getReturnTypeAnnotation($builderObject, 'getAddress'));

        // test generate nested object from array
        $addressArray = ['street' => 'Test street', 'number' => 10, 'building' => ['type' => 'private', 'size' => 160]];
        $builderObject->setAddress($addressArray);
        $this->assertSame($addressArray, $builderObject->getAddress());
        $this->assertSame(['address' => $addressArray], $builderObject->getRawModelDataInput());
        $object = $builderObject->validate();
        $this->assertSame('Test street', $object->getAddress()->getStreet());
        $this->assertSame(10, $object->getAddress()->getNumber());
        $this->assertSame('private', $object->getAddress()->getBuilding()->getType());
        $this->assertSame(160, $object->getAddress()->getBuilding()->getSize());

        // test generate nested object from nested builder
        $addressBuilderClassName = 'MyApp\Namespace\Dependencies\AddressBuilder';
        $addressBuilderObject = new $addressBuilderClassName();
        $this->assertSame('string|null', $this->getParameterTypeAnnotation($addressBuilderObject, 'setStreet'));
        $this->assertSame('int|null', $this->getParameterTypeAnnotation($addressBuilderObject, 'setNumber'));
        $this->assertSame('Address_Building|Address_BuildingBuilder|array|null', $this->getParameterTypeAnnotation($addressBuilderObject, 'setBuilding'));
        $this->assertSame('string|null', $this->getReturnTypeAnnotation($addressBuilderObject, 'getStreet'));
        $this->assertSame('int|null', $this->getReturnTypeAnnotation($addressBuilderObject, 'getNumber'));
        $this->assertSame('Address_Building|Address_BuildingBuilder|array|null', $this->getReturnTypeAnnotation($addressBuilderObject, 'getBuilding'));

        $buildingBuilderClassName = 'MyApp\Namespace\Dependencies\Address_BuildingBuilder';
        $buildingBuilderObject = new $buildingBuilderClassName();
        $this->assertSame('string|null', $this->getParameterTypeAnnotation($buildingBuilderObject, 'setType'));
        $this->assertSame('int|null', $this->getParameterTypeAnnotation($buildingBuilderObject, 'setSize'));
        $this->assertSame('string|null', $this->getReturnTypeAnnotation($buildingBuilderObject, 'getType'));
        $this->assertSame('int|null', $this->getReturnTypeAnnotation($buildingBuilderObject, 'getSize'));

        $buildingBuilderObject->setType('private')->setSize(160);

        $addressBuilderObject->setStreet('Test street')->setNumber(10)->setBuilding($buildingBuilderObject);
        $this->assertSame($addressArray['building'], $addressBuilderObject->getBuilding()->getRawModelDataInput());
        $this->assertSame($addressArray['street'], $addressBuilderObject->getRawModelDataInput()['street']);
        $this->assertSame($addressArray['number'], $addressBuilderObject->getRawModelDataInput()['number']);
        $builderObject->setAddress($addressBuilderObject);
        $this->assertSame($addressBuilderObject, $builderObject->getAddress());
        $object = $builderObject->validate();
        $this->assertSame('Test street', $object->getAddress()->getStreet());
        $this->assertSame(10, $object->getAddress()->getNumber());
        $this->assertSame('private', $object->getAddress()->getBuilding()->getType());
        $this->assertSame(160, $object->getAddress()->getBuilding()->getSize());

        // test add validated object
        $addressObjectClassName =  'MyApp\Namespace\Dependencies\Address';
        $buildingObjectClassName =  'MyApp\Namespace\Dependencies\Address_Building';
        $addressArray['building'] = new $buildingObjectClassName($addressArray['building']);
        $addressObject = new $addressObjectClassName($addressArray);
        $builderObject->setAddress($addressObject);
        $this->assertSame($addressObject, $builderObject->getAddress());
        $object = $builderObject->validate();
        $this->assertSame('Test street', $object->getAddress()->getStreet());
        $this->assertSame(10, $object->getAddress()->getNumber());
        $this->assertSame('private', $object->getAddress()->getBuilding()->getType());
        $this->assertSame(160, $object->getAddress()->getBuilding()->getSize());

        // check if the nested objects from a different namespace are correctly imported
        $mainFileContent = file_get_contents(str_replace('.php', 'Builder.php', $files[2]));
        $this->assertStringContainsString("use $addressObjectClassName;", $mainFileContent);
        $this->assertStringContainsString("use $addressBuilderClassName;", $mainFileContent);
    }

    public function testNestedObjectArray(): void
    {
        $className = $this->generateClassFromFile('NestedObjectArray.json');

        $this->assertGeneratedBuilders(2);

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName();

        $nestedObjectClassName = null;
        foreach ($this->getGeneratedFiles() as $file) {
            if (str_contains($file, 'ItemOfArray')) {
                $nestedObjectClassName = str_replace('.php', '', basename($file));

                break;
            }
        }

        $nestedBuilderClassName = $nestedObjectClassName . 'Builder';

        $this->assertNotEmpty($nestedObjectClassName);
        $expectedTypeHint = "{$nestedObjectClassName}[]|{$nestedBuilderClassName}[]|array|null";
        $this->assertSame($expectedTypeHint, $this->getParameterTypeAnnotation($builderObject, 'setAddressList'));
        $this->assertSame($expectedTypeHint, $this->getReturnTypeAnnotation($builderObject, 'getAddressList'));

        $builderObject->setAddressList([
            ['street' => 'Test street 0', 'number' => 10],
            (new $nestedBuilderClassName())->setStreet('Test street 1')->setNumber(11),
            new $nestedObjectClassName(['street' => 'Test street 2',  'number' => 12]),
        ]);

        $object = $builderObject->validate();

        $this->assertCount(3, $object->getAddressList());

        for ($i = 0; $i <= 2; $i++) {
            $this->assertInstanceOf($nestedObjectClassName, $object->getAddressList()[$i]);
            $this->assertSame("Test street {$i}", $object->getAddressList()[$i]->getStreet());
            $this->assertSame(10 + $i, $object->getAddressList()[$i]->getNumber());
        }
    }

    private function assertGeneratedBuilders(int $expectedGeneratedBuilders): void
    {
        $dir = sys_get_temp_dir() . '/PHPModelGeneratorTest/Models';

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Builder.php')) {
                $files[] = $file->getPathname();
            }
        }

        $this->assertCount($expectedGeneratedBuilders, $files);
    }
}
