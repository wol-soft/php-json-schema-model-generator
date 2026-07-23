<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Arrays\InvalidItemException;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;

#[ApplicableDrafts]
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
        $this->assertSame('completed', $object->getByPackage()[0]->getDeliveryStatus()->value);
        $this->assertSame(250000, $object->getByPackage()[0]->getImpressions());

        // Composition must not adopt a partially-successful branch's value when the overall
        // composition fails: only the "impressions" branch (optional, absent here) trivially
        // passes, "delivery_status" fails its enum check, so allOf requires 2 matches but got 1.
        $this->expectException(InvalidItemException::class);
        $this->expectExceptionMessage(
            'Requires to match all composition elements but matched 1 element',
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
