<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\SchemaProvider;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProvider\SingleFileProvider;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Class SingleFileProviderTest
 *
 * @package PHPModelGenerator\Tests\SchemaProvider
 */
class SingleFileProviderTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Generate model classes from a schema file using SingleFileProvider directly.
     */
    private function generateViaProvider(string $file, ?GeneratorConfiguration $config = null): void
    {
        $config = ($config ?? (new GeneratorConfiguration())->setCollectErrors(false))->setOutputEnabled(false);

        (new ModelGenerator($config))->generateModels(
            new SingleFileProvider($this->getSchemaFilePath($file)),
            MODEL_TEMP_PATH,
        );
    }

    /**
     * A valid single-file schema generates a usable PHP model class.
     * Also verifies that getBaseDirectory() returns the directory containing the source file,
     * since both use the same schema file and configuration.
     */
    public function testSingleFileProviderGeneratesClass(): void
    {
        $filePath = $this->getSchemaFilePath('Person.json');
        $provider = new SingleFileProvider($filePath);

        // getBaseDirectory() must point to the directory that contains Person.json so that
        // relative $ref paths in the same directory can be resolved correctly.
        $this->assertSame(dirname(realpath($filePath)), $provider->getBaseDirectory());

        (new ModelGenerator(
            (new GeneratorConfiguration())->setCollectErrors(false)->setOutputEnabled(false),
        ))->generateModels($provider, MODEL_TEMP_PATH);

        $person = new \SingleFileProviderPerson(['name' => 'Alice', 'age' => 30]);
        $this->assertSame('Alice', $person->getName());
        $this->assertSame(30, $person->getAge());
        $this->assertNull((new \SingleFileProviderPerson([]))->getName());
    }

    /**
     * Construction fails with a SchemaException for a non-existing file and for a file
     * containing invalid JSON — both represent an unusable schema source.
     */
    public function testInvalidSourceThrowsSchemaException(): void
    {
        // Non-existing file
        try {
            new SingleFileProvider('/non/existing/path.json');
            $this->fail('Expected SchemaException for non-existing file');
        } catch (SchemaException $schemaException) {
            $this->assertMatchesRegularExpression('/^Invalid JSON-Schema file/', $schemaException->getMessage());
        }

        // File containing invalid JSON
        try {
            new SingleFileProvider($this->getSchemaFilePath('InvalidJSON.json'));
            $this->fail('Expected SchemaException for invalid JSON file');
        } catch (SchemaException $schemaException) {
            $this->assertMatchesRegularExpression('/^Invalid JSON-Schema file/', $schemaException->getMessage());
        }
    }

    /**
     * A schema with a relative $ref to a sibling file generates both classes and correctly
     * wires up the nested object — verifying that RefResolverTrait resolves relative paths
     * relative to the source file's directory.
     */
    public function testExternalRefIsResolved(): void
    {
        $this->generateViaProvider('PersonWithAddress.json');

        $person = new \SingleFileProviderPersonWithAddress([
            'name' => 'Alice',
            'address' => ['city' => 'Berlin'],
        ]);

        $this->assertSame('Alice', $person->getName());
        $this->assertNotNull($person->getAddress());
        $this->assertSame('Berlin', $person->getAddress()->getCity());
    }
}
