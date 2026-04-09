<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Draft;

use PHPModelGenerator\Draft\DraftBuilder;
use PHPModelGenerator\Draft\DraftInterface;
use PHPModelGenerator\Draft\Draft_07;
use PHPModelGenerator\Draft\Element\Type;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Exception\String\MinLengthException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator\Factory\SimplePropertyValidatorFactory;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;


/**
 * Tests for the Draft extensibility architecture:
 *   - overriding an existing validator on an existing type (via DraftBuilder::addType replacement)
 *   - adding a new custom validator to an existing type (via DraftBuilder::getType mutation)
 *   - adding a completely new type (via DraftBuilder::addType with a fresh Type)
 *
 * Each of these exercises DraftBuilder::getType, DraftBuilder::addType,
 * Draft::getTypes, and Draft::hasType as part of the same generation pass.
 */
class DraftExtensibilityTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Confirm that with the standard Draft_07, minLength must be a non-negative integer —
     * passing a float throws SchemaException at generation time.
     */
    public function testDefaultDraftRejectsFloatMinLength(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Invalid minLength/');

        $this->generateClassFromFile('StringWithFloatMinLength.json');
    }

    /**
     * A custom draft overrides the built-in 'minLength' validator (float-accepting replacement),
     * adds a new 'customMin' validator, and registers a brand-new 'special' type — all in one
     * pass.
     *
     * Override mechanism: Type::addValidator keys validators by name, so a second call with the
     *   same key replaces the existing entry in-place (preserving its position in the modifier
     *   sequence).
     * Add-new-validator mechanism: DraftBuilder::getType returns the live Type object; calling
     *   addValidator on it mutates the builder's entry directly.
     * New-type mechanism: DraftBuilder::addType with a previously-unknown key adds a new entry.
     */
    public function testCustomDraftExtensibilityOverrideAndAddAndNewType(): void
    {
        // Factory that accepts any numeric minLength value (floats included).
        // The exception threshold is cast to int to satisfy MinLengthException's signature.
        $floatMinLengthFactory = new class extends SimplePropertyValidatorFactory {
            protected function isValueValid(mixed $value): bool
            {
                return is_numeric($value) && $value >= 0;
            }

            protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface
            {
                $intThreshold = (int) ceil((float) $value);

                return new PropertyValidator(
                    $property,
                    "is_string(\$value) && mb_strlen(\$value) < $intThreshold",
                    MinLengthException::class,
                    [$intThreshold],
                );
            }
        };

        // Factory for the new 'customMin' keyword — enforces a minimum string length.
        $customMinFactory = new class extends SimplePropertyValidatorFactory {
            protected function isValueValid(mixed $value): bool
            {
                return is_int($value) && $value >= 0;
            }

            protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface
            {
                return new PropertyValidator(
                    $property,
                    "is_string(\$value) && mb_strlen(\$value) < $value",
                    MinLengthException::class,
                    [$value],
                );
            }
        };

        $customDraft = new class ($floatMinLengthFactory, $customMinFactory) implements DraftInterface {
            public function __construct(
                private readonly SimplePropertyValidatorFactory $floatMinLength,
                private readonly SimplePropertyValidatorFactory $customMin,
            ) {}

            public function getDefinition(): DraftBuilder
            {
                $builder = (new Draft_07())->getDefinition();

                // Override the built-in 'minLength' validator with one that accepts floats.
                // addValidator keys by name, so this replaces the existing entry in-place.
                $builder->getType('string')->addValidator('minLength', $this->floatMinLength);

                // Add a brand-new 'customMin' validator to the string type.
                $builder->getType('string')->addValidator('customMin', $this->customMin);

                // Add a completely new type.
                $builder->addType(new Type('special'));

                return $builder;
            }
        };

        // Verify that the new 'special' type is visible via getTypes() and hasType().
        $builtDraft = $customDraft->getDefinition()->build();
        $this->assertTrue($builtDraft->hasType('special'));
        $this->assertArrayHasKey('special', $builtDraft->getTypes());
        $this->assertFalse($builtDraft->hasType('nonexistent'));

        $config = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setDraft($customDraft);

        // Override verified: float minLength no longer throws SchemaException at gen time.
        // minLength: 0.5 → ceil(0.5) = 1 → minimum effective length is 1.
        $className = $this->generateClassFromFile('StringWithFloatMinLength.json', $config);

        // Non-empty string passes (length 3 >= 1).
        $object = new $className(['value' => 'abc']);
        $this->assertSame('abc', $object->getValue());

        // Empty string fails (length 0 < 1).
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Value for value must not be shorter than 1');
        new $className(['value' => '']);
    }

    public function testCustomDraftCustomMinKeywordEnforcesMinimumLengthAtRuntime(): void
    {
        // Factory for the new 'customMin' keyword — same runtime semantics as minLength.
        $customMinFactory = new class extends SimplePropertyValidatorFactory {
            protected function isValueValid(mixed $value): bool
            {
                return is_int($value) && $value >= 0;
            }

            protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface
            {
                return new PropertyValidator(
                    $property,
                    "is_string(\$value) && mb_strlen(\$value) < $value",
                    MinLengthException::class,
                    [$value],
                );
            }
        };

        $customDraft = new class ($customMinFactory) implements DraftInterface {
            public function __construct(
                private readonly SimplePropertyValidatorFactory $customMin,
            ) {}

            public function getDefinition(): DraftBuilder
            {
                $builder = (new Draft_07())->getDefinition();
                // Mutate the live Type object returned by getType — this is the "add validator
                // to existing type" path that does not need a full type replacement.
                $builder->getType('string')->addValidator('customMin', $this->customMin);

                return $builder;
            }
        };

        $config = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setDraft($customDraft);

        $className = $this->generateClassFromFile('StringWithCustomMin.json', $config);

        // 'ab' has length 2 < 3 → validation fails
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Value for value must not be shorter than 3');
        new $className(['value' => 'ab']);
    }

    public function testCustomDraftCustomMinKeywordAcceptsValueMeetingMinimum(): void
    {
        $customMinFactory = new class extends SimplePropertyValidatorFactory {
            protected function isValueValid(mixed $value): bool
            {
                return is_int($value) && $value >= 0;
            }

            protected function getValidator(PropertyInterface $property, mixed $value): PropertyValidatorInterface
            {
                return new PropertyValidator(
                    $property,
                    "is_string(\$value) && mb_strlen(\$value) < $value",
                    MinLengthException::class,
                    [$value],
                );
            }
        };

        $customDraft = new class ($customMinFactory) implements DraftInterface {
            public function __construct(
                private readonly SimplePropertyValidatorFactory $customMin,
            ) {}

            public function getDefinition(): DraftBuilder
            {
                $builder = (new Draft_07())->getDefinition();
                $builder->getType('string')->addValidator('customMin', $this->customMin);

                return $builder;
            }
        };

        $config = (new GeneratorConfiguration())
            ->setCollectErrors(false)
            ->setDraft($customDraft);

        $className = $this->generateClassFromFile('StringWithCustomMin.json', $config);

        $object = new $className(['value' => 'abc']);
        $this->assertSame('abc', $object->getValue());

        $object = new $className(['value' => 'longer string']);
        $this->assertSame('longer string', $object->getValue());
    }
}
