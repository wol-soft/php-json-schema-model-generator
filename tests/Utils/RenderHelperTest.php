<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Utils;

use Exception;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Utils\RenderHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RenderHelperTest extends TestCase
{
    public function testUcfirst(): void
    {
        $renderHelper = new RenderHelper(new GeneratorConfiguration());

        $this->assertSame('Hallo', $renderHelper->ucfirst('Hallo'));
        $this->assertSame('Hallo', $renderHelper->ucfirst('hallo'));
    }

    public function testGetSimpleClassName(): void
    {
        $renderHelper = new RenderHelper(new GeneratorConfiguration());

        $this->assertSame('RenderHelper', $renderHelper->getSimpleClassName(RenderHelper::class));
        $this->assertSame('Exception', $renderHelper->getSimpleClassName(Exception::class));
    }

    /**
     * PHP rejects "?null" as a type declaration: the null type already contains null, so a property
     * which only accepts null never renders the nullable shorthand (whatever makes the property
     * nullable), while the shorthand is still rendered for every other single type.
     */
    #[DataProvider('nullablePropertyDataProvider')]
    public function testGetTypeRendersNullOnlyTypeWithoutNullableShorthand(
        bool $implicitNull,
        bool $required,
        bool $outputType,
        bool $forceNullable,
        ?bool $explicitNullable,
    ): void {
        $renderHelper = new RenderHelper((new GeneratorConfiguration())->setImplicitNull($implicitNull));

        $nullProperty = (new Property('nullOnly', new PropertyType('null', $explicitNullable), new JsonSchema('', [])))
            ->setRequired($required);
        $this->assertSame('null', $renderHelper->getType($nullProperty, $outputType, $forceNullable));

        // The shorthand of any other single type depends on the nullability of the property
        $stringProperty = (
            new Property('string', new PropertyType('string', $explicitNullable), new JsonSchema('', []))
        )->setRequired($required);
        $nullable = $explicitNullable ?? (!$required && ($implicitNull || $outputType));

        $this->assertSame(
            ($nullable || $forceNullable ? '?' : '') . 'string',
            $renderHelper->getType($stringProperty, $outputType, $forceNullable),
        );
    }

    public static function nullablePropertyDataProvider(): array
    {
        $cases = [];

        foreach ([true, false] as $implicitNull) {
            foreach ([true, false] as $required) {
                foreach ([true, false] as $outputType) {
                    foreach ([true, false] as $forceNullable) {
                        foreach ([null, true, false] as $explicitNullable) {
                            $cases[sprintf(
                                'implicit null %s, %s, %s type, %s, explicit nullability %s',
                                $implicitNull ? 'on' : 'off',
                                $required ? 'required' : 'optional',
                                $outputType ? 'output' : 'input',
                                $forceNullable ? 'forced nullable' : 'not forced',
                                var_export($explicitNullable, true),
                            )] = [$implicitNull, $required, $outputType, $forceNullable, $explicitNullable];
                        }
                    }
                }
            }
        }

        return $cases;
    }

    public function testGetTypeHintAnnotationOfNullOnlyTypeContainsNullOnce(): void
    {
        $renderHelper = new RenderHelper(new GeneratorConfiguration());

        $nullProperty = new Property('nullOnly', new PropertyType('null'), new JsonSchema('', []));
        $nullProperty->setRequired(false);

        $this->assertSame('null', $renderHelper->getTypeHintAnnotation($nullProperty));
        $this->assertSame('null', $renderHelper->getTypeHintAnnotation($nullProperty, true, true));
    }
}
