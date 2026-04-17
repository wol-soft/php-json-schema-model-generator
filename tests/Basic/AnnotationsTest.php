<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use ReflectionClass;

/**
 * Class AnnotationsTest
 *
 * Tests that $comment and examples schema keywords are emitted into generated PHPDoc.
 *
 * @package PHPModelGenerator\Tests\Basic
 */
class AnnotationsTest extends AbstractPHPModelGeneratorTestCase
{
    public function testCommentAppearsInGetterDocblock(): void
    {
        $className = $this->generateClassFromFile(
            'CommentOnly.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $doc = (new ReflectionClass($className))->getMethod('getValue')->getDocComment();

        $this->assertStringContainsString(
            'This is a developer note about the value field.',
            $doc,
        );
    }

    public function testSingleExampleAppearsInGetterDocblock(): void
    {
        $className = $this->generateClassFromFile(
            'ExamplesSingle.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $doc = (new ReflectionClass($className))->getMethod('getValue')->getDocComment();

        $this->assertStringContainsString('@example hello world', $doc);
    }

    public function testMultipleExamplesAllAppearInGetterDocblock(): void
    {
        $className = $this->generateClassFromFile(
            'ExamplesMultiple.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $doc = (new ReflectionClass($className))->getMethod('getValue')->getDocComment();

        $this->assertStringContainsString('@example first', $doc);
        $this->assertStringContainsString('@example second', $doc);
        $this->assertStringContainsString('@example third', $doc);
    }

    public function testNoAnnotationsProducesNoCommentOrExample(): void
    {
        $className = $this->generateClassFromFile(
            'NoAnnotations.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $doc = (new ReflectionClass($className))->getMethod('getValue')->getDocComment();

        $this->assertStringNotContainsString('@example', $doc);
        $this->assertStringNotContainsString('$comment', $doc);
    }

    public function testBothAnnotationsPresentTogether(): void
    {
        $className = $this->generateClassFromFile(
            'BothAnnotations.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $doc = (new ReflectionClass($className))->getMethod('getValue')->getDocComment();

        $this->assertStringContainsString('A developer note.', $doc);
        $this->assertStringContainsString('@example example one', $doc);
        $this->assertStringContainsString('@example example two', $doc);
    }

    public function testNonStringExamplesAreJsonEncoded(): void
    {
        $className = $this->generateClassFromFile(
            'ExamplesNonString.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $doc = (new ReflectionClass($className))->getMethod('getCount')->getDocComment();

        $this->assertStringContainsString('@example 42', $doc);
        $this->assertStringContainsString('@example 0', $doc);
        $this->assertStringContainsString('@example true', $doc);
    }
}
