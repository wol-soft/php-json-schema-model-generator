<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Property names (and definition names) which spell a schema keyword such as "$schema" or "$id"
 * must be treated as plain names. The map holding them ("properties", "definitions", ...) is not
 * a schema itself, so the value found under such a name is a property definition and must never
 * be interpreted as the keyword.
 */
class SchemaKeywordPropertyNamesTest extends AbstractPHPModelGeneratorTestCase
{
    public function testPropertiesNamedLikeSchemaKeywordsAreGeneratedAsPlainProperties(): void
    {
        $className = $this->generateClassFromFile('SchemaKeywordPropertyNames.json');

        $object = new $className([
            '$schema' => './schema.json',
            '$id' => 'scenario-1',
            'name' => 'Scenario',
            'fromDefinitions' => 'referenced',
            'nested' => ['$schema' => 1, '$id' => 2],
            '$id1' => 3,
        ]);

        $this->assertSame('./schema.json', $object->getSchema());
        $this->assertSame('scenario-1', $object->getId());
        $this->assertSame('Scenario', $object->getName());
        $this->assertSame('referenced', $object->getFromDefinitions());
        $this->assertSame(1, $object->getNested()->getSchema());
        $this->assertSame(2, $object->getNested()->getId());

        // the property definitions behind the keyword-like names are still enforced
        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessage("Invalid type for '\$schema': requires 'string', got 'integer'");

        new $className(['$schema' => 5]);
    }
}
