<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\NestedObjectException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * A pattern of patternProperties is part of the generated code: it is embedded in a string literal. A pattern which
 * contains a quote or a backslash must neither break the generated code nor change the regular expression which is
 * evaluated.
 */
class QuotedPatternPropertiesTest extends AbstractPHPModelGeneratorTestCase
{
    public function testPatternsWithQuotesAndBackslashesAreEvaluatedAsWritten(): void
    {
        $className = $this->generateClassFromFile(
            'QuotedPatternProperties.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className([
            'denied' => ['yes' => 1],
            // the pattern ^a\\b$ matches the key a\b only: the keys a and ab are no match
            'deniedBackslash' => ['a' => 1, 'ab' => 2],
            // the keys matching a pattern aren't additional properties
            'closed' => ['known' => 'k', "it's" => 'i', 'b\\x' => 3],
        ]);
        $this->assertSame(['yes' => 1], $object->getDenied()->meta()->rawInput());
        $this->assertSame(['a' => 1, 'ab' => 2], $object->getDeniedBackslash()->meta()->rawInput());
        $this->assertSame(
            ['known' => 'k', "it's" => 'i', 'b\\x' => 3],
            $object->getClosed()->meta()->rawInput(),
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['denied' => ["no's" => 1]],
            NestedObjectException::class,
            <<<'MSG'
            Invalid nested object for property 'denied':
              - Provided JSON for '%class%' contains invalid pattern properties
                  - invalid property 'no's' matching pattern '^no's'
                    * Value for 'no's' is not allowed
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['deniedBackslash' => ['a\\b' => 1]],
            NestedObjectException::class,
            <<<'MSG'
            Invalid nested object for property 'deniedBackslash':
              - Provided JSON for '%class%' contains invalid pattern properties
                  - invalid property 'a\b' matching pattern '^a\\b$'
                    * Value for 'a\b' is not allowed
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['closed' => ['known' => 'k', "it's" => 'i', 'other' => 1]],
            NestedObjectException::class,
            <<<'MSG'
            Invalid nested object for property 'closed':
              - Provided JSON for '%class%' contains not allowed additional properties ['other']
            MSG,
        );
    }
}
