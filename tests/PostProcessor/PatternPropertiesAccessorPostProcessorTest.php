<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\PostProcessor;

use Exception;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Object\UnknownPatternPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PatternPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class PatternPropertiesAccessorPostProcessorTest
 *
 * @package PHPModelGenerator\Tests\PostProcessor
 */
class PatternPropertiesAccessorPostProcessorTest extends AbstractPHPModelGeneratorTest
{
    protected function addPostProcessors(PostProcessor ...$postProcessors): void
    {
        $this->modifyModelGenerator = function (ModelGenerator $generator) use ($postProcessors): void {
            foreach ($postProcessors as $postProcessor) {
                $generator->addPostProcessor($postProcessor);
            }
        };
    }

    public function testPatternPropertiesAccessorMethodIsNotGeneratedForObjectsWithoutPatternProperties(): void
    {
        $this->addPostProcessors(new PatternPropertiesAccessorPostProcessor());
        $className = $this->generateClassFromFile('PatternPropertiesNotDefined.json');

        $object = new $className();

        $this->assertFalse(is_callable([$object, 'getPatternProperties']));
    }

    public function testDuplicatePatternPropertiesAccessKeyThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches("/Duplicate pattern property access key 'Numerics' in file .*\.json/");

        $this->addPostProcessors(new PatternPropertiesAccessorPostProcessor());
        $this->generateClassFromFile('DuplicateKey.json');
    }

    public function testAccessingPatternProperties(): void
    {
        $this->addPostProcessors(new PatternPropertiesAccessorPostProcessor());
        $className = $this->generateClassFromFile('PatternProperties.json');

        $object = new $className(['a1' => 0, 'a2' => 10, 'b1' => 'Hello', 'b2' => 'World']);

        // test accessing the pattern properties via a defined key
        $this->assertEqualsCanonicalizing(
            ['alpha' => null, 'a1' => 0, 'a2' => 10],
            $object->getPatternProperties('Numerics')
        );
        // test accessing the pattern properties via the RegEx
        $this->assertEqualsCanonicalizing(
            ['beta' => null, 'b1' => 'Hello', 'b2' => 'World'],
            $object->getPatternProperties('^b')
        );
    }

    /**
     * @dataProvider invalidPatternPropertyKeysDataProvider
     */
    public function testAccessingNonExistingPatternProperties(string $key): void
    {
        $this->expectException(UnknownPatternPropertyException::class);

        $this->addPostProcessors(new PatternPropertiesAccessorPostProcessor());
        $className = $this->generateClassFromFile('PatternProperties.json');

        $object = new $className();

        $object->getPatternProperties($key);
    }

    public function invalidPatternPropertyKeysDataProvider(): array
    {
        return [
            'Access Pattern with key via RegEx' => ['^a'],
            'Access non existing pattern' => ['^c'],
            'Access non existing key' => ['StringProperties'],
            'Empty key' => [''],
        ];
    }

    public function testModifyingPatternPropertiesViaAdditionalPropertiesAccessor(): void
    {
        $this->addPostProcessors(
            new PatternPropertiesAccessorPostProcessor(),
            new AdditionalPropertiesAccessorPostProcessor(true)
        );

        $className = $this->generateClassFromFile(
            'PatternProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['a0' => 100]);

        $this->assertEqualsCanonicalizing(['alpha' => null, 'a0' => 100], $object->getPatternProperties('Numerics'));
        $this->assertEqualsCanonicalizing(['beta' => null], $object->getPatternProperties('^b'));
        $this->assertSame([], $object->getAdditionalProperties());

        $object->setAdditionalProperty('b0', 'Hello');
        $this->assertEqualsCanonicalizing(['alpha' => null, 'a0' => 100], $object->getPatternProperties('Numerics'));
        $this->assertEqualsCanonicalizing(['beta' => null, 'b0' => 'Hello'], $object->getPatternProperties('^b'));
        $this->assertSame([], $object->getAdditionalProperties());

        $object->setAdditionalProperty('c0', false);
        $this->assertEqualsCanonicalizing(['alpha' => null, 'a0' => 100], $object->getPatternProperties('Numerics'));
        $this->assertSame(['beta' => null, 'b0' => 'Hello'], $object->getPatternProperties('^b'));
        $this->assertSame(['c0' => false], $object->getAdditionalProperties());

        $object->setAdditionalProperty('a0', 10);
        $this->assertEqualsCanonicalizing(['alpha' => null, 'a0' => 10], $object->getPatternProperties('Numerics'));
        $this->assertEqualsCanonicalizing(['beta' => null, 'b0' => 'Hello'], $object->getPatternProperties('^b'));
        $this->assertSame(['c0' => false], $object->getAdditionalProperties());

        $object->removeAdditionalProperty('a0');
        $this->assertEqualsCanonicalizing(['alpha' => null], $object->getPatternProperties('Numerics'));
        $this->assertEqualsCanonicalizing(['beta' => null, 'b0' => 'Hello'], $object->getPatternProperties('^b'));
        $this->assertSame(['c0' => false], $object->getAdditionalProperties());
    }

    /**
     * @dataProvider invalidPatternPropertiesDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string $property
     * @param $value
     * @param string $exceptionMessage
     */
    public function testInvalidPatternPropertiesViaAdditionalPropertiesAccessorThrowsAnException(
        GeneratorConfiguration $configuration,
        string $property,
        $value,
        string $exceptionMessage
    ): void {
        $this->addPostProcessors(
            new PatternPropertiesAccessorPostProcessor(),
            new AdditionalPropertiesAccessorPostProcessor(true)
        );

        $className = $this->generateClassFromFile('PatternProperties.json', $configuration->setImmutable(false));

        $object = new $className(['a0' => 100, 'b0' => 'Hello']);

        try {
            $object->setAdditionalProperty($property, $value);
            $this->fail('Expected exception not thrown');
        } catch (Exception $exception) {
            if ($configuration->collectErrors()) {
                $this->assertInstanceOf(ErrorRegistryException::class, $exception);
            } else {
                $this->assertInstanceOf(ValidationException::class, $exception);
            }

            $this->assertRegExp("/$exceptionMessage/", $exception->getMessage());
        }

        $this->assertEqualsCanonicalizing(['alpha' => null, 'a0' => 100], $object->getPatternProperties('Numerics'));
        $this->assertSame('Hello', $object->getPatternProperties('^b')['b0']);

        // TODO: bug: value gets updated
        // $this->assertEqualsCanonicalizing(['a0' => 100, 'b0' => 'Hello'], $object->getAdditionalProperties());
        // $this->assertEqualsCanonicalizing(['a0' => 100, 'b0' => 'Hello'], $object->getRawModelDataInput());
    }

    public function invalidPatternPropertiesDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'invalid type for integer properties' => [
                    'a0',
                    'Hello',
                    <<<ERROR
Provided JSON for PatternPropertiesAccessorPostProcessorTest.* contains invalid pattern properties.
  - invalid property 'a0' matching pattern '\^a'
    \* Invalid type for pattern property. Requires int, got string
ERROR
                ],
                'invalid type for string properties' => [
                    'b0',
                    100,
                    <<<ERROR
Provided JSON for PatternPropertiesAccessorPostProcessorTest.* contains invalid pattern properties.
  - invalid property 'b0' matching pattern '\^b'
    \* Invalid type for pattern property. Requires string, got int
ERROR
                ],
            ]
        );
    }

    public function testModifyingPatternPropertiesViaPopulate(): void
    {
        $this->addPostProcessors(new PatternPropertiesAccessorPostProcessor(), new PopulatePostProcessor());
        $className = $this->generateClassFromFile('PatternProperties.json');

        $object = new $className(['a0' => 100]);
        $this->assertEqualsCanonicalizing(['alpha' => null, 'a0' => 100], $object->getPatternProperties('Numerics'));
        $this->assertEqualsCanonicalizing(['beta' => null], $object->getPatternProperties('^b'));

        $object->populate(['a1' => 0, 'a2' => 10, 'b1' => 'Hello', 'c1' => 'World']);
        $this->assertEqualsCanonicalizing(
            ['alpha' => null, 'a0' => 100, 'a1' => 0, 'a2' => 10],
            $object->getPatternProperties('Numerics')
        );
        $this->assertEqualsCanonicalizing(['beta' => null, 'b1' => 'Hello'], $object->getPatternProperties('^b'));

        $object->populate(['a1' => -10, 'b2' => 'World']);
        $this->assertEqualsCanonicalizing(
            ['alpha' => null, 'a0' => 100, 'a1' => -10, 'a2' => 10],
            $object->getPatternProperties('Numerics')
        );
        $this->assertEqualsCanonicalizing(
            ['beta' => null, 'b1' => 'Hello', 'b2' => 'World'],
            $object->getPatternProperties('^b')
        );
    }

    /**
     * @dataProvider invalidPatternPropertiesDataProvider
     *
     * @param GeneratorConfiguration $configuration
     * @param string $property
     * @param $value
     * @param string $exceptionMessage
     */
    public function testInvalidPatternPropertiesViaPopulateThrowsAnException(
        GeneratorConfiguration $configuration,
        string $property,
        $value,
        string $exceptionMessage
    ): void {
        $this->addPostProcessors(new PatternPropertiesAccessorPostProcessor(), new PopulatePostProcessor());

        $className = $this->generateClassFromFile('PatternProperties.json', $configuration->setImmutable(false));

        $object = new $className(['a0' => 100, 'b0' => 'Hello']);

        try {
            $object->populate([$property => $value]);
            $this->fail('Expected exception not thrown');
        } catch (Exception $exception) {
            if ($configuration->collectErrors()) {
                $this->assertInstanceOf(ErrorRegistryException::class, $exception);
            } else {
                $this->assertInstanceOf(ValidationException::class, $exception);
            }

            $this->assertRegExp("/$exceptionMessage/", $exception->getMessage());
        }

        $this->assertEqualsCanonicalizing(['alpha' => null, 'a0' => 100], $object->getPatternProperties('Numerics'));
        $this->assertEqualsCanonicalizing(['beta' => null, 'b0' => 'Hello'], $object->getPatternProperties('^b'));
        $this->assertEqualsCanonicalizing(['a0' => 100, 'b0' => 'Hello'], $object->getRawModelDataInput());
    }

    public function testModifyingPatternPropertiesViaSetter(): void
    {
        $this->addPostProcessors(new PatternPropertiesAccessorPostProcessor());

        $className = $this->generateClassFromFile(
            'PatternProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['a0' => 100]);

        $object->setAlpha(20);
        $this->assertEqualsCanonicalizing(['alpha' => 20, 'a0' => 100], $object->getPatternProperties('Numerics'));
        $this->assertEqualsCanonicalizing(['alpha' => 20, 'a0' => 100], $object->getRawModelDataInput());
        $this->assertSame(['beta' => null], $object->getPatternProperties('^b'));

        $object->setBeta('abcde');
        $this->assertEqualsCanonicalizing(['alpha' => 20, 'a0' => 100], $object->getPatternProperties('Numerics'));
        $this->assertEqualsCanonicalizing(
            ['alpha' => 20, 'a0' => 100, 'beta' => 'abcde'],
            $object->getRawModelDataInput()
        );
        $this->assertSame(['beta' => 'abcde'], $object->getPatternProperties('^b'));

        $this->assertSame(20, $object->getAlpha());
        $this->assertSame('abcde', $object->getBeta());
    }

    /**
     * @dataProvider invalidPropertiesDataProvider
     *
     * @param string $property
     * @param $value
     * @param string $exceptionMessage
     */
    public function testInvalidPatternPropertiesViaSetterThrowsAnException(
        string $property,
        $value,
        string $exceptionMessage
    ): void {
        $this->addPostProcessors(new PatternPropertiesAccessorPostProcessor());

        $className = $this->generateClassFromFile(
            'PatternProperties.json',
            (new GeneratorConfiguration())->setImmutable(false)
        );

        $object = new $className(['alpha' => 20, 'beta' => 'abcde']);

        try {
            $setter = 'set' . ucfirst($property);
            $object->$setter($value);

            $this->fail('Expected exception not thrown');
        } catch (ErrorRegistryException $exception) {
            $this->assertRegExp("/$exceptionMessage/", $exception->getMessage());
        }

        $this->assertSame(['alpha' => 20], $object->getPatternProperties('Numerics'));
        $this->assertSame(['beta' => 'abcde'], $object->getPatternProperties('^b'));
        $this->assertEqualsCanonicalizing(['alpha' => 20, 'beta' => 'abcde'], $object->getRawModelDataInput());

        $this->assertSame(20, $object->getAlpha());
        $this->assertSame('abcde', $object->getBeta());
    }

    public function invalidPropertiesDataProvider(): array
    {
        return [
            'value declined by property constraint' => [
                'alpha',
                0,
                'Value for alpha must not be smaller than 10'
            ],
            'value declined by pattern property constraint' => [
                'alpha',
                15,
                <<<ERROR
Provided JSON for PatternPropertiesAccessorPostProcessorTest.* contains invalid pattern properties.
  - invalid property 'alpha' matching pattern '\^a'
    \* Value for pattern property must be a multiple of 10
ERROR
            ],
            'Value declined by property and pattern property constraint' => [
                'alpha',
                5,
                <<<ERROR
Provided JSON for PatternPropertiesAccessorPostProcessorTest.* contains invalid pattern properties.
  - invalid property 'alpha' matching pattern '\^a'
    \* Value for pattern property must be a multiple of 10
Value for alpha must not be smaller than 10
ERROR
            ],
        ];
    }
}
