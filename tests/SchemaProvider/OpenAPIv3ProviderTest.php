<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\SchemaProvider;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\SchemaProvider\OpenAPIv3Provider;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class OpenAPIv3ProviderTest extends AbstractPHPModelGeneratorTestCase
{
    public function testInvalidJsonSchemaFileThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/^Invalid JSON-Schema file (.*)\.json at line 5, column 2$/');

        $this->generateClassFromFile('InvalidJSONSchema.json', null, false, true, OpenAPIv3Provider::class);
    }

    /**
     * The spec file decodes successfully (valid JSON), but its root value isn't an object/array,
     * so there is nothing to build an Open API v3 spec from. No JsonSchema can be constructed for
     * a non-array value, so this case has no location - it is reported by file name only.
     */
    #[DataProvider('nonObjectRootDataProvider')]
    public function testNonObjectRootValueThrowsSchemaException(string $jsonContent): void
    {
        $tempFile = sys_get_temp_dir() . '/openApiV3ProviderTest_' . uniqid() . '.json';
        file_put_contents($tempFile, $jsonContent);

        try {
            $this->expectException(SchemaException::class);
            $this->expectExceptionMessageMatches('/^Invalid JSON-Schema file .+\.json$/');
            new OpenAPIv3Provider($tempFile);
        } finally {
            @unlink($tempFile);
        }
    }

    public static function nonObjectRootDataProvider(): array
    {
        return [
            'boolean' => ['true'],
            'integer' => ['42'],
            'string' => ['"hello"'],
            'null' => ['null'],
        ];
    }

    #[DataProvider('missingSchemasDataProvider')]
    public function testOpenApiV3JsonSchemaFileWithoutSchemasThrowsAnException(string $file): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Open API v3 spec file (.*)\.json doesn't contain any schemas to process at line 1, column 1$/",
        );

        $this->generateClassFromFile($file, null, false, true, OpenAPIv3Provider::class);
    }

    public static function missingSchemasDataProvider(): array
    {
        return [
            'No components section defined' => ['NoComponents.json'],
            'No schemas section defined' => ['NoSchemas.json'],
            'Empty schemas section' => ['EmptySchemas.json'],
        ];
    }

    public function testOpenApiV3SchemaProvider(): void
    {
        $this->generateClassFromFile('MultipleSchemaDefinitions.json', null, true, true, OpenAPIv3Provider::class);

        $person = new \OpenApiPerson(['name' => 'Hannes']);
        $this->assertSame('Hannes', $person->getName());
        $this->assertNull($person->getAge());

        // test if the custom ID is preferred over the object key
        $car = new \OpenApiCarWithCustomId(['ps' => 150]);
        $this->assertSame(150, $car->getPs());
    }

    #[DataProvider('referencedSchemaDataProvider')]
    public function testOpenApiV3ReferencedSchemaProvider(
        string $reference,
        array $personData,
        callable $personAssert,
        array $carData,
        callable $carAssert,
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'References.json',
            [$reference, $reference, $reference],
            null,
            false,
            true,
            OpenAPIv3Provider::class,
        );

        $personClass = "{$className}_0";
        $carClass = "{$className}_1";

        $personAssert(new $personClass($personData));
        $carAssert(new $carClass($carData));
    }

    public static function referencedSchemaDataProvider(): array
    {
        return [
            'Empty data path reference' => [
                '#/components/modules/person',
                [],
                static function ($person): void {
                    self::assertNull($person->getName());
                    self::assertIsArray($person->getChildren());
                    self::assertEmpty($person->getChildren());
                },
                [],
                static function ($car): void {
                    self::assertNull($car->getPs());
                    self::assertNull($car->getOwner());
                },
            ],
            'one level data id reference' => [
                '#Person',
                [
                    'name' => 'Hannes',
                    'children' => [
                        [
                            'name' => 'Erwin',
                        ],
                    ],
                ],
                static function ($person): void {
                    self::assertSame('Hannes', $person->getName());
                    self::assertCount(1, $person->getChildren());
                    self::assertSame('Erwin', $person->getChildren()[0]->getName());
                    self::assertEmpty($person->getChildren()[0]->getChildren());
                },
                [
                    'ps' => 150,
                    'owner' => [
                        'name' => 'Susi',
                    ],
                ],
                static function ($car): void {
                    self::assertSame(150, $car->getPs());
                    self::assertSame('Susi', $car->getOwner()->getName());
                    self::assertEmpty($car->getOwner()->getChildren());
                },
            ],
            'nested recursive data id reference' => [
                '#Person',
                [
                    'name' => 'Hannes',
                    'children' => [
                        [
                            'name' => 'Erwin',
                            'children' => [
                                [
                                    'name' => 'Gerda',
                                ],
                            ],
                        ],
                    ],
                ],
                static function ($person): void {
                    self::assertSame('Hannes', $person->getName());
                    self::assertCount(1, $person->getChildren());
                    self::assertSame('Erwin', $person->getChildren()[0]->getName());
                    self::assertCount(1, $person->getChildren()[0]->getChildren());
                    self::assertSame('Gerda', $person->getChildren()[0]->getChildren()[0]->getName());
                    self::assertEmpty($person->getChildren()[0]->getChildren()[0]->getChildren());
                },
                [
                    'ps' => 150,
                    'owner' => [
                        'name' => 'Susi',
                        'children' => [
                            [
                                'name' => 'Gerda',
                            ],
                        ],
                    ],
                ],
                static function ($car): void {
                    self::assertSame(150, $car->getPs());
                    self::assertSame('Susi', $car->getOwner()->getName());
                    self::assertCount(1, $car->getOwner()->getChildren());
                    self::assertSame('Gerda', $car->getOwner()->getChildren()[0]->getName());
                    self::assertEmpty($car->getOwner()->getChildren()[0]->getChildren());
                },
            ],
        ];
    }
}
