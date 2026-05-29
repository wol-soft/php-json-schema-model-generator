<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Regression test for https://github.com/wol-soft/php-json-schema-model-generator/issues/130
 *
 * When two properties of an object reference the same nested schema via $ref, the
 * BuilderClassPostProcessor used to emit a duplicate getter/setter pair under the first
 * property's name, producing a fatal "Cannot redeclare" error at require time.
 *
 * The defect was in PropertyProxy: its fluent setters returned the wrapped inner property
 * rather than $this, so a chain like `(clone $proxy)->setReadOnly(false)->...` silently
 * escaped to the underlying Property — which still carried the first property's name.
 */
class Issue130Test extends AbstractIssueTestCase
{
    public function testTwoPropertiesReferencingSameDefinitionProduceDistinctBuilderMethods(): void
    {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new BuilderClassPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'DuplicateRef.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $builderClassName = $className . 'Builder';
        $builderObject = new $builderClassName();

        // Both setter/getter pairs must exist with distinct names derived from the
        // outer property names — not collapsed onto the referenced definition's name.
        $this->assertTrue(method_exists($builderObject, 'getCollectionList'));
        $this->assertTrue(method_exists($builderObject, 'setCollectionList'));
        $this->assertTrue(method_exists($builderObject, 'getCollectionListExclude'));
        $this->assertTrue(method_exists($builderObject, 'setCollectionListExclude'));

        // Setting one must not bleed into the other, and the validated model must hold
        // two distinct nested objects.
        $builderObject
            ->setCollectionList(['items' => ['a', 'b']])
            ->setCollectionListExclude(['items' => ['c']]);

        $validated = $builderObject->validate();

        $this->assertSame(['a', 'b'], $validated->getCollectionList()->getItems());
        $this->assertSame(['c'], $validated->getCollectionListExclude()->getItems());
        $this->assertNotSame($validated->getCollectionList(), $validated->getCollectionListExclude());
    }
}
