<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PatternPropertiesAccessorPostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use stdClass;

/**
 * Class PatternPropertiesTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class PatternPropertiesTest extends AbstractPHPModelGeneratorTestCase
{
    public function testInvalidPatternThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches("/Invalid pattern 'ab\[c' for pattern property in file .*\.json/");

        $this->generateClassFromFileTemplate('PatternProperty.json', ['ab[c']);
    }

    /**
     * @dataProvider invalidTypedPatternPropertyDataProvider
     */
    public function testTypedPatternPropertyWithInvalidInputThrowsAnException(
        GeneratorConfiguration $configuration,
        $propertyValue,
    ): void {
        $this->expectValidationError(
            $configuration,
            'Invalid type for pattern property. Requires string, got ' .
                (is_object($propertyValue) ? $propertyValue::class : gettype($propertyValue)),
        );

        $className = $this->generateClassFromFile('TypedPatternProperty.json', $configuration);

        new $className(['S_invalid' => $propertyValue]);
    }

    public function invalidTypedPatternPropertyDataProvider(): array
    {
        return $this->combineDataProvider(
            $this->validationMethodDataProvider(),
            [
                'int' => [1],
                'float' => [0.92],
                'bool' => [true],
                'array' => [[]],
                'object' => [new stdClass()],
            ],
        );
    }

    /**
     * @dataProvider validTypedPatternPropertyDataProvider
     */
    public function testTypedPatternPropertyWithValidInputIsValid(string $propertyValue): void
    {
        $className = $this->generateClassFromFile('TypedPatternProperty.json');
        $object = new $className(['S_valid' => $propertyValue]);

        $this->assertSame(['S_valid' => $propertyValue], $object->getRawModelDataInput());
    }

    public function validTypedPatternPropertyDataProvider(): array
    {
        return [
            'empty string' => [''],
            'spaces' => ['    '],
            'only non numeric chars' => ['abc'],
            'mixed string' => ['1234a'],
        ];
    }

    public function testMultipleTransformingFiltersForPatternPropertiesAndObjectPropertyThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage(
            'Applying multiple transforming filters for property alpha is not supported',
        );

        $this->generateClassFromFile('MultipleTransformingFilters.json');
    }

    /**
     * https://github.com/wol-soft/php-json-schema-model-generator/issues/65
     */
    public function testPatternEscaping(): void
    {
        $className = $this->generateClassFromFileTemplate('PatternProperty.json', ['a/(b|c)']);
        $object = new $className(['a/b' => 'Hello']);

        $this->assertSame(['a/b' => 'Hello'], $object->getRawModelDataInput());
    }

    public function testNumericPatternProperties(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PatternPropertiesAccessorPostProcessor(),);
        };

        $className = $this->generateClassFromFileTemplate(
            'PatternProperty.json',
            ['^[0-9]+$'],
            (new GeneratorConfiguration())->setSerialization(true),
        );

        $object = new $className([10 => 'Hello', '12' => 'World']);

        $this->assertSame(['10' => 'Hello', '12' => 'World'], $object->toArray());
        $this->assertSame(['10' => 'Hello', '12' => 'World'], $object->getPatternProperties('^[0-9]+$'));
    }
}
