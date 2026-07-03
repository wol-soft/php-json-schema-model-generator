<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPModelGenerator\Tests\Support\JsonSchemaDraft;
use PHPUnit\Framework\Attributes\DataProvider;

class Issue79Test extends AbstractIssueTestCase
{
    // Draft 2019-09+: $ref siblings apply — all four properties (from ref and from sibling
    // properties/required) must appear on the generated class.
    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    public function testCombinedReferenceAndObjectDefinition(): void
    {
        $className = $this->generateClassFromFile('person.json');

        $object = new $className(['name' => 'Hans', 'street' => 'A28']);

        $this->assertSame('Hans', $object->getName());
        $this->assertSame('A28', $object->getStreet());
        $this->assertNull($object->getAge());
        $this->assertNull($object->getZip());
    }

    #[ApplicableDrafts(from: JsonSchemaDraft::DRAFT_2019_09)]
    #[DataProvider('invalidInputDataProvider')]
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

    // Draft 07: $ref siblings are silently ignored. Only the referenced schema's properties
    // (street + zip from the location definition) appear on the generated class.
    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    public function testDraft07SiblingsIgnoredRefOnlyPropertiesPresent(): void
    {
        $className = $this->generateClassFromFile('person.json');

        $object = new $className(['street' => 'A28']);

        $this->assertSame('A28', $object->getStreet());
        $this->assertNull($object->getZip());
        $this->assertFalse(method_exists($object, 'getName'));
        $this->assertFalse(method_exists($object, 'getAge'));
    }

    #[ApplicableDrafts(until: JsonSchemaDraft::DRAFT_07)]
    #[DataProvider('invalidInputDraft07DataProvider')]
    public function testDraft07WithInvalidDataThrowsAnException(array $data): void
    {
        $className = $this->generateClassFromFile(
            'person.json',
            (new GeneratorConfiguration())->setCollectErrors(true),
        );

        $this->expectException(ErrorRegistryException::class);

        new $className($data);
    }

    public static function invalidInputDraft07DataProvider(): array
    {
        return [
            // Only the ref's properties exist: street is required, zip is optional
            'missing required street' => [[]],
            'invalid zip type'        => [['street' => 'A28', 'zip' => 'ABC']],
        ];
    }
}
