<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Basic;

use InvalidArgumentException;
use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\Filter\InvalidFilterValueException;
use PHPModelGenerator\Exception\String\ContentException;
use PHPModelGenerator\Exception\String\FormatException;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPModelGenerator\Tests\Fixtures\AlwaysInvalidContentValidator;
use PHPModelGenerator\Tests\Fixtures\AlwaysValidContentValidator;
use PHPModelGenerator\ValueObject\ImmutableMediaString;
use PHPModelGenerator\ValueObject\MediaString;
use RuntimeException;

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

    /**
     * No content validator registered: property with contentMediaType/contentEncoding validates
     * without content check — no ContentException thrown.
     */
    public function testNoContentValidatorRegistered(): void
    {
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())->setImmutable(false)->setCollectErrors(false),
        );

        $object = new $className([]);
        $object->setContent('any-value');

        $this->assertInstanceOf(MediaString::class, $object->getContent());
        $this->assertSame('any-value', $object->getContent()->getValue());
    }

    /**
     * Exact-match validator registered for (mediaType, encoding): fires on matching property.
     * Validator that passes (no throw) → no exception.
     * Validator that throws → ContentException with correct media type, encoding, and $previous.
     * Null value on nullable property → content validator does not run.
     * Array form of addContentValidator: registers for every combination of the Cartesian product.
     * Invalid array elements → InvalidArgumentException at registration time.
     */
    public function testExactMatchValidatorAndNullBypass(): void
    {
        // Validator passes — no exception
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator('application/json', 'base64', new AlwaysValidContentValidator()),
        );

        $object = new $className([]);
        $object->setContent('e30=');
        $this->assertInstanceOf(MediaString::class, $object->getContent());

        // Validator throws → ContentException with correct fields and $previous set
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator('application/json', 'base64', new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);

        try {
            $object->setContent('not-valid');
            $this->fail('Expected ContentException');
        } catch (ContentException $exception) {
            $this->assertSame('application/json', $exception->getExpectedMediaType());
            $this->assertSame('base64', $exception->getExpectedEncoding());
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertStringContainsString('not-valid', $exception->getPrevious()->getMessage());
        }

        // --- null on nullable property → content validator does not run ---

        $className = $this->generateClassFromFile(
            'ContentMediaNullable.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator('application/json', 'base64', new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);
        $object->setContent(null);
        $this->assertNull($object->getContent());

        // --- array form: register for multiple encodings at once ---

        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator('application/json', ['base64', 'utf-8'], new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);
        $this->expectException(ContentException::class);
        $object->setContent('e30=');
    }

    /**
     * addContentValidator rejects array arguments containing non-strings or empty strings.
     */
    public function testAddContentValidatorRejectsInvalidArrayElements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new GeneratorConfiguration())->addContentValidator(['application/json', ''], 'base64', new AlwaysValidContentValidator());
    }

    public function testAddContentValidatorRejectsNonStringInArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new GeneratorConfiguration())->addContentValidator('application/json', ['base64', null], new AlwaysValidContentValidator());
    }

    /**
     * Media-type wildcard ($mediaType, null): fires when encoding differs or is absent.
     * Encoding wildcard (null, $encoding): fires when media type differs or is absent.
     * Full wildcard (null, null): fires for any combination.
     */
    public function testWildcardValidators(): void
    {
        // ($mediaType, null) wildcard fires for the property with encoding=base64
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator('application/json', null, new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);
        $this->expectException(ContentException::class);
        $object->setContent('data');
    }

    public function testEncodingWildcardFires(): void
    {
        // (null, $encoding) wildcard fires for the property with mediaType=application/json
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator(null, 'base64', new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);
        $this->expectException(ContentException::class);
        $object->setContent('data');
    }

    public function testFullWildcardFires(): void
    {
        // (null, null) wildcard fires for any property with a content keyword
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator(null, null, new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);
        $this->expectException(ContentException::class);
        $object->setContent('data');
    }

    /**
     * Specificity: exact match takes priority over media-type wildcard.
     * Exact match = AlwaysValid; media-type wildcard = AlwaysInvalid → no exception.
     * Specificity: media-type wildcard over encoding wildcard.
     * Specificity: encoding wildcard over full wildcard.
     */
    public function testSpecificityOrder(): void
    {
        // Exact match (valid) beats media-type wildcard (invalid)
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator('application/json', 'base64', new AlwaysValidContentValidator())
                ->addContentValidator('application/json', null, new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);
        $object->setContent('e30=');
        $this->assertInstanceOf(MediaString::class, $object->getContent());

        // Media-type wildcard (valid) beats encoding wildcard (invalid)
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator('application/json', null, new AlwaysValidContentValidator())
                ->addContentValidator(null, 'base64', new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);
        $object->setContent('e30=');
        $this->assertInstanceOf(MediaString::class, $object->getContent());

        // Encoding wildcard (valid) beats full wildcard (invalid)
        $className = $this->generateClassFromFile(
            'ContentMediaBoth.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator(null, 'base64', new AlwaysValidContentValidator())
                ->addContentValidator(null, null, new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);
        $object->setContent('e30=');
        $this->assertInstanceOf(MediaString::class, $object->getContent());
    }

    /**
     * Property with only contentMediaType: lookup key is ($mediaType, null).
     * An exact ($mediaType, null) validator fires; full wildcard also fires when no exact match.
     */
    public function testContentMediaTypeOnlyValidatorKey(): void
    {
        // Exact ($mediaType, null) validator fires
        $className = $this->generateClassFromFile(
            'ContentMediaTypeSimple.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator('image/png', null, new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);

        try {
            $object->setContent('data');
            $this->fail('Expected ContentException');
        } catch (ContentException $exception) {
            $this->assertSame('image/png', $exception->getExpectedMediaType());
            $this->assertNull($exception->getExpectedEncoding());
        }

        // Full wildcard also fires for this property
        $className = $this->generateClassFromFile(
            'ContentMediaTypeSimple.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator(null, null, new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);
        $this->expectException(ContentException::class);
        $object->setContent('data');
    }

    /**
     * Property with only contentEncoding: lookup key is (null, $encoding).
     * An exact (null, $encoding) validator fires.
     */
    public function testContentEncodingOnlyValidatorKey(): void
    {
        $className = $this->generateClassFromFile(
            'ContentEncoding.json',
            (new GeneratorConfiguration())
                ->setImmutable(false)
                ->setCollectErrors(false)
                ->addContentValidator(null, 'base64', new AlwaysInvalidContentValidator()),
        );

        $object = new $className([]);

        try {
            $object->setContent('aGVsbG8=');
            $this->fail('Expected ContentException');
        } catch (ContentException $exception) {
            $this->assertNull($exception->getExpectedMediaType());
            $this->assertSame('base64', $exception->getExpectedEncoding());
        }
    }
}
