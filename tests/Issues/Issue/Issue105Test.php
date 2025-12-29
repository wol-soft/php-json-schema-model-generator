<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\Dependency\InvalidSchemaDependencyException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Object\RequiredValueException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\EnumPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPModelGenerator\Tests\Issues\Issue\output\Models\Issue104Test_6948130ae6bdf;

class Issue105Test extends AbstractIssueTestCase
{
    public function testCompositionValidatorOnPropertyGeneratesNoDynamicProperty(): void
    {
        if(PHP_VERSION_ID < 80100){ // up to php 8.1 there were no php enums
            self::markTestSkipped();
        }

        $generatorConfiguration = (new GeneratorConfiguration())->setImmutable(false);
        $this->modifyModelGenerator = static function (ModelGenerator $modelGenerator) : void {
            $modelGenerator->addPostProcessor(new EnumPostProcessor(
                implode(DIRECTORY_SEPARATOR, [sys_get_temp_dir(), 'PHPModelGeneratorTest', 'Enum']),
                'Enum',
                true,

            ));
        };
        $className = $this->generateClassFromFile('issue105schema.json', $generatorConfiguration);


        $data = [
            'media_buy_deliveries' => [
                [
                    'by_package' => [
                        [
                            'impressions' => 250000,
                            'delivery_status' => 'completed',
                        ],
                    ],
                ],
                [
                    'by_package' => [
                        [
                            'impressions' => 175000,
                            'delivery_status' => 'flight_ended',
                        ],
                    ],
                ],
            ],
        ];


        $object = new $className($data);

        $this->assertSame('completed', $object->getMediaBuyDeliveries()[0]->getByPackage()[0]->getDeliveryStatus()->value);

    }
}
