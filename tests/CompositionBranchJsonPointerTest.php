<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests;

use PHPModelGenerator\Attributes\JsonPointer;
use ReflectionClass;

/**
 * A property transferred from a composition branch into the merge class must carry a #[JsonPointer]
 * that reflects its position within the composition, not the location it happens to be defined at.
 *
 * For a $ref'd branch the synthesizer previously used the referenced definition's pointer
 * (e.g. /$defs/Extra/properties/age) instead of the branch position (/allOf/0/properties/age).
 */
class CompositionBranchJsonPointerTest extends AbstractPHPModelGeneratorTestCase
{
    public function testTransferredPropertyFromRefBranchHasCorrectJsonPointer(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'allOf' => [
                ['$ref' => '#/$defs/Extra'],
            ],
            '$defs' => [
                'Extra' => [
                    'type' => 'object',
                    'properties' => [
                        'age' => ['type' => 'integer'],
                    ],
                ],
            ],
        ]);

        $className = $this->generateClass($schema);
        $ageAttr = (new ReflectionClass($className))->getProperty('age')->getAttributes(JsonPointer::class);

        $this->assertCount(1, $ageAttr);
        $this->assertSame(
            '/allOf/0/properties/age',
            $ageAttr[0]->getArguments()[0],
            'Property "age" from a $ref allOf branch must carry the composition-branch pointer',
        );
    }

    public function testTransferredPropertyFromInlineBranchHasCorrectJsonPointer(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'allOf' => [
                [
                    'properties' => [
                        'age' => ['type' => 'integer'],
                    ],
                ],
            ],
        ]);

        $className = $this->generateClass($schema);
        $reflection = new ReflectionClass($className);

        $this->assertSame(
            '/properties/name',
            $reflection->getProperty('name')->getAttributes(JsonPointer::class)[0]->getArguments()[0],
        );
        $this->assertSame(
            '/allOf/0/properties/age',
            $reflection->getProperty('age')->getAttributes(JsonPointer::class)[0]->getArguments()[0],
        );
    }
}
