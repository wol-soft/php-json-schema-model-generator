<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue117Test extends AbstractIssueTestCase
{
    private function getConfig(): GeneratorConfiguration
    {
        return (new GeneratorConfiguration())->setSerialization(true)->setImmutable(false);
    }

    /**
     * The basic case from the issue report: outer optional 'name' not provided,
     * inner required 'name' must still appear in the serialized output.
     */
    public function testNestedRequiredPropertyNotSkippedWhenOuterOptionalPropertyHasSameName(): void
    {
        $className = $this->generateClassFromFile(
            'nestedObjectSamePropertyName.json',
            $this->getConfig(),
            false,
            false,
        );

        $object = new $className(['dog' => ['name' => 'woofy']]);

        $this->assertSame(
            ['dog' => ['name' => 'woofy']],
            $object->toArray(),
        );
    }

    /**
     * When the outer optional 'name' IS provided it must appear in the output,
     * and the inner 'name' must appear too.
     */
    public function testBothOuterAndInnerPropertyIncludedWhenOuterProvided(): void
    {
        $className = $this->generateClassFromFile(
            'nestedObjectSamePropertyName.json',
            $this->getConfig(),
            false,
            false,
        );

        $object = new $className(['name' => 'Alice', 'dog' => ['name' => 'woofy']]);

        $this->assertSame(
            ['name' => 'Alice', 'dog' => ['name' => 'woofy']],
            $object->toArray(),
        );
    }

    /**
     * User-supplied $except must still exclude the property at ALL levels
     * (this is the intentional global-except behaviour).
     */
    public function testUserSuppliedExceptExcludesPropertyAtAllLevels(): void
    {
        $className = $this->generateClassFromFile(
            'nestedObjectSamePropertyName.json',
            $this->getConfig(),
            false,
            false,
        );

        $object = new $className(['name' => 'Alice', 'dog' => ['name' => 'woofy']]);

        $this->assertSame(
            ['dog' => []],
            $object->toArray(['name']),
        );
    }

    /**
     * Three levels of nesting with 'name' at each level: outer optional not provided,
     * both inner levels must include their 'name'.
     */
    public function testMultiLevelNestedRequiredPropertyNotSkipped(): void
    {
        $className = $this->generateClassFromFile(
            'nestedObjectSamePropertyNameMultiLevel.json',
            $this->getConfig(),
            false,
            false,
        );

        $object = new $className(['dog' => ['name' => 'woofy', 'puppy' => ['name' => 'fluffy']]]);

        $this->assertSame(
            ['dog' => ['name' => 'woofy', 'puppy' => ['name' => 'fluffy']]],
            $object->toArray(),
        );
    }

    /**
     * After setting the outer optional 'name' via setter it must appear in the output
     * without affecting the inner 'name'.
     */
    public function testSettingOuterOptionalPropertyAfterConstructionDoesNotAffectInner(): void
    {
        $className = $this->generateClassFromFile(
            'nestedObjectSamePropertyName.json',
            $this->getConfig(),
            false,
            false,
        );

        $object = new $className(['dog' => ['name' => 'woofy']]);
        $object->setName('Alice');

        $this->assertSame(
            ['name' => 'Alice', 'dog' => ['name' => 'woofy']],
            $object->toArray(),
        );
    }
}
