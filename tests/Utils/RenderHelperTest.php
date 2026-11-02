<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Utils;

use Exception;
use PHPModelGenerator\Exception\Object\MinPropertiesException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\PropertyValidator;
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

    /**
     * The validators of the base property validate the generated class itself, the name of the property which their
     * exceptions receive is the name of the class (a label for the message text, no location inside of the data). The
     * exception is marked so the name doesn't become a segment of the instance pointer. The validators of any other
     * property validate a value of the data under the real name of the property.
     */
    #[DataProvider('collectErrorsDataProvider')]
    public function testValidationErrorMarksTheExceptionOfAValidatorOfTheGeneratedClass(bool $collectErrors): void
    {
        $renderHelper = new RenderHelper((new GeneratorConfiguration())->setCollectErrors($collectErrors));

        $baseValidator = new PropertyValidator(
            new BaseProperty('Person', new PropertyType('object'), new JsonSchema('', [])),
            '$amount < 2',
            MinPropertiesException::class,
            [2],
        );
        $constructor = '(new \\PHPModelGenerator\\Exception\\Object\\MinPropertiesException'
            . "(\$value ?? null, ...['Person', '', 2]))->withPlaceholderPropertyName()";

        $this->assertSame(
            $collectErrors ? "\$this->_errorRegistry->addError($constructor);" : "throw $constructor;",
            $renderHelper->validationError($baseValidator),
        );

        $propertyValidator = new PropertyValidator(
            new Property('name', new PropertyType('string'), new JsonSchema('', [])),
            '$amount < 2',
            MinPropertiesException::class,
            [2],
        );
        $constructor = "new \\PHPModelGenerator\\Exception\\Object\\MinPropertiesException"
            . "(\$value ?? null, ...['name', '', 2])";

        $this->assertSame(
            $collectErrors ? "\$this->_errorRegistry->addError($constructor);" : "throw $constructor;",
            $renderHelper->validationError($propertyValidator),
        );
    }

    /**
     * An exception class of a custom validator which doesn't extend the ValidationException of the library has no
     * instance pointer and doesn't provide the method of the marker.
     */
    public function testValidationErrorDoesNotMarkAnExceptionWhichIsNoValidationException(): void
    {
        $renderHelper = new RenderHelper((new GeneratorConfiguration())->setCollectErrors(false));

        $validator = new PropertyValidator(
            new BaseProperty('Person', new PropertyType('object'), new JsonSchema('', [])),
            'true',
            Exception::class,
            [],
        );

        $this->assertSame(
            "throw new \\Exception(\$value ?? null, ...['Person', '']);",
            $renderHelper->validationError($validator),
        );
    }

    /**
     * A validator which provides a value of more than one line (the data of a model) is rendered with the arguments
     * on lines of their own, also if the exception is marked.
     */
    public function testValidationErrorMarksTheExceptionIfTheProvidedValueSpansMultipleLines(): void
    {
        $renderHelper = new RenderHelper((new GeneratorConfiguration())->setCollectErrors(false));

        $validator = new PropertyValidator(
            new BaseProperty('Person', new PropertyType('object'), new JsonSchema('', [])),
            'true',
            MinPropertiesException::class,
            [2],
            "array_values(\n    \$value\n)",
        );

        $this->assertSame(
            <<<'CODE'
            throw (new \PHPModelGenerator\Exception\Object\MinPropertiesException(
                array_values(
                    $value
                ),
                ...['Person', '', 2]
            ))->withPlaceholderPropertyName();
            CODE,
            $renderHelper->validationError($validator),
        );
    }

    public static function collectErrorsDataProvider(): array
    {
        return [
            'direct exception' => [false],
            'collect errors' => [true],
        ];
    }
}
