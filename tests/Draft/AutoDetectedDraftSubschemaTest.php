<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Draft;

use PHPModelGenerator\Exception\Arrays\UnevaluatedItemsException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Exercises the default AutoDetectionDraft against schema documents that declare the
 * Draft 2019-09 `$schema` URI at the document root: the detected draft governs the whole
 * document, so keywords introduced in 2019-09 must be applied to every subschema — property
 * subschemas included — not only at the root level.
 *
 * This class deliberately carries no ApplicableDrafts attribute: without a registered draft
 * run, generateClassFromFile() keeps the GeneratorConfiguration's default draft
 * (AutoDetectionDraft) instead of injecting an explicit draft instance. The behaviour under
 * explicitly pinned drafts is covered by the draft-expanded test classes.
 */
class AutoDetectedDraftSubschemaTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * `unevaluatedProperties` declared on an object-typed property subschema must be applied
     * when the document root declares the 2019-09 `$schema` URI. The rejection surfaces
     * through the nested-object wrapping of the `child` property; the nested class name is
     * resolved from the generated files because the class-name generator appends a uniqid.
     */
    public function testDetectedDraftAppliesToNestedObjectSchemas(): void
    {
        $className = $this->generateClassFromFile('NestedObjectUnevaluatedProperties.json');
        $nestedClassName = $this->resolveNestedClassName($className);

        // Only the declared key is present — nothing is unevaluated inside `child`.
        $accepted = new $className(['child' => ['known' => 'a']]);
        $this->assertSame(['child' => ['known' => 'a']], $accepted->meta()->rawInput());

        // `extra` is claimed by nothing inside `child` and must be rejected.
        $this->expectException(NestedObjectException::class);
        $this->expectExceptionMessage(
            <<<MSG
            Invalid nested object for property child:
              - Provided JSON for {$nestedClassName} contains not allowed unevaluated properties [extra]
            MSG,
        );

        new $className(['child' => ['known' => 'a', 'extra' => 1]]);
    }

    /**
     * `unevaluatedItems` on an array property subschema must be applied when the document root
     * declares the 2019-09 `$schema` URI. With no sibling applicator every element is
     * unevaluated — the same behaviour the explicitly-pinned-draft tests assert for this
     * schema shape.
     */
    public function testDetectedDraftAppliesToArrayPropertySubschemas(): void
    {
        $className = $this->generateClassFromFile('ArrayPropertyUnevaluatedItems.json');

        $accepted = new $className(['tags' => []]);
        $this->assertSame(['tags' => []], $accepted->meta()->rawInput());

        $this->expectException(UnevaluatedItemsException::class);
        $this->expectExceptionMessage('Provided JSON for tags contains not allowed unevaluated items [#0]');

        new $className(['tags' => ['surplus']]);
    }
}
