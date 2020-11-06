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
    public function testInvalidJsonSchemaFileThrowsAnException(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/^Invalid JSON-Schema file (.*)\.json$/');

        $this->generateClassFromFile('InvalidJSONSchema.json', null, false, true, OpenAPIv3Provider::class);
    }

    /**
     * @dataProvider missingSchemasDataProvider
     */
    public function testOpenApiV3JsonSchemaFileWithoutSchemasThrowsAnException(string $file): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches(
            "/^Open API v3 spec file (.*)\.json doesn't contain any schemas to process$/"
        );

        $this->generateClassFromFile($file, null, false, true, OpenAPIv3Provider::class);
    }

    public function missingSchemasDataProvider(): array
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

    /**
     * @dataProvider referencedSchemaDataProvider
     */
    public function testOpenApiV3ReferencedSchemaProvider(
        string $reference,
        array $personData,
        callable $personAssert,
        array $carData,
        callable $carAssert
    ): void {
        $className = $this->generateClassFromFileTemplate(
            'References.json',
            [$reference, $reference, $reference],
            null,
            false,
            true,
            OpenAPIv3Provider::class
        );

        $personClass = "{$className}_0";
        $carClass = "{$className}_1";

        $personAssert(new $personClass($personData));
        $carAssert(new $carClass($carData));
    }

    public function referencedSchemaDataProvider(): array
    {
        return [
            'Empty data path reference' => [
                '#/components/modules/person',
                [],
                function ($person) {
                    $this->assertNull($person->getName());
                    $this->assertIsArray($person->getChildren());
                    $this->assertEmpty($person->getChildren());
                },
                [],
                function ($car) {
                    $this->assertNull($car->getPs());
                    $this->assertNull($car->getOwner());
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
                function ($person) {
                    $this->assertSame('Hannes', $person->getName());
                    $this->assertCount(1, $person->getChildren());
                    $this->assertSame('Erwin', $person->getChildren()[0]->getName());
                    $this->assertEmpty($person->getChildren()[0]->getChildren());
                },
                [
                    'ps' => 150,
                    'owner' => [
                        'name' => 'Susi',
                    ],
                ],
                function ($car) {
                    $this->assertSame(150, $car->getPs());
                    $this->assertSame('Susi', $car->getOwner()->getName());
                    $this->assertEmpty($car->getOwner()->getChildren());
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
                function ($person) {
                    $this->assertSame('Hannes', $person->getName());
                    $this->assertCount(1, $person->getChildren());
                    $this->assertSame('Erwin', $person->getChildren()[0]->getName());
                    $this->assertCount(1, $person->getChildren()[0]->getChildren());
                    $this->assertSame('Gerda', $person->getChildren()[0]->getChildren()[0]->getName());
                    $this->assertEmpty($person->getChildren()[0]->getChildren()[0]->getChildren());
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
                function ($car) {
                    $this->assertSame(150, $car->getPs());
                    $this->assertSame('Susi', $car->getOwner()->getName());
                    $this->assertCount(1, $car->getOwner()->getChildren());
                    $this->assertSame('Gerda', $car->getOwner()->getChildren()[0]->getName());
                    $this->assertEmpty($car->getOwner()->getChildren()[0]->getChildren());
                },
            ],
        ];
    }
}
