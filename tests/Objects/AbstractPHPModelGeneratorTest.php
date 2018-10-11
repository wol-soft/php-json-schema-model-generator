<?php

namespace PHPModelGenerator\Tests\Objects;

use FilesystemIterator;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Generator;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Class AbstractPHPModelGeneratorTest
 *
 * @package PHPModelGenerator\Tests\Objects
 */
abstract class AbstractPHPModelGeneratorTest extends TestCase
{
    private $names = [];

    public static function setUpBeforeClass()
    {
        $di = new RecursiveDirectoryIterator(sys_get_temp_dir() . '/PHPModelGeneratorTest', FilesystemIterator::SKIP_DOTS);
        $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($ri as $file ) {
            $file->isDir() ?  rmdir($file) : unlink($file);
        }

        @mkdir(sys_get_temp_dir() . '/PHPModelGeneratorTest');
        @mkdir(sys_get_temp_dir() . '/PHPModelGeneratorTest/Models');
    }

    public function tearDown()
    {
        parent::tearDownAfterClass();

        foreach ($this->names as $name) {
            unlink(sys_get_temp_dir() . '/PHPModelGeneratorTest/' . $name . '.json');
            unlink(sys_get_temp_dir() . '/PHPModelGeneratorTest/Models/' . $name . '.php');
        }
    }

    /**
     * @param string                 $jsonSchema
     * @param array                  $objectInput
     * @param GeneratorConfiguration $generatorConfiguration
     *
     * @return object
     *
     * @throws FileSystemException
     * @throws RenderException
     * @throws SchemaException
     */
    public function generateObject(
        string $jsonSchema,
        array $objectInput,
        GeneratorConfiguration $generatorConfiguration = null
    ): object {
        $generatorConfiguration = $generatorConfiguration ?? new GeneratorConfiguration();
        $generatorConfiguration->setPrettyPrint(false);

        $baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'PHPModelGeneratorTest';
        foreach ($this->names as $name) {
            unlink($baseDir . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $name . '.php');
        }

        $className = $this->getClassName();

        $jsonSchema = json_decode($jsonSchema, true);
        $jsonSchema['id'] = $className;
        $jsonSchema = json_encode($jsonSchema);

        file_put_contents($baseDir . DIRECTORY_SEPARATOR . $className . '.json', $jsonSchema);

        (new Generator($generatorConfiguration))->generateModels(
            $baseDir,
            $baseDir . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR
        );

        require_once $baseDir . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $className . '.php';

        return new $className($objectInput);
    }

    private function getClassName()
    {
        $name = '_' . uniqid();

        while (in_array($name, $this->names)) {
            $name .= '1';
        }

        $this->names[] = $name;

        return $name;
    }
}
