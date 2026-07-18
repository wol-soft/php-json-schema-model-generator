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
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator): void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                TEST_BASE_DIR . DIRECTORY_SEPARATOR . 'Enum',
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
        // The enum branch of the all-object allOf is routed through the object path; the enum type
        // is preserved on the merged item property, so the getter still returns the backed enum.
        $this->assertSame('completed', $object->getByPackage()[0]->getDeliveryStatus()->value);
        $this->assertSame(250000, $object->getByPackage()[0]->getImpressions());

        // An invalid enum value is still rejected, now surfaced as the item's composition
        // constraint failure (the enum branch does not match) rather than the enum backing-value
        // error, since the item is validated through its composed class.
        $this->expectException(InvalidItemException::class);
        $this->expectExceptionMessage(
            'declined by composition constraint.'
                . "\n      Requires to match all composition elements but matched 1 elements.",
        );
        new $className([
            'by_package' => [
                [
                    'delivery_status' => 'invalid',
                ],
            ],
        ]);
    }
}
