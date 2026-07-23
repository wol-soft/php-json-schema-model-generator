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

/**
 * When two properties reference the same $def, the second is generated as a PropertyProxy sharing
 * the first property's underlying object. An attribute added to one proxy (here via a post
 * processor) must stay on that proxy and not leak onto the shared underlying — otherwise every
 * sibling reference to the same $def inherits it.
 */
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

        $this->assertCount(
            1,
            $reflection->getProperty('refTwo')->getAttributes(Deprecated::class),
            'ref_two must carry the Deprecated attribute added to it',
        );
        $this->assertCount(
            0,
            $reflection->getProperty('refOne')->getAttributes(Deprecated::class),
            'ref_one must not inherit the Deprecated attribute added only to ref_two',
        );
    }
}
