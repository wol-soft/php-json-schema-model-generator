<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use Exception;
use PHPModelGenerator\Exception\Arrays\ContainsException;
use PHPModelGenerator\Exception\Arrays\MinContainsException;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Issue #186: AutoDetectionDraft::getDraftForSchema() read the $schema keyword off whichever
 * JsonSchema node it was handed, but $schema only ever appears on a document root - every
 * subschema reached via JsonSchema::navigate()/withJson() (properties, $ref targets, composition
 * branches, ...) never carried $schema, so draft detection always fell back to Draft 07 outside
 * the literal document root, silently dropping Draft 2019-09-only behavior like
 * minContains/maxContains.
 *
 * Fixed by having JsonSchema track the $schema URI in effect for each node (its own if declared,
 * otherwise inherited from the nearest ancestor). This also covers the JSON Schema core spec's
 * exception to root-only $schema: a subschema with its own $id may declare a different $schema
 * for itself and its descendants (an "embedded schema resource"), which the fix must honour
 * rather than clobber with the document root's dialect.
 */
class Issue186Test extends AbstractIssueTestCase
{
    /**
     * "legacyTags" $refs a $defs entry that declares its own draft-07 $schema although the
     * document root declares 2019-09 (a valid embedded-schema-resource override per the core
     * spec), while "tags" $refs a sibling $defs entry with no override and therefore inherits
     * 2019-09. Both entries carry identical contains/minContains/maxContains constraints, so any
     * behavioural difference between them is attributable only to the override actually taking
     * effect - and to AutoDetectionDraft resolving the draft per-node instead of only for the
     * document root.
     */
    public function testAutoDetectionDraftHonoursASchemaOverrideDeclaredByAnEmbeddedResource(): void
    {
        $className = $this->generateClassFromFile('AutoDetectionEmbeddedResourceSchemaOverride.json');

        // 3 matches for "tags" (within minContains=2 .. maxContains=4 under 2019-09) and 1 match
        // for "legacyTags" (only the base "contains" >= 1 match applies under the draft-07 override)
        $object = new $className(['tags' => ['a', 'b', 1], 'legacyTags' => ['a', 1, 2]]);
        $this->assertSame(['a', 'b', 1], $object->getTags());
        $this->assertSame(['a', 1, 2], $object->getLegacyTags());

        // "tags" inherited 2019-09: 1 match < minContains=2 still throws
        try {
            new $className(['tags' => ['a', 1, 2], 'legacyTags' => ['a', 1, 2]]);
            $this->fail('Expected MinContainsException for "tags" with one matching item');
        } catch (Exception $exception) {
            $this->assertInstanceOf(MinContainsException::class, $exception);
        }

        // "legacyTags" is still held to the base "contains" constraint under the draft-07
        // override - the override drops minContains/maxContains specifically, not all validation
        $this->expectException(ContainsException::class);
        new $className(['tags' => ['a', 'b', 1], 'legacyTags' => [1, 2, 3]]);
    }
}
