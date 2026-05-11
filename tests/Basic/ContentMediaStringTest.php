<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Filter\InvalidFilterValueException;
use PHPModelGenerator\Exception\String\FormatException;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\ValueObject\ImmutableMediaString;
use PHPModelGenerator\ValueObject\MediaString;

class ContentMediaStringTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * contentMediaType only: property becomes MediaString; plain property stays string.
     * Also covers: raw string wrapped, MediaString pass-through, neither keyword = plain string,
     * nullable property stores null, required property triggers validation when missing.
     */
    public function testContentMediaTypeOnly(): void
    {
        $className = $this->generateClassFromFile(
            'ContentMediaType.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // Must provide requiredContent to construct successfully
        $object = new $className(['requiredContent' => 'required-data']);

        // Getter/setter exist on mutable property
        $this->assertTrue(is_callable([$object, 'getContent']));
        $this->assertTrue(is_callable([$object, 'setContent']));

        // Plain property remains a string (neither keyword → no wrapper)
        $object->setPlain('raw');
        $this->assertSame('raw', $object->getPlain());

        // Raw string → wrapped in MediaString with schema-defined mediaType
        $object->setContent('hello');
        $mediaString = $object->getContent();

        $this->assertInstanceOf(MediaString::class, $mediaString);
        $this->assertSame('image/png', $mediaString->getMediaType());
        $this->assertNull($mediaString->getEncoding());
        $this->assertSame('hello', $mediaString->getValue());
        $this->assertSame('hello', (string) $mediaString);

        // Pre-existing MediaString with matching metadata → returned as the same instance
        $existing = new MediaString('world', 'image/png');
        $object->setContent($existing);
        $this->assertSame($existing, $object->getContent());

        // setValue() on MediaString works (mutable)
        $mediaString->setValue('updated');
        $this->assertSame('updated', $mediaString->getValue());

        // --- nullable property ---

        // Not set → null (optional property)
        $this->assertNull($object->getNullableContent());

        // Set to string → wrapped
        $object->setNullableContent('data');
        $this->assertInstanceOf(MediaString::class, $object->getNullableContent());

        // Set to null → null
        $object->setNullableContent(null);
        $this->assertNull($object->getNullableContent());

        // --- required property ---

        // Provided required value → wrapped
        $this->assertInstanceOf(MediaString::class, $object->getRequiredContent());
        $this->assertSame('required-data', $object->getRequiredContent()->getValue());

        // Missing required property → throws
        $this->expectException(ErrorRegistryException::class);
        $this->expectExceptionMessageMatches('/Missing required value for requiredContent/');
        new $className([]);
    }

    /**
     * contentEncoding only: getMediaType() returns null, getEncoding() returns the schema value.
     */
    public function testContentEncodingOnly(): void
    {
        $className = $this->generateClassFromFile(
            'ContentEncoding.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className([]);
        $object->setContent('aGVsbG8=');
        $mediaString = $object->getContent();

        $this->assertInstanceOf(MediaString::class, $mediaString);
        $this->assertNull($mediaString->getMediaType());
        $this->assertSame('base64', $mediaString->getEncoding());
        $this->assertSame('aGVsbG8=', $mediaString->getValue());
    }

    /**
     * Both contentMediaType and contentEncoding present: both values carried on the wrapper.
     */
    public function testContentMediaBothKeywords(): void
    {
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className([]);
        $object->setContent('e30=');
        $mediaString = $object->getContent();

        $this->assertInstanceOf(MediaString::class, $mediaString);
        $this->assertSame('application/json', $mediaString->getMediaType());
        $this->assertSame('base64', $mediaString->getEncoding());
    }

    /**
     * readOnly and writeOnly: both produce ImmutableMediaString.
     * readOnly has no setter; writeOnly has no getter.
     * Also covers ImmutableMediaString pass-through via setter.
     */
    public function testReadOnlyAndWriteOnlyUseImmutableMediaString(): void
    {
        $className = $this->generateClassFromFile(
            'ContentMediaReadOnly.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        // readOnly: no setter
        $this->assertFalse(is_callable([new $className([]), 'setReadOnlyContent']));

        $object = new $className(['readOnlyContent' => 'image-data']);
        $immutable = $object->getReadOnlyContent();

        $this->assertInstanceOf(ImmutableMediaString::class, $immutable);
        $this->assertSame('image/png', $immutable->getMediaType());
        $this->assertNull($immutable->getEncoding());
        $this->assertSame('image-data', $immutable->getValue());
        $this->assertFalse(is_callable([$immutable, 'setValue']));

        // writeOnly: getter absent, setter present
        $object = new $className([]);
        $this->assertFalse(is_callable([$object, 'getWriteOnlyContent']));
        $this->assertTrue(is_callable([$object, 'setWriteOnlyContent']));

        // Raw string → ImmutableMediaString via setter
        $object->setWriteOnlyContent('payload');

        // Pre-existing ImmutableMediaString with matching metadata → passes through
        $existing = new ImmutableMediaString('payload', 'image/png');
        $object->setWriteOnlyContent($existing);
    }

    /**
     * Global immutability — wrapper is ImmutableMediaString regardless of schema readOnly flag.
     */
    public function testGlobalImmutabilityUsesImmutableMediaString(): void
    {
        // Default GeneratorConfiguration has immutability enabled
        $className = $this->generateClassFromFile('ContentMediaType.json');

        $object = new $className(['content' => 'data', 'requiredContent' => 'req']);
        $immutable = $object->getContent();

        $this->assertInstanceOf(ImmutableMediaString::class, $immutable);
        $this->assertSame('image/png', $immutable->getMediaType());
        $this->assertSame('data', $immutable->getValue());

        // Global immutability: no setter generated
        $this->assertFalse(is_callable([$object, 'setContent']));
    }

    /**
     * SerializationPostProcessor: MediaString serializes back to the raw string value.
     * PopulatePostProcessor: populate assigns a MediaString-wrapped value.
     */
    public function testSerializationAndPopulate(): void
    {
        $className = $this->generateClassFromFile(
            'ContentMediaType.json',
            (new GeneratorConfiguration())->setImmutable(false)->setSerialization(true),
        );

        $object = new $className(['content' => 'image-data', 'plain' => 'text', 'requiredContent' => 'req']);

        $this->assertInstanceOf(SerializationInterface::class, $object);

        $result = $object->toArray();
        $this->assertSame('image-data', $result['content']);
        $this->assertSame('text', $result['plain']);

        // --- PopulatePostProcessor ---

        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'ContentMediaType.json',
            (new GeneratorConfiguration())->setImmutable(false),
        );

        $object = new $className(['requiredContent' => 'req']);
        $object->populate(['content' => 'populated-data']);

        $mediaString = $object->getContent();
        $this->assertInstanceOf(MediaString::class, $mediaString);
        $this->assertSame('populated-data', $mediaString->getValue());
        $this->assertSame('image/png', $mediaString->getMediaType());
    }

    /**
     * contentMediaType combined with format: format validates the raw string before wrapping.
     * A valid date string passes both format check and contentMediaType wrapping.
     * An invalid date string fails at format validation.
     * A pre-existing MediaString bypasses the format check.
     */
    public function testFormatCombinedWithContentMediaType(): void
    {
        $className = $this->generateClassFromFile(
            'ContentMediaWithFormat.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        // Valid date string → passes format check, then wrapped in MediaString
        $object = new $className([]);
        $object->setContent('2024-03-15');
        $mediaString = $object->getContent();

        $this->assertInstanceOf(MediaString::class, $mediaString);
        $this->assertSame('2024-03-15', $mediaString->getValue());
        $this->assertSame('text/plain', $mediaString->getMediaType());

        // Invalid date string → FormatException thrown (format checked on raw string)
        $this->expectException(FormatException::class);
        $object->setContent('not-a-date');
    }

    /**
     * Pre-existing MediaString with mismatched mediaType or encoding → InvalidFilterValueException.
     * The filter throws InvalidArgumentException internally; the pipeline wraps it.
     */
    public function testPassingMediaStringWithMismatchedMediaTypeThrows(): void
    {
        $className = $this->generateClassFromFile(
            'ContentMediaType.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className(['requiredContent' => 'req']);

        $this->expectException(InvalidFilterValueException::class);
        $this->expectExceptionMessageMatches('/mediaType mismatch/');
        $object->setContent(new MediaString('data', 'text/plain'));
    }

    public function testPassingMediaStringWithMismatchedEncodingThrows(): void
    {
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className([]);

        $this->expectException(InvalidFilterValueException::class);
        $this->expectExceptionMessageMatches('/encoding mismatch/');
        $object->setContent(new MediaString('data', 'application/json', 'utf-8'));
    }
}
