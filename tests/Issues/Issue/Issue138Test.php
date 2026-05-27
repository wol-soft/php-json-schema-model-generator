<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Issues\Issue;

use PHPModelGenerator\Tests\Issues\AbstractIssueTestCase;

/**
 * Issue #138: Property descriptions containing {{curly_braces}} must not cause
 * a template engine UndefinedSymbolException.
 */
class Issue138Test extends AbstractIssueTestCase
{
    public function testDescriptionWithCurlyBracesRendersSuccessfully(): void
    {
        $className = $this->generateClassFromFile('descriptionWithCurlyBraces.json');

        $object = new $className(['name' => 'test']);
        $this->assertSame('test', $object->getName());
    }

    public function testDescriptionIsPreservedInGeneratedDocblock(): void
    {
        $className = $this->generateClassFromFile('descriptionWithCurlyBraces.json');

        $object = new $className(['name' => 'test']);

        $refl = new \ReflectionProperty($className, 'name');
        $docComment = $refl->getDocComment();

        // The {{product_name}} must be preserved verbatim in the docblock
        $this->assertStringContainsString(
            '{{product_name}}',
            $docComment,
            'The generated docblock must preserve {{product_name}} verbatim',
        );
    }
}
