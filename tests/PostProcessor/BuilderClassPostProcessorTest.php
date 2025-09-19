<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use FilesystemIterator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
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

        $this->assertCount(2, $files);
        $this->assertGeneratedBuilders(2);

        $builderClassName = 'MyApp\Namespace\NestedObjectBuilder';
        $builderObject = new $builderClassName();

        $expectedTypeHint = "Address|AddressBuilder|array|null";
        $this->assertSame($expectedTypeHint, $this->getParameterTypeAnnotation($builderObject, 'setAddress'));
        $this->assertSame($expectedTypeHint, $this->getReturnTypeAnnotation($builderObject, 'getAddress'));

        // test generate nested object from array
        $addressArray = ['street' => 'Test street', 'number' => 10];
        $builderObject->setAddress($addressArray);
        $this->assertSame($addressArray, $builderObject->getAddress());
        $this->assertSame(['address' => $addressArray], $builderObject->getRawModelDataInput());
        $object = $builderObject->validate();
        $this->assertSame('Test street', $object->getAddress()->getStreet());
        $this->assertSame(10, $object->getAddress()->getNumber());

        // test generate nested object from nested builder
        $nestedBuilderClassName = 'MyApp\Namespace\Dependencies\AddressBuilder';
        $nestedBuilderObject = new $nestedBuilderClassName();
        $this->assertSame('string|null', $this->getParameterTypeAnnotation($nestedBuilderObject, 'setStreet'));
        $this->assertSame('int|null', $this->getParameterTypeAnnotation($nestedBuilderObject, 'setNumber'));
        $this->assertSame('string|null', $this->getReturnTypeAnnotation($nestedBuilderObject, 'getStreet'));
        $this->assertSame('int|null', $this->getReturnTypeAnnotation($nestedBuilderObject, 'getNumber'));

        $nestedBuilderObject->setStreet('Test street')->setNumber(10);
        $this->assertSame($addressArray, $nestedBuilderObject->getRawModelDataInput());
        $builderObject->setAddress($nestedBuilderObject);
        $this->assertSame($nestedBuilderObject, $builderObject->getAddress());
        $object = $builderObject->validate();
        $this->assertSame('Test street', $object->getAddress()->getStreet());
        $this->assertSame(10, $object->getAddress()->getNumber());

        // test add validated object
        $nestedObjectClassName =  'MyApp\Namespace\Dependencies\Address';
        $nestedObject = new $nestedObjectClassName($addressArray);
        $builderObject->setAddress($nestedObject);
        $this->assertSame($nestedObject, $builderObject->getAddress());
        $object = $builderObject->validate();
        $this->assertSame('Test street', $object->getAddress()->getStreet());
        $this->assertSame(10, $object->getAddress()->getNumber());

        // check if the nested objects from a different namespace are correctly imported
        $mainFileContent = file_get_contents(str_replace('.php', 'Builder.php', $files[1]));
        $this->assertStringContainsString("use $nestedObjectClassName;", $mainFileContent);
        $this->assertStringContainsString("use $nestedBuilderClassName;", $mainFileContent);
    }

    public function testNestedObjectArray(): void
    {
        $className = $this->generateClassFromFile('NestedObjectArray.json');

        $this->assertGeneratedBuilders(2);

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName();

        $nestedObjectClassName = null;
        foreach ($this->getGeneratedFiles() as $file) {
            if (str_contains($file, 'Itemofarray')) {
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

    public function testEnum(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new BuilderClassPostProcessor())->addPostProcessor(new EnumPostProcessor());
        };
        $className = $this->generateClassFromFile('BasicSchema.json');

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName();

        $this->assertSame('string', $this->getParameterTypeAnnotation($builderObject, 'setName'));
        $this->assertSame('int|null', $this->getParameterTypeAnnotation($builderObject, 'setAge'));
        $this->assertSame('string|null', $this->getReturnTypeAnnotation($builderObject, 'getName'));
        $this->assertSame('int|null', $this->getReturnTypeAnnotation($builderObject, 'getAge'));
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
