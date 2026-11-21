<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Objects;

use PHPModelGenerator\Exception\Generic\EnumException;
use PHPModelGenerator\Exception\Generic\InvalidConstException;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\Object\AdditionalPropertiesException;
use PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException;
use PHPModelGenerator\Exception\String\FormatException;
use PHPModelGenerator\Format\FormatValidatorFromRegEx;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Support\ApplicableDrafts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Strings of the schema with a line break or another control character (property names, patterns, enum, const and
 * default values, keys of object values, format patterns) are embedded into the generated code as they are written.
 * The generated code is indented while it is rendered: a line break inside a PHP string literal would receive the
 * indentation of the following line and change the string.
 */
#[ApplicableDrafts]
class ControlCharactersInGeneratedCodeTest extends AbstractPHPModelGeneratorTestCase
{
    #[DataProvider('validationMethodDataProvider')]
    public function testStringsWithControlCharactersAreEmbeddedAsWritten(GeneratorConfiguration $configuration): void
    {
        $className = $this->generateClassFromFile('ControlCharacters.json', $configuration);

        $object = new $className([
            "line\nbreak" => 'value',
            'choice' => "foo\nbar",
            'fixed' => "x\ny",
            'objectChoice' => ["key\nwith break" => 1],
            "p\n" => 5,
        ]);

        $this->assertSame("foo\nbar", $object->getChoice());
        $this->assertSame("x\ny", $object->getFixed());
        $this->assertSame("line1\nline2", $object->getWithDefault());
        $this->assertSame(["key\nwith break" => 1], $object->getObjectChoice());
        $this->assertSame('value', $object->meta()->rawInput()["line\nbreak"]);
        $this->assertSame(5, $object->meta()->rawInput()["p\n"]);

        foreach (["tab\there", "crlf\r\nend"] as $choice) {
            $this->assertSame($choice, (new $className(['choice' => $choice]))->getChoice());
        }

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["line\nbreak" => 1],
            $this->getExpectedExceptionClass($configuration, InvalidTypeException::class),
            <<<'MSG'
            Invalid type for 'line
            break': requires 'string', got 'integer'
            MSG,
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['choice' => 'foo'],
            $this->getExpectedExceptionClass($configuration, EnumException::class),
            'Value for \'choice\' must be one of ["foo\nbar","tab\there","crlf\r\nend",null], got "foo"',
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['fixed' => 'x'],
            $this->getExpectedExceptionClass($configuration, InvalidConstException::class),
            'Value for \'fixed\' must be "x\ny", got "x"',
        );

        $this->assertInstantiationFailsWithMessage(
            $className,
            ["p\n" => 'five'],
            $this->getExpectedExceptionClass($configuration, InvalidPatternPropertiesException::class),
            <<<MSG
            Provided JSON for '$className' contains invalid pattern properties
              - invalid property 'p
            ' matching pattern '^p
            '
                * Invalid type for 'pattern property': requires 'int', got 'string'
            MSG,
        );

        // a key which doesn't match the pattern is an additional property
        $this->assertInstantiationFailsWithMessage(
            $className,
            ["q\n" => 5],
            $this->getExpectedExceptionClass($configuration, AdditionalPropertiesException::class),
            <<<MSG
            Provided JSON for '$className' contains not allowed additional properties ['q
            ']
            MSG,
        );
    }

    public function testFormatPatternWithControlCharactersIsEmbeddedAsWritten(): void
    {
        $className = $this->generateClassFromFile(
            'FormatWithControlCharacters.json',
            (new GeneratorConfiguration())
                ->setCollectErrors(false)
                ->addFormat('lineBreak', new FormatValidatorFromRegEx("/^a\nb$/")),
        );

        $this->assertSame("a\nb", (new $className(['value' => "a\nb"]))->getValue());

        $this->assertInstantiationFailsWithMessage(
            $className,
            ['value' => 'ab'],
            FormatException::class,
            "Value for 'value' must match the format 'lineBreak'",
        );
    }
}
