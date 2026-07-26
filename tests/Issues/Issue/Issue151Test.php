<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Attributes\Deprecated;
use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Model\Attributes\PhpAttribute;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use ReflectionClass;

/**
 * Issue #151: PropertyProxy::addAttribute() used to delegate to the shared underlying property,
 * so adding an attribute to one proxy (e.g. via a post processor) leaked onto every sibling proxy
 * referencing the same $ref definition. Fixed by storing added attributes locally on the proxy
 * and merging them into getAttributes().
 *
 * That fix in turn exposed a second bug: PropertyAttributeSynthesizer calls filterAttributes()
 * followed by addAttribute() on a transferred property once per composition validator (allOf,
 * anyOf, ...) that defines it. filterAttributes() only cleared the shared underlying property's
 * attributes, never the proxy's own local ones, so a property shared across multiple composition
 * branches accumulated duplicate #[JsonPointer] attributes instead of replacing them on each
 * pass. Fixed by making filterAttributes() also filter the proxy's own local attributes.
 */
class Issue151Test extends AbstractIssueTestCase
{
    public function testAttributeAddedToOneProxyDoesNotLeakToSiblingReference(): void
    {
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

        $className = $this->generateClassFromFile('refLeak.json');
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

        // both proxies must still resolve to independently usable string properties
        $object = new $className(['ref_one' => 'one', 'ref_two' => 'two']);
        $this->assertSame('one', $object->getRefOne());
        $this->assertSame('two', $object->getRefTwo());
    }

    /**
     * warmup consumes the first (non-proxy) resolution of the shared $ref, so allOf's "foo" (the
     * next usage of that $ref with the same required-ness) resolves as a PropertyProxy referencing
     * warmup's property. That proxy is the one transferred and registered as the schema's outer
     * "foo", so synthesiseJsonPointerAttributes() runs against it once when allOf's validator
     * completes and once when anyOf's does.
     */
    public function testRefPropertyInMultipleCompositionsDoesNotGetDuplicateJsonPointer(): void
    {
        $className = $this->generateClassFromFile('duplicateJsonPointer.json');
        $reflection = new ReflectionClass($className);

        $fooPointers = $reflection->getProperty('foo')->getAttributes(JsonPointer::class);
        $this->assertCount(
            1,
            $fooPointers,
            'foo must carry exactly one JsonPointer attribute, not one per composition validator ' .
                'that defines it',
        );
        $this->assertSame(['/$defs/Foo'], $fooPointers[0]->getArguments());

        // both the composed property and the plain sibling sharing its $ref must still function
        $object = new $className(['warmup' => 'w', 'foo' => 'f']);
        $this->assertSame('w', $object->getWarmup());
        $this->assertSame('f', $object->getFoo());
    }
}
