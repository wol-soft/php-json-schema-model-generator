<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Issues\AbstractIssueTest;

class Issue76Test extends AbstractIssueTest
{
    public function testSerializeWithImplicitNullEnabledIncludesAllFields(): void
    {
        $className = $this->generateClassFromFile(
            'optionalPropertySerialization.json',
            (new GeneratorConfiguration())->setSerialization(true)
        );

        $object = new $className(['property1' => 'Hello']);

        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property2' => null, 'property3' => 'Moin'],
            $object->toArray()
        );
    }

    public function testSerializeSkipsNotProvidedOptionalProperties(): void
    {
        $className = $this->generateClassFromFile(
            'optionalPropertySerialization.json',
            (new GeneratorConfiguration())->setSerialization(true)->setImmutable(false),
            false,
            false
        );

        $object = new $className(['property1' => 'Hello']);

        $this->assertEqualsCanonicalizing(['property1' => 'Hello', 'property3' => 'Moin'], $object->toArray());
        $object->setProperty2('World');
        $this->assertEqualsCanonicalizing(
            ['property1' => 'Hello', 'property2' => 'World', 'property3' => 'Moin'],
            $object->toArray()
        );
    }
}
