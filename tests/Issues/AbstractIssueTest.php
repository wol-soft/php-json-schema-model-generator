<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues;

use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTest;

abstract class AbstractIssueTest extends AbstractPHPModelGeneratorTest
{
    protected function getSchemaFilePath(string $file): string
    {
        preg_match('/(?P<issue>\d+)/', $this->getStaticClassName(), $matches);

        return __DIR__ . '/../Schema/Issues/' . $matches['issue'] . '/' . $file;
    }
}
