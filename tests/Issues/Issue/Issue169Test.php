<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\AdditionalPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PatternPropertiesAccessorPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Issue #169: the composition branch-default reset (`allBranchDefaultAttributeMap`, built in
 * AbstractComposedPropertyValidator::setupBranchDefaultHelpers() and consumed by
 * ComposedItem.phptpl / ConditionalComposedItem.phptpl) must only reset attributes that were
 * actually transferred onto the containing class.
 *
 * For a bare (non-object-wrapped) composition property whose matching branch is itself
 * object-typed, internal bookkeeping properties such as `_additionalProperties` /
 * `_patternProperties` / `_patternPropertiesMap` are declared on the branch's own generated
 * class, not on the containing class. Resetting them via `$this->$branchDefaultAttr = ...`
 * creates a dynamic property on the wrong object — deprecated since PHP 8.2 and a future fatal
 * error.
 */
class Issue169Test extends AbstractIssueTestCase
{
    /**
     * Constructing the object-typed branch of a bare oneOf whose branch declares
     * `additionalProperties` must not emit a "Creation of dynamic property" deprecation for
     * `_additionalProperties`, and the additional properties must still be collected correctly
     * on the branch's own object.
     */
    public function testAdditionalPropertiesBranchOfBareOneOfDoesNotCreateDynamicProperty(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new AdditionalPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile('bareOneOfAdditionalProperties.json');

        $deprecations = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$deprecations): bool {
            $deprecations[] = $errstr;
            return true;
        });

        try {
            $object = new $className(['target' => ['name' => 'foo', 'extra' => 'bar']]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
        $this->assertSame('foo', $object->getTarget()->getName());
        $this->assertSame(['extra' => 'bar'], $object->getTarget()->additionalProperties()->getAll());
    }

    /**
     * The array-typed branch of the same bare oneOf must remain unaffected by the fix: the
     * sibling object branch's internal bookkeeping properties are simply excluded from the reset
     * map, they never had anything to do with the array branch's own value.
     */
    public function testArrayBranchOfBareOneOfIsUnaffected(): void
    {
        $className = $this->generateClassFromFile('bareOneOfAdditionalProperties.json');

        $object = new $className(['target' => ['Hello', 'World']]);

        $this->assertSame(['Hello', 'World'], $object->getTarget());
    }

    /**
     * Same bug via `patternProperties` instead of `additionalProperties`: constructing the
     * object-typed branch must not emit "Creation of dynamic property" deprecations for
     * `_patternProperties` / `_patternPropertiesMap`, and the pattern-matched property must
     * still be collected correctly on the branch's own object.
     */
    public function testPatternPropertiesBranchOfBareOneOfDoesNotCreateDynamicProperty(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PatternPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile('bareOneOfPatternProperties.json');

        $deprecations = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$deprecations): bool {
            $deprecations[] = $errstr;
            return true;
        });

        try {
            $object = new $className(['target' => ['name' => 'foo', 'x-custom' => 'bar']]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
        $this->assertSame('foo', $object->getTarget()->getName());
        $this->assertSame(['x-custom' => 'bar'], $object->getTarget()->patternProperties()->get('^x-'));
    }

    /**
     * The string-typed branch of the same bare oneOf must remain unaffected by the fix.
     */
    public function testStringBranchOfBareOneOfIsUnaffected(): void
    {
        $className = $this->generateClassFromFile('bareOneOfPatternProperties.json');

        $object = new $className(['target' => 'plain string']);

        $this->assertSame('plain string', $object->getTarget());
    }
}
