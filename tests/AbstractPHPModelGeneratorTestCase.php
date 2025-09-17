<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests;

use Exception;
use FilesystemIterator;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProvider\OpenAPIv3Provider;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;
use PHPModelGenerator\Utils\ClassNameGenerator;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionType;

/**
 * Class AbstractPHPModelGeneratorTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
abstract class AbstractPHPModelGeneratorTestCase extends TestCase
{
    protected const EXTERNAL_JSON_DIRECTORIES = [];

    protected $modifyModelGenerator;

    private array $names = [];

    private array $generatedFiles = [];

    /**
     * Set up an empty directory for the tests
     */
    public function setUp(): void
    {
        parent::setUp();

        (new ModelGenerator())->generateModelDirectory(sys_get_temp_dir() . '/PHPModelGeneratorTest');
        @mkdir(sys_get_temp_dir() . '/PHPModelGeneratorTest/Models');
    }

    /**
     * Polyfill for assertRegEx to avoid warnings during test execution
     *
     * TODO: remove and switch all calls to assertMatchesRegularExpression when dropping support for PHPUnit < 9.1
     * TODO: (dropping support for PHP < 7.4)
     */
    public static function assertRegExp(string $pattern, string $string, string $message = ''): void
    {
        is_callable([parent::class, 'assertMatchesRegularExpression'])
            ? parent::assertMatchesRegularExpression($pattern, $string, $message)
            : parent::assertRegExp($pattern, $string, $message);
    }

    /**
     * Check if the test has failed. In this case move all JSON files and generated classes in a directory for debugging
     *
     * Additionally clear the test folder so the next test starts in an empty environment
     */
    public function tearDown(): void
    {
        parent::tearDown();

        if ($this->hasFailed()) {
            $failedResultDir = FAILED_CLASSES_PATH . preg_replace( '/[^a-z0-9]+/i', '-', $this->getName());
            $dir = sys_get_temp_dir() . '/PHPModelGeneratorTest';

            foreach (
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS))
                as
                $item
            ) {
                $file = (string) $item;
                $nestedDir = dirname(str_replace($dir, '', $file));

                if (!is_dir($failedResultDir . $nestedDir)) {
                    @mkdir($failedResultDir . $nestedDir, 0755, true);
                }

                copy(
                    $file,
                    $failedResultDir . $nestedDir . DIRECTORY_SEPARATOR . basename($file),
                );
            }
        }

        $this->names = [];
        $this->generatedFiles = [];
    }

    /**
     * Copy given external JSON schema files into the tmp directory to make them available during model generation
     */
    private function copyExternalJSON(): void
    {
        $baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'PHPModelGeneratorTest' . DIRECTORY_SEPARATOR;
        $copyBaseDir = __DIR__ . "/Schema/{$this->getStaticClassName()}/";

        foreach (static::EXTERNAL_JSON_DIRECTORIES as $directory) {
            $di = new RecursiveDirectoryIterator($copyBaseDir . $directory, FilesystemIterator::SKIP_DOTS);

            foreach (new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                @mkdir($baseDir . dirname(str_replace($copyBaseDir, '', (string) $file)), 0777, true);
                @copy((string) $file, $baseDir . str_replace($copyBaseDir, '', (string) $file));
            }
        }
    }

    /**
     * Generate a class from a given JSON schema file and return the FQCN
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    protected function generateClassFromFile(
        string $file,
        ?GeneratorConfiguration $generatorConfiguration = null,
        bool $originalClassNames = false,
        bool $implicitNull = true,
        string $schemaProviderClass = RecursiveDirectoryProvider::class,
    ): string {
        return $this->generateClass(
            file_get_contents($this->getSchemaFilePath($file)),
            $generatorConfiguration,
            $originalClassNames,
            $implicitNull,
            $schemaProviderClass,
        );
    }

    /**
     * Generate a class from a file template and apply all $values via sprintf to the template
     *
     * @param string[]                    $values
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    protected function generateClassFromFileTemplate(
        string $file,
        array $values,
        ?GeneratorConfiguration $generatorConfiguration = null,
        bool $escape = true,
        bool $implicitNull = true,
        string $schemaProviderClass = RecursiveDirectoryProvider::class,
    ): string {
        return $this->generateClass(
            sprintf(
                file_get_contents($this->getSchemaFilePath($file)),
                ...array_map(
                    static fn(string $item): string =>
                        $escape ? str_replace("'", '"', addcslashes($item, '"\\')) : $item,
                    $values,
                )
            ),
            $generatorConfiguration,
            false,
            $implicitNull,
            $schemaProviderClass,
        );
    }

    /**
     * Generate a class from a given JSON schema string and return the FQCN
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    protected function generateClass(
        string $jsonSchema,
        ?GeneratorConfiguration $generatorConfiguration = null,
        bool $originalClassNames = false,
        bool $implicitNull = true,
        string $schemaProviderClass = RecursiveDirectoryProvider::class,
    ): string {
        $generatorConfiguration = ($generatorConfiguration ?? (new GeneratorConfiguration())->setCollectErrors(false))
            ->setImplicitNull($implicitNull)
            ->setOutputEnabled(false);

        $baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'PHPModelGeneratorTest';

        foreach (
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS))
            as
            $item
        ) {
            unlink((string) $item);
        }

        $className = $this->getClassName();

        if (!$originalClassNames) {
            // extend the class name generator to attach a uniqid as multiple test executions use identical $id
            // properties which would lead to name collisions
            $generatorConfiguration->setClassNameGenerator(new class extends ClassNameGenerator {
                public function getClassName(
                    string $propertyName,
                    JsonSchema $schema,
                    bool $isMergeClass,
                    string $currentClassName = '',
                ): string {
                    return parent::getClassName($propertyName, $schema, $isMergeClass, $currentClassName) .
                        ($currentClassName ? uniqid() : '');
                }
            });

            // generate an object ID for valid JSON schema files to avoid class name collisions in the testing process
            $jsonSchemaArray = json_decode($jsonSchema, true);
            if ($jsonSchemaArray) {
                $jsonSchemaArray['$id'] = $className;

                if (isset($jsonSchemaArray['components']['schemas'])) {
                    $counter = 0;
                    foreach ($jsonSchemaArray['components']['schemas'] as &$schema) {
                        $schema['$id'] = $className . '_' . $counter++;
                    }
                }

                $jsonSchema = json_encode($jsonSchemaArray);
            }
        }

        $mainFile = $baseDir . DIRECTORY_SEPARATOR . $className . '.json';
        file_put_contents($mainFile, $jsonSchema);
        $this->copyExternalJSON();

        switch ($schemaProviderClass) {
            case RecursiveDirectoryProvider::class:
                $schemaProvider = new RecursiveDirectoryProvider($baseDir);
                break;
            case OpenAPIv3Provider::class:
                $schemaProvider = new OpenAPIv3Provider($mainFile);
                break;
            default: throw new Exception("Schema provider $schemaProviderClass not supported");
        }

        $generator = new ModelGenerator($generatorConfiguration);
        if (is_callable($this->modifyModelGenerator)) {
            ($this->modifyModelGenerator)($generator);
        }

        $generatedFiles = $generator->generateModels(
            $schemaProvider,
            $baseDir . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR,
        );

        foreach ($generatedFiles as $path) {
            $this->generatedFiles[] = $path;
        }

        return $className;
    }

    /**
     * Generate objects for all JSON-Schema files in the given directory
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    protected function generateDirectory(string $directory, GeneratorConfiguration $configuration): array
    {
        $generatedClasses = (new ModelGenerator($configuration))->generateModels(
            new RecursiveDirectoryProvider(__DIR__ . '/Schema/' . $this->getStaticClassName() . '/' . $directory),
            MODEL_TEMP_PATH,
        );

        foreach ($generatedClasses as $path) {
            $this->generatedFiles[] = $path;
        }

        return $generatedClasses;
    }

    /**
     * Combine two data providers
     */
    protected function combineDataProvider(array $dataProvider1, array $dataProvider2): array
    {
        $result = [];
        foreach ($dataProvider1 as $dp1Key => $dp1Value) {
            foreach ($dataProvider2 as $dp2Key => $dp2Value) {
                $result["$dp1Key - $dp2Key"] = array_merge($dp1Value, $dp2Value);
            }
        }

        return $result;
    }

    /**
     * Expect a validation error based on the given configuration
     */
    protected function expectValidationError(GeneratorConfiguration $configuration, array | string $messages): void
    {
        if (!is_array($messages)) {
            $messages = [$messages];
        }

        if ($configuration->collectErrors()) {
            $this->expectException(ErrorRegistryException::class);
            $this->expectExceptionMessage(join("\n", $messages));
        } else {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage($messages[0]);
        }
    }

    /**
     * Expect a validation error based on the given configuration matching the given message(s)
     */
    protected function expectValidationErrorRegExp(
        GeneratorConfiguration $configuration,
        array | string $messages,
    ): void {
        if (!is_array($messages)) {
            $messages = [$messages];
        }

        if ($configuration->collectErrors()) {
            $this->expectException(ErrorRegistryException::class);
            $this->expectExceptionMessageMatches(join("\n", $messages));
        } else {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessageMatches($messages[0]);
        }
    }

    /**
     * Set up an ErrorRegistryException containing the given messages
     */
    protected function getErrorRegistryException(array $messages): ErrorRegistryException
    {
        $errorRegistry = new ErrorRegistryException();

        foreach ($messages as $message) {
            $errorRegistry->addError($message);
        }

        return $errorRegistry;
    }

    /**
     * Check if the given error registry exception contains the requested exception.
     *
     * @throws AssertionFailedError
     */
    protected function assertErrorRegistryContainsException(
        ErrorRegistryException $registryException,
        string $expectedException,
    ): ValidationException {
        foreach ($registryException->getErrors() as $error) {
            if ($error instanceof $expectedException) {
                return $error;
            }
        }

        $this->fail("Error exception $expectedException not found in error registry exception");
    }

    public function validationMethodDataProvider(): array
    {
        return [
            'Error Collection' => [new GeneratorConfiguration()],
            'Direct Exception' => [(new GeneratorConfiguration())->setCollectErrors(false)],
        ];
    }

    public function implicitNullDataProvider(): array
    {
        return [
            'implicit null enabled' => [true],
            'implicit null disabled' => [false],
        ];
    }

    public function namespaceDataProvider(): array
    {
        return [
            'No namespace' => [''],
            'Custom namespace' => ['\MyApp\Model\\'],
        ];
    }

    /**
     * Get the annotated type for an object property
     */
    protected function getPropertyTypeAnnotation(string | object $object, string $property): string
    {
        $matches = [];
        preg_match(
            '/@var\s+([^\s]+)\s/',
            (new ReflectionClass($object))->getProperty($property)->getDocComment(),
            $matches,
        );

        return $matches[1];
    }

    /**
     * Get the annotated return type for an object method
     */
    protected function getReturnTypeAnnotation(string | object $object, string $method): string
    {
        $matches = [];
        preg_match(
            '/@return\s+([^\s]+)\s/',
            (new ReflectionClass($object))->getMethod($method)->getDocComment(),
            $matches,
        );

        return $matches[1] ?? '';
    }

    /**
     * Get the annotated parameter type for an object method
     */
    protected function getParameterTypeAnnotation(string | object $object, string $method, int $parameter = 0): string
    {
        $matches = [];
        preg_match_all(
            '/@param\s+([^\s]*)\s?\$/',
            (new ReflectionClass($object))->getMethod($method)->getDocComment(),
            $matches,
        );

        return $matches[1][$parameter];
    }

    protected function getParameterType($object, string $method, int $parameter = 0): ?ReflectionType
    {
        return (new ReflectionClass($object))->getMethod($method)->getParameters()[$parameter]->getType();
    }

    protected function getReturnType($object, string $method): ?ReflectionType
    {
        return (new ReflectionClass($object))->getMethod($method)->getReturnType();
    }

    protected function getGeneratedFiles(): array
    {
        return $this->generatedFiles;
    }

    protected function getSchemaFilePath(string $file): string
    {
        return __DIR__ . '/Schema/' . $this->getStaticClassName() . '/' . $file;
    }

    /**
     * Generate a unique name for a class
     */
    private function getClassName(): string
    {
        // include the static class name to avoid collisions from loaded classes from multiple tests
        $name = $this->getStaticClassName() . '_' . uniqid();

        while (in_array($name, $this->names)) {
            $name .= '1';
        }

        $this->names[] = $name;

        return $name;
    }

    protected function getStaticClassName(): string
    {
        $parts = explode('\\', static::class);

        return end($parts);
    }
}
