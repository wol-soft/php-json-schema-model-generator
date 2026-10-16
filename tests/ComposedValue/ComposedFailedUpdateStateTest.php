<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PatternPropertiesAccessorPostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Throwable;

/**
 * An object schema remembers the result of each composition component, a component is only evaluated again
 * if one of its properties is part of an update. A failed update must leave this state as it was, otherwise the
 * rejected data influences later valid updates.
 */
class ComposedFailedUpdateStateTest extends AbstractPHPModelGeneratorTestCase
{
    public static function collectErrorsProvider(): array
    {
        return [
            'exception on the first violation' => [false, ValidationException::class],
            'collect errors' => [true, ErrorRegistryException::class],
        ];
    }

    /**
     * The new value of kind satisfies the second oneOf branch but violates the maxLength of kind itself: the
     * composition succeeded when the update is rejected. The first branch (still matching the unchanged model) must
     * not be remembered as failed.
     *
     * @param class-string<Throwable> $expectedException
     */
    #[DataProvider('collectErrorsProvider')]
    public function testSetterWhichFailsAfterTheCompositionSucceededDoesNotChangeTheCompositionState(
        bool $collectErrors,
        string $expectedException,
    ): void {
        $className = $this->generateClassFromFile(
            'OneOfRejectedByOwnValidation.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $object = new $className(['kind' => 'a']);

        $exception = $this->catchException(static fn() => $object->setKind('bb'));
        $this->assertInstanceOf($expectedException, $exception, 'Expected the maxLength violation of kind');
        $this->assertSame("Value for 'kind' must not be longer than 1", $exception->getMessage());

        $this->assertSame('a', $object->getKind());
        // The default of the second branch was not adopted: the branch doesn't match.
        $this->assertNull($object->getSandbox());

        // The data (kind 'a', other 'x') matches exactly the first branch.
        $object->setOther('x');
        $this->assertSame('x', $object->getOther());
        $this->assertSame('a', $object->getKind());
    }

    /**
     * @param class-string<Throwable> $expectedException
     */
    #[DataProvider('collectErrorsProvider')]
    public function testPopulateWhichFailsAfterTheCompositionSucceededDoesNotChangeTheCompositionState(
        bool $collectErrors,
        string $expectedException,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator->addPostProcessor(new PopulatePostProcessor());
        };

        $className = $this->generateClassFromFile(
            'OneOfRejectedByOwnValidation.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $object = new $className(['kind' => 'a']);

        $exception = $this->catchException(static fn() => $object->populate(['kind' => 'bb', 'other' => 'y']));
        $this->assertInstanceOf($expectedException, $exception, 'Expected the maxLength violation of kind');
        $this->assertSame("Value for 'kind' must not be longer than 1", $exception->getMessage());

        $this->assertSame('a', $object->getKind());
        $this->assertNull($object->getOther());
        // The default of the second branch was not adopted: the branch doesn't match.
        $this->assertNull($object->getSandbox());

        $object->populate(['other' => 'z']);
        $this->assertSame('z', $object->getOther());
        $this->assertNull($object->getSandbox());

        $object->setOther('x');
        $this->assertSame('x', $object->getOther());
    }

    /**
     * A declared property matching a pattern property is linked into the pattern property storage by reference. The
     * composition writes the branch default of a1 through this reference before the update is rejected by the
     * maxLength of kind. The restored model must neither contain the default nor lose the reference: a later update
     * of a1 must still be visible via the pattern property accessor.
     *
     * Restoring from a snapshot which still contains the reference was rejected: the snapshot value then follows the
     * reference and holds the default, so the restore would not detect the change.
     *
     * @param class-string<Throwable> $expectedException
     */
    #[DataProvider('collectErrorsProvider')]
    public function testRejectedUpdateRestoresBranchDefaultOfPatternPropertyAndKeepsReference(
        bool $collectErrors,
        string $expectedException,
    ): void {
        $this->modifyModelGenerator = static function (ModelGenerator $generator): void {
            $generator
                ->addPostProcessor(new PopulatePostProcessor())
                ->addPostProcessor(new PatternPropertiesAccessorPostProcessor());
        };

        $className = $this->generateClassFromFile(
            'BranchDefaultOnPatternProperty.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        // setter: the second branch matches (default for a1), the maxLength of kind rejects the update
        $object = new $className(['kind' => 'b']);

        $exception = $this->catchException(static fn() => $object->setKind('cc'));
        $this->assertInstanceOf($expectedException, $exception, 'Expected the maxLength violation of kind');
        $this->assertSame("Value for 'kind' must not be longer than 1", $exception->getMessage());

        $this->assertSame('b', $object->getKind());
        $this->assertNull($object->getA1());

        $object->setA1('x');
        $this->assertSame(['a1' => 'x'], $object->patternProperties()->get('^a'));

        // populate: additionally a new pattern property, which must not be adopted
        $object = new $className(['kind' => 'b']);

        $exception = $this->catchException(static fn() => $object->populate(['kind' => 'cc', 'a2' => 'y']));
        $this->assertInstanceOf($expectedException, $exception, 'Expected the maxLength violation of kind');
        $this->assertSame("Value for 'kind' must not be longer than 1", $exception->getMessage());

        $this->assertSame('b', $object->getKind());
        $this->assertNull($object->getA1());
        $this->assertSame(['kind' => 'b'], $object->meta()->rawInput());

        $object->populate(['a1' => 'x', 'a2' => 'y']);
        $this->assertSame(['a1' => 'x', 'a2' => 'y'], $object->patternProperties()->get('^a'));
    }

    /**
     * The first composition (anyOf) accepts the update, records its result and applies the default of its matching
     * branch. The second composition (oneOf) rejects the update afterwards. The model state written by the first
     * composition must be restored as well, although the first composition itself succeeded.
     *
     * @param class-string<Throwable> $expectedException
     */
    #[DataProvider('collectErrorsProvider')]
    public function testRejectionBySecondCompositionRestoresStateOfFirstComposition(
        bool $collectErrors,
        string $expectedException,
    ): void {
        $className = $this->generateClassFromFile(
            'SecondCompositionRejects.json',
            (new GeneratorConfiguration())->setCollectErrors($collectErrors)->setImmutable(false),
        );

        $object = new $className(['kind' => 'a']);
        $this->assertNull($object->getSandbox());

        $exception = $this->catchException(static fn() => $object->setKind('bb'));
        $this->assertInstanceOf($expectedException, $exception, 'Expected the oneOf violation of kind');
        $this->assertSame(
            sprintf(
                "Invalid value for '%s' declined by composition constraint\n"
                    . '  Requires to match one composition element but matched 0 elements%s',
                (new ReflectionClass($className))->getShortName(),
                // with collected errors, the failing element of the composition is listed
                $collectErrors
                    ? "\n  - Composition element #1: Failed\n    * Value for 'kind' must not be longer than 1"
                    : '',
            ),
            $exception->getMessage(),
        );

        $this->assertSame('a', $object->getKind());
        $this->assertNull($object->getSandbox());

        // the restored validation state of the anyOf composition evaluates the next update correctly
        $object->setKind('b');
        $this->assertSame('b', $object->getKind());
        $this->assertTrue($object->getSandbox());
    }
}
