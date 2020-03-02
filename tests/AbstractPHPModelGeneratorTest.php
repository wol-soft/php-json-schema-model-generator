<?php

namespace PHPModelGenerator\Tests;

use FilesystemIterator;
use PHPModelGenerator\Utils\ClassNameGenerator;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Class AbstractPHPModelGeneratorTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
abstract class AbstractPHPModelGeneratorTest extends TestCase
{
    protected const EXTERNAL_JSON_DIRECTORIES = [];

    private $names = [];

    private $generatedFiles = [];

    /**
     * Set up an empty directory for the tests
     */
    public function setUp(): void
    {
        parent::setUp();

        if (is_dir(sys_get_temp_dir() . '/PHPModelGeneratorTest')) {
            $di = new RecursiveDirectoryIterator(sys_get_temp_dir() . '/PHPModelGeneratorTest', FilesystemIterator::SKIP_DOTS);
            $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($ri as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
        }

        @mkdir(sys_get_temp_dir() . '/PHPModelGeneratorTest');
        @mkdir(sys_get_temp_dir() . '/PHPModelGeneratorTest/Models');
    }

    /**
     * Check if the test has failed. In this case move all JSON files and generated classes in a directory for debugging
     * Additionally clear the test folder so the next test starts in an empty environment
     */
    public function tearDown(): void
    {
        parent::tearDown();

        if ($this->hasFailed()) {
            $failedResultDir = FAILED_CLASSES_PATH . preg_replace( '/[^a-z0-9]+/i', '-', $this->getName());

            @mkdir($failedResultDir, 0777, true);
            foreach ($this->names as $name) {
                copy(
                    sys_get_temp_dir() . '/PHPModelGeneratorTest/' . $name . '.json',
                    $failedResultDir . DIRECTORY_SEPARATOR . $name . '.json'
                );
            }

            foreach ($this->generatedFiles as $file) {
                copy(
                    $file,
                    $failedResultDir . DIRECTORY_SEPARATOR . basename($file)
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
                @mkdir($baseDir . dirname(str_replace($copyBaseDir, '', $file)), 0777, true);
                @copy($file, $baseDir . str_replace($copyBaseDir, '', $file));
            }
        }
    }

    /**
     * Generate a class from a given JSON schema file and return the FQCN
     *
     * @param string                      $file
     * @param GeneratorConfiguration|null $generatorConfiguration
     * @param bool                        $originalClassNames
     *
     * @return string
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    protected function generateClassFromFile(
        string $file,
        GeneratorConfiguration $generatorConfiguration = null,
        bool $originalClassNames = false
    ): string {
        return $this->generateClass(
            file_get_contents(__DIR__ . '/Schema/' . $this->getStaticClassName() . '/' . $file),
            $generatorConfiguration,
            $originalClassNames
        );
    }

    /**
     * Generate a class from a file template and apply all $values via sprintf to the template
     *
     * @param string                      $file
     * @param array                       $values
     * @param GeneratorConfiguration|null $generatorConfiguration
     * @param bool                        $escape
     *
     * @return string
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    protected function generateClassFromFileTemplate(
        string $file,
        array $values,
        GeneratorConfiguration $generatorConfiguration = null,
        bool $escape = true
    ): string {
        return $this->generateClass(
            call_user_func_array(
                'sprintf',
                array_merge(
                    [file_get_contents(__DIR__ . '/Schema/' . $this->getStaticClassName() . '/' . $file)],
                    array_map(
                        function ($item) use ($escape) {
                            return $escape ? str_replace("'", '"', addcslashes($item, '"\\')) : $item;
                        },
                        $values
                    )
                )
            ),
            $generatorConfiguration
        );
    }

    /**
     * Generate a class from a given JSON schema string and return the FQCN
     *
     * @param string                 $jsonSchema
     * @param GeneratorConfiguration $generatorConfiguration
     * @param bool                   $originalClassNames
     *
     * @return string
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    protected function generateClass(
        string $jsonSchema,
        GeneratorConfiguration $generatorConfiguration = null,
        bool $originalClassNames = false
    ): string {
        $generatorConfiguration = ($generatorConfiguration ?? (new GeneratorConfiguration())->setCollectErrors(false))
            ->setPrettyPrint(false)
            ->setOutputEnabled(false);

        if (!$originalClassNames) {
            // extend the class name generator to attach a uniqid as multiple test executions use identical $id
            // properties which would lead to name collisions
            $generatorConfiguration->setClassNameGenerator(new class extends ClassNameGenerator {
                public function getClassName(
                    string $propertyName,
                    array $schema,
                    bool $isMergeClass,
                    string $currentClassName = ''
                ): string {
                    return parent::getClassName($propertyName, $schema, $isMergeClass, $currentClassName) .
                        ($currentClassName ? uniqid() : '');
                }
            });
        }

        $baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'PHPModelGeneratorTest';
        foreach ($this->names as $name) {
            unlink($baseDir . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $name . '.php');
        }

        $className = $this->getClassName();

        // generate an object ID for valid JSON schema files to avoid class name collisions in the testing process
        $jsonSchemaArray = json_decode($jsonSchema, true);
        if ($jsonSchemaArray) {
            $jsonSchemaArray['id'] = $className;
            $jsonSchema = json_encode($jsonSchemaArray);
        }

        file_put_contents($baseDir . DIRECTORY_SEPARATOR . $className . '.json', $jsonSchema);
        $this->copyExternalJSON();

        $generatedFiles = (new ModelGenerator($generatorConfiguration))->generateModels(
            $baseDir,
            $baseDir . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR
        );

        foreach ($generatedFiles as $path) {
            $this->generatedFiles[] = $path;

            require $path;
        }

        return $className;
    }

    /**
     * Generate objects for all JSON-Schema files in the given directory
     *
     * @param string                 $directory
     * @param GeneratorConfiguration $configuration
     * @return array
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    protected function generateDirectory(string $directory, GeneratorConfiguration $configuration): array
    {
        $generatedClasses = (new ModelGenerator($configuration))->generateModels(
            __DIR__ . '/Schema/' . $this->getStaticClassName() . '/' . $directory,
            MODEL_TEMP_PATH
        );

        foreach ($generatedClasses as $path) {
            $this->generatedFiles[] = $path;

            require $path;
        }

        return $generatedClasses;
    }

    /**
     * Combine two data providers
     *
     * @param array $dataProvider1
     * @param array $dataProvider2
     *
     * @return array
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
     *
     * @param GeneratorConfiguration $configuration
     * @param array|string           $messages
     */
    protected function expectValidationError(GeneratorConfiguration $configuration, $messages): void
    {
        if (!is_array($messages)) {
            $messages = [$messages];
        }

        if ($configuration->collectErrors()) {
            $this->expectExceptionObject($this->getErrorRegistryException($messages));
        } else {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage($messages[0]);
        }
    }

    /**
     * Expect a validation error based on the given configuration matching the given message(s)
     *
     * @param GeneratorConfiguration $configuration
     * @param array|string           $messages
     */
    protected function expectValidationErrorRegExp(GeneratorConfiguration $configuration, $messages): void
    {
        if (!is_array($messages)) {
            $messages = [$messages];
        }

        if ($configuration->collectErrors()) {
            $exception = $this->getErrorRegistryException($messages);
            $this->expectException(get_class($exception));
            $this->expectExceptionMessageMatches($exception->getMessage());
        } else {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessageMatches($messages[0]);
        }
    }

    /**
     * Set up an ErrorRegistryException containing the given messages
     *
     * @param array $messages
     *
     * @return ErrorRegistryException
     */
    protected function getErrorRegistryException(array $messages): ErrorRegistryException
    {
        $errorRegistry = new ErrorRegistryException();

        foreach ($messages as $message) {
            $errorRegistry->addError($message);
        }

        return $errorRegistry;
    }

    public function validationMethodDataProvider(): array {
        return [
            'Error Collection' => [new GeneratorConfiguration()],
            'Direct Exception' => [(new GeneratorConfiguration())->setCollectErrors(false)],
        ];
    }

    /**
     * Get the annotated type for an object property
     *
     * @param object $object
     * @param string $property
     *
     * @return string
     */
    protected function getPropertyType(object $object, string $property): string
    {
        $matches = [];
        preg_match(
            '/@var\s+([^\s]+)\s/',
            (new ReflectionClass($object))->getProperty($property)->getDocComment(),
            $matches
        );

        return $matches[1];
    }

    /**
     * Get the annotated return type for an object method
     *
     * @param object $object
     * @param string $method
     *
     * @return string
     */
    protected function getMethodReturnType(object $object, string $method): string
    {
        $matches = [];
        preg_match(
            '/@return\s+([^\s]+)\s/',
            (new ReflectionClass($object))->getMethod($method)->getDocComment(),
            $matches
        );

        return $matches[1];
    }

    /**
     * Generate a unique name for a class
     *
     * @return string
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

    private function getStaticClassName(): string
    {
        $parts = explode('\\', static::class);

        return end($parts);
    }
}
