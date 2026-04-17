<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Draft\Modifier;

use PHPModelGenerator\Draft\Element\Type;
use PHPModelGenerator\Draft\Modifier\TypeCheckModifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\TypeCheckInterface;
use PHPModelGenerator\Model\Validator\TypeCheckValidator;
use PHPModelGenerator\SchemaProcessor\RenderQueue;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;
use PHPUnit\Framework\TestCase;

class TypeCheckModifierTest extends TestCase
{
    private SchemaProcessor $schemaProcessor;
    private Schema $schema;

    protected function setUp(): void
    {
        $this->schemaProcessor = new SchemaProcessor(
            new RecursiveDirectoryProvider(__DIR__),
            '',
            new GeneratorConfiguration(),
            new RenderQueue(),
        );

        $this->schema = new Schema('', '', '', new JsonSchema('', []));
    }

    public function testAddsTypeCheckValidatorForType(): void
    {
        $property = new Property('name', new PropertyType('string'), new JsonSchema('', ['type' => 'string']));
        $property->setRequired(true);

        $modifier = new TypeCheckModifier('string');
        $modifier->modify($this->schemaProcessor, $this->schema, $property, new JsonSchema('', ['type' => 'string']));

        $typeCheckValidators = array_filter(
            $property->getValidators(),
            static fn($v) => $v->getValidator() instanceof TypeCheckInterface,
        );

        $this->assertCount(1, $typeCheckValidators);
        $this->assertContains('string', reset($typeCheckValidators)->getValidator()->getTypes());
    }

    public function testDoesNotAddDuplicateTypeCheckValidator(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'string']);
        $property = new Property('name', new PropertyType('string'), $jsonSchema);
        $property->setRequired(true);

        // Add a TypeCheckValidator manually first
        $property->addValidator(new TypeCheckValidator('string', $property, false), 2);

        $modifier = new TypeCheckModifier('string');
        $modifier->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $typeCheckValidators = array_filter(
            $property->getValidators(),
            static fn($v) => $v->getValidator() instanceof TypeCheckInterface,
        );

        $this->assertCount(1, $typeCheckValidators);
    }

    public function testDoesNotSkipWhenExistingValidatorIsForDifferentType(): void
    {
        $jsonSchema = new JsonSchema('', ['type' => 'integer']);
        $property = new Property('name', new PropertyType('int'), $jsonSchema);
        $property->setRequired(true);

        // Add a TypeCheckValidator for a different type
        $property->addValidator(new TypeCheckValidator('string', $property, false), 2);

        $modifier = new TypeCheckModifier('int');
        $modifier->modify($this->schemaProcessor, $this->schema, $property, $jsonSchema);

        $typeCheckValidators = array_filter(
            $property->getValidators(),
            static fn($v) => $v->getValidator() instanceof TypeCheckInterface,
        );

        $this->assertCount(2, $typeCheckValidators);
    }

    // --- Type auto-registration ---

    #[DataProvider('typeCheckAutoRegistrationProvider')]
    public function testTypeAutoRegistersTypeCheckModifier(string $jsonType, string $phpType): void
    {
        $type = new Type($jsonType);

        $modifiers = $type->getModifiers();
        $this->assertCount(1, $modifiers);
        $this->assertInstanceOf(TypeCheckModifier::class, $modifiers[0]);

        // Verify the modifier adds a validator for the correct PHP type
        $property = new Property('x', new PropertyType($phpType), new JsonSchema('', ['type' => $jsonType]));
        $property->setRequired(true);
        $schema = new Schema('', '', '', new JsonSchema('', []));
        $schemaProcessor = new SchemaProcessor(
            new RecursiveDirectoryProvider(__DIR__),
            '',
            new GeneratorConfiguration(),
            new RenderQueue(),
        );

        $modifiers[0]->modify($schemaProcessor, $schema, $property, new JsonSchema('', ['type' => $jsonType]));

        $typeCheckValidators = array_filter(
            $property->getValidators(),
            static fn($v) => $v->getValidator() instanceof TypeCheckInterface,
        );
        $this->assertCount(1, $typeCheckValidators);
        $this->assertContains($phpType, reset($typeCheckValidators)->getValidator()->getTypes());
    }

    public static function typeCheckAutoRegistrationProvider(): array
    {
        return [
            'array'   => ['array',   'array'],
            'string'  => ['string',  'string'],
            'integer' => ['integer', 'int'],
            'number'  => ['number',  'float'],
            'boolean' => ['boolean', 'bool'],
            'null'    => ['null',    'null'],
        ];
    }

    public function testTypeWithTypeCheckFalseRegistersNoModifiers(): void
    {
        $type = new Type('object', false);
        $this->assertEmpty($type->getModifiers());
    }

    public function testAllowsImplicitNullForOptionalProperty(): void
    {
        $config = (new GeneratorConfiguration())->setImplicitNull(true);
        $schemaProcessor = new SchemaProcessor(
            new RecursiveDirectoryProvider(__DIR__),
            '',
            $config,
            new RenderQueue(),
        );

        $jsonSchema = new JsonSchema('', ['type' => 'string']);
        $property = new Property('name', new PropertyType('string'), $jsonSchema);
        $property->setRequired(false);

        $modifier = new TypeCheckModifier('string');
        $modifier->modify($schemaProcessor, $this->schema, $property, $jsonSchema);

        $validators = array_values(array_filter(
            $property->getValidators(),
            static fn($v) => $v->getValidator() instanceof TypeCheckInterface,
        ));

        $this->assertCount(1, $validators);
        // The check should allow $value === null for an optional property with implicit null enabled
        $this->assertStringContainsString('null', $validators[0]->getValidator()->getCheck());
    }
}
