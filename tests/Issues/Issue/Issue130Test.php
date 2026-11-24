<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\BuilderClassPostProcessor;
use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;
use ReflectionMethod;
use ReflectionNamedType;

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
    public function setUp(): void
    {
        parent::setUp();

        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new BuilderClassPostProcessor());
        };
    }

    /**
     * Two references with different names get a property of their own each, the builder methods are named after the
     * references.
     */
    public function testTwoPropertiesReferencingSameDefinitionProduceDistinctBuilderMethods(): void
    {
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

    /**
     * A recursive reference is a proxy of the property which is created for the definition (`next` resolves to the
     * property of `head`): the builder of the definition must name the methods after `next`, and the builder must not
     * modify the property of `head` (its validators are dropped from the builder property only).
     */
    public function testRecursiveReferenceProducesBuilderMethodsNamedAfterTheReference(): void
    {
        $className = $this->generateClassFromFile(
            'RecursiveRef.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $headType = (new ReflectionMethod($className, 'getHead'))->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $headType);

        $nodeBuilderClassName = $headType->getName() . 'Builder';
        $nodeBuilder = new $nodeBuilderClassName();

        $this->assertTrue(method_exists($nodeBuilder, 'getNext'));
        $this->assertTrue(method_exists($nodeBuilder, 'setNext'));
        $this->assertFalse(method_exists($nodeBuilder, 'setHead'));

        $node = $nodeBuilder->setName('first')->setNext(['name' => 'second'])->validate();
        $this->assertSame('second', $node->getNext()->getName());

        $builderClassName = $className . 'Builder';
        $validated = (new $builderClassName())
            ->setHead(['name' => 'first', 'next' => ['name' => 'second']])
            ->validate();
        $this->assertSame('second', $validated->getHead()->getNext()->getName());

        // The model still validates the property of head
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessage(
            <<<'MSG'
            Invalid nested object for property 'head':
              - Invalid type for 'name': requires 'string', got 'integer'
            MSG,
        );

        new $className(['head' => ['name' => 1]]);
    }
}
