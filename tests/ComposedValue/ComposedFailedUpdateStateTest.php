<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Exception\ErrorRegistryException;
use PHPModelGenerator\Exception\ValidationException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PopulatePostProcessor;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

        try {
            $object->setKind('bb');
            $this->fail('Expected the maxLength violation of kind');
        } catch (Throwable $exception) {
            $this->assertInstanceOf($expectedException, $exception);
        }

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

        try {
            $object->populate(['kind' => 'bb', 'other' => 'y']);
            $this->fail('Expected the maxLength violation of kind');
        } catch (Throwable $exception) {
            $this->assertInstanceOf($expectedException, $exception);
        }

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
}
