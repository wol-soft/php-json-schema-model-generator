<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

class Issue79Test extends AbstractIssueTestCase
{
    public function testCombinedReferenceAndObjectDefinition(): void
    {
        $className = $this->generateClassFromFile('person.json');

        $object = new $className(['name' => 'Hans', 'street' => 'A28']);

        $this->assertSame('Hans', $object->getName());
        $this->assertSame('A28', $object->getStreet());
        $this->assertNull($object->getAge());
        $this->assertNull($object->getZip());
    }

    /**
     * @dataProvider invalidInputDataProvider
     */
    public function testCombinedReferenceAndObjectDefinitionWithInvalidDataThrowsAnException(array $data): void
    {
        $className = $this->generateClassFromFile(
            'person.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $this->expectException(ErrorRegistryException::class);

        new $className($data);
    }

    public static function invalidInputDataProvider(): array
    {
        return [
            'empty input'       => [[]],
            'empty reference'   => [['name' => 'Hans']],
            'empty object'      => [['street' => 'A28']],
            'invalid reference' => [['name' => 'Hans', 'street' => 'A28', 'zip' => 'ABC']],
            'invalid object'    => [['name' => 'Hans', 'street' => 'A28', 'age' => 'ABC']],
        ];
    }
}
