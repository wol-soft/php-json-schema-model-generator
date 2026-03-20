<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue105Test extends AbstractIssueTestCase
{
    public function testEnumInComposition(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator) : void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,
            ));
        };
        $className = $this->generateClassFromFile('enumInComposition.json');

        $data = [
            'by_package' => [
                [
                    'impressions' => 250000,
                    'delivery_status' => 'completed',
                ],
            ],
        ];

        $object = new $className($data);

        $this->assertCount(1, $object->getByPackage());
        $this->assertSame('completed', $object->getByPackage()[0]->getDeliveryStatus()->value);
        $this->assertSame(250000, $object->getByPackage()[0]->getImpressions());

        $this->expectException(InvalidItemException::class);
        $this->expectExceptionMessage('"invalid" is not a valid backing value for enum');
        new $className([
            'by_package' => [
                [
                    'delivery_status' => 'invalid',
                ],
            ],
        ]);
    }
}
