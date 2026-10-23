<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Model;

use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\SchemaDependencyValidator;
use PHPUnit\Framework\TestCase;

/**
 * A property which is declared by a schema takes precedence over a property which was only transferred from a
 * composition branch or a dependency schema, independent of the order in which the properties are registered.
 */
class SchemaPropertyPrecedenceTest extends TestCase
{
    private function createSchema(): Schema
    {
        return new Schema('', '', 'Test', new JsonSchema('', []));
    }

    private function createProperty(string $name, ?string $type): Property
    {
        return new Property(
            $name,
            $type === null ? null : new PropertyType($type),
            new JsonSchema('', $type === null ? [] : ['type' => $type]),
        );
    }

    private function createUnresolvedProperty(string $name): Property
    {
        $jsonSchema = new JsonSchema('', ['type' => 'string']);

        return new class ($name, new PropertyType('string'), $jsonSchema) extends Property {
            public function __construct(string $name, PropertyType $type, JsonSchema $jsonSchema)
            {
                parent::__construct($name, $type, $jsonSchema);

                $this->isResolved = false;
            }
        };
    }

    public function testDeclaredPropertyReplacesTransferredPropertyAndKeepsItsPosition(): void
    {
        $schema = $this->createSchema();
        $declared = $this->createProperty('transferred', 'string');

        $schema->addProperty($this->createProperty('first', 'integer'));
        $schema->addProperty($this->createProperty('transferred', null), SchemaDependencyValidator::class);
        $schema->addProperty($this->createProperty('last', 'integer'));

        $this->assertFalse($schema->isRootRegistered('transferred'));

        $schema->addProperty($declared);

        $this->assertSame(['first', 'transferred', 'last'], array_keys($schema->getProperties()));
        $this->assertSame($declared, $schema->getProperty('transferred'));
        $this->assertTrue($schema->isRootRegistered('transferred'));
    }

    public function testTransferredPropertyDoesNotReplaceDeclaredProperty(): void
    {
        $schema = $this->createSchema();
        $declared = $this->createProperty('declared', 'string');

        $schema->addProperty($declared);
        $schema->addProperty($this->createProperty('declared', null), SchemaDependencyValidator::class);

        $this->assertSame($declared, $schema->getProperty('declared'));
        $this->assertSame('string', $schema->getProperty('declared')->getType()?->getNames()[0]);
    }

    public function testReplacementWhichIsNotResolvedYetKeepsTheResolutionBookkeeping(): void
    {
        $schema = $this->createSchema();
        $unresolved = $this->createUnresolvedProperty('transferred');

        $schema->addProperty($this->createProperty('transferred', null), SchemaDependencyValidator::class);
        $schema->addProperty($unresolved);

        $resolvedCallbackCalls = 0;
        $schema->onAllPropertiesResolved(static function () use (&$resolvedCallbackCalls): void {
            $resolvedCallbackCalls++;
        });

        $this->assertSame(0, $resolvedCallbackCalls);

        $unresolved->resolve();

        $this->assertSame(1, $resolvedCallbackCalls);
    }
}
