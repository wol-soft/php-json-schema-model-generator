<?php

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProvider\RecursiveDirectoryProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class GeneratorTest
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class GeneratorTest extends TestCase
{
    public function testNonExistingSourceDirectoryThrowsAnException(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage("Source directory '" . __DIR__ . "/NonExistingDirectory' doesn't exist");

        (new ModelGenerator())->generateModels(
            new RecursiveDirectoryProvider(__DIR__ . '/NonExistingDirectory'),
            __DIR__
        );
    }

    public function testNonExistingDestinationDirectoryThrowsAnException(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage("Destination directory '" . __DIR__ . "/NonExistingDirectory' doesn't exist or is not empty");

        (new ModelGenerator())->generateModels(
            new RecursiveDirectoryProvider(__DIR__),
            __DIR__ . '/NonExistingDirectory'
        );
    }

    public function testNotEmptyDestinationDirectoryThrowsAnException(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage("Destination directory '" . __DIR__ . "' doesn't exist or is not empty");

        (new ModelGenerator())->generateModels(new RecursiveDirectoryProvider(__DIR__), __DIR__);
    }
}
