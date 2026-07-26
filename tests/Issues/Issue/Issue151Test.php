<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Attributes\Deprecated;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use ReflectionClass;

class Issue151Test extends AbstractPHPModelGeneratorTestCase
{
    public function testAttributeAddedToOneProxyDoesNotLeakToSiblingReference(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'ref_one' => ['$ref' => '#/$defs/Foo'],
                'ref_two' => ['$ref' => '#/$defs/Foo'],
            ],
            '$defs' => [
                'Foo' => ['type' => 'string'],
            ],
        ]);

        $deprecateRefTwo = new class extends PostProcessor {
            public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
            {
                foreach ($schema->getProperties() as $property) {
                    if ($property->getName() === 'ref_two') {
                        $property->addAttribute(new PhpAttribute(Deprecated::class));
                    }
                }
            }
        };

        $this->modifyModelGenerator = static function (ModelGenerator $generator) use ($deprecateRefTwo): void {
            $generator->addPostProcessor($deprecateRefTwo);
        };

        $className = $this->generateClass($schema);
        $reflection = new ReflectionClass($className);

        $this->assertCount(1, $reflection->getProperty('refTwo')->getAttributes(Deprecated::class));
        $this->assertCount(0, $reflection->getProperty('refOne')->getAttributes(Deprecated::class));
    }
}
