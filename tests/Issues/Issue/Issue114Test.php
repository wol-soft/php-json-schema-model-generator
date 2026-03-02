<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue114Test extends AbstractIssueTestCase
{
    /**
     * @requires PHP >= 8.1
     */
    public function testEnumWithConstConditionalGeneratesClass(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };

        $className = $this->generateClassFromFile('enumConstConditional.json');

        $object = new $className(['buying_mode' => 'wholesale', 'min_quantity' => 10]);
        $this->assertSame('wholesale', $object->getBuyingMode()->value);
        $this->assertSame(10, $object->getMinQuantity());
    }

    /**
     * @requires PHP >= 8.1
     */
    public function testEnumWithConstConditionalBriefModeIsValid(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };

        $className = $this->generateClassFromFile('enumConstConditional.json');

        $object = new $className(['buying_mode' => 'brief']);
        $this->assertSame('brief', $object->getBuyingMode()->value);
        $this->assertNull($object->getMinQuantity());
    }
}
