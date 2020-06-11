<?php

namespace PHPModelGenerator\Tests\SchemaProvider;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\SchemaProvider\OpenAPIv3Provider;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

/**
 * Class OpenAPIv3ProviderTest
 *
 * @package PHPModelGenerator\Tests\SchemaProvider
 */
class OpenAPIv3ProviderTest extends AbstractPHPModelGeneratorTest
{
    public function testInvalidJSONSchemaFileThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/^Invalid JSON-Schema file (.*)\.json$/');

        $this->generateClassFromFile('InvalidJSONSchema.json', null, false, OpenAPIv3Provider::class);
    }

    /**
     * @dataProvider missingSchemasDataProvider
     */
    public function testOpenAPIv3JSONSchemaFileWithoutSchemasThrowsAnException(string $file): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Open API v3 spec file (.*)\.json doesn't contain any schemas to process$/"
        );

        $this->generateClassFromFile($file, null, false, OpenAPIv3Provider::class);
    }

    public function missingSchemasDataProvider(): array
    {
        return [
            'No components section defined' => ['NoComponents.json'],
            'No schemas section defined' => ['NoSchemas.json'],
            'Empty schemas section' => ['EmptySchemas.json'],
        ];
    }

    public function testOpenAPIv3SchemaProvider(): void
    {
        $this->generateClassFromFile('MultipleSchemaDefinitions.json', null, false, OpenAPIv3Provider::class);

        $person = new \OpenApiPerson(['name' => 'Hannes']);
        $this->assertSame('Hannes', $person->getName());
        $this->assertNull($person->getAge());

        // test if the custom ID is preferred over the object key
        $car = new \OpenApiCarWithCustomId(['ps' => 150]);
        $this->assertSame(150, $car->getPs());
    }
}
