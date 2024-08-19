<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue77Test extends AbstractIssueTestCase
{
    public function testCompositionValidatorOnPropertyGeneratesNoDynamicProperty(): void
    {
        $className = $this->generateClassFromFile('dynamicProperty.json', (new GeneratorConfiguration())->setImmutable(false));

        $object = new $className(['values' => [1, 10, 1000], 'pet' => ['type' => 'dog', 'age' => 0, 'name' => 'Hans']]);

        $this->assertSame([1, 10, 1000], $object->getValues());
        $this->assertSame('dog', $object->getPet()->getType());
        $this->assertSame(0, $object->getPet()->getAge());
        $this->assertSame('Hans', $object->getPet()->getName());

        $this->expectExceptionMessage('Value for item of array values must not be smaller than 1');

        new $className(['values' => [0]]);
    }
}
