<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\ComposedValue;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies that allOf and anyOf branch-level defaults are applied from every branch that
 * matches, that user-supplied values take precedence, and that identical defaults across
 * branches collapse silently without throwing.
 *
 * allOf: all branches always match simultaneously — every branch default applies.
 * anyOf: all branches match when no constraints prevent it — every branch default applies.
 */
class ComposedAllOfAnyOfBranchDefaultTest extends AbstractPHPModelGeneratorTestCase
{
    /**
     * Both allOf branches always match; each declares a default on a different property.
     * Both defaults must appear on the object when no user values are supplied.
     * User-supplied values override the branch defaults in every position.
     */
    public function testAllOfBranchDefaultsAppliedFromAllBranches(): void
    {
        $className = $this->generateClassFromFile(
            'AllOfBranchDefault.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // No user input: both branch defaults apply.
        $noInput = new $className([]);
        $this->assertSame(30, $noInput->getTimeout());
        $this->assertSame(3, $noInput->getRetries());
        // Both values came from branch defaults; neither is present in raw input.
        $this->assertSame([], $noInput->getRawModelDataInput());

        // Both values user-supplied: defaults are bypassed.
        $bothSupplied = new $className(['timeout' => 100, 'retries' => 5]);
        $this->assertSame(100, $bothSupplied->getTimeout());
        $this->assertSame(5, $bothSupplied->getRetries());
        $this->assertSame(['timeout' => 100, 'retries' => 5], $bothSupplied->getRawModelDataInput());

        // Only one value user-supplied: the other still gets its branch default.
        $partialInput = new $className(['timeout' => 100]);
        $this->assertSame(100, $partialInput->getTimeout());
        $this->assertSame(3, $partialInput->getRetries());
        // retries came from the branch default; only the user-supplied timeout is in raw input.
        $this->assertSame(['timeout' => 100], $partialInput->getRawModelDataInput());
    }

    /**
     * Both anyOf branches match (no required constraints, no additionalProperties: false);
     * each declares a default on a different property. Both defaults must apply.
     * User-supplied values override the branch defaults in every position.
     */
    public function testAnyOfBranchDefaultsAppliedFromAllMatchingBranches(): void
    {
        $className = $this->generateClassFromFile(
            'AnyOfBranchDefault.json',
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        // No user input: both matching branches contribute their defaults.
        $noInput = new $className([]);
        $this->assertSame(30, $noInput->getTimeout());
        $this->assertSame(3, $noInput->getRetries());
        // Both values came from branch defaults; neither is present in raw input.
        $this->assertSame([], $noInput->getRawModelDataInput());

        // Both values user-supplied: defaults are bypassed.
        $bothSupplied = new $className(['timeout' => 100, 'retries' => 5]);
        $this->assertSame(100, $bothSupplied->getTimeout());
        $this->assertSame(5, $bothSupplied->getRetries());
        $this->assertSame(['timeout' => 100, 'retries' => 5], $bothSupplied->getRawModelDataInput());

        // Only one value user-supplied: the other still gets its branch default.
        $partialInput = new $className(['retries' => 5]);
        $this->assertSame(30, $partialInput->getTimeout());
        $this->assertSame(5, $partialInput->getRetries());
        // timeout came from the branch default; only the user-supplied retries is in raw input.
        $this->assertSame(['retries' => 5], $partialInput->getRawModelDataInput());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function identicalDefaultsSchemaProvider(): array
    {
        return [
            'allOf' => ['AllOfBranchDefaultAgreement.json'],
            'anyOf' => ['AnyOfBranchDefaultAgreement.json'],
        ];
    }

    /**
     * When two branches (allOf or anyOf) declare the same default value for the same property
     * the values agree and the conflict rule does not fire. The agreed default must be applied
     * once; a user-supplied value must override it.
     */
    #[DataProvider('identicalDefaultsSchemaProvider')]
    public function testBranchesWithIdenticalDefaultsCollapseToSingleApplication(string $schemaFile): void
    {
        $className = $this->generateClassFromFile(
            $schemaFile,
            (new GeneratorConfiguration())->setCollectErrors(false),
        );

        $object = new $className([]);
        $this->assertSame(30, $object->getTimeout());
        // The agreed default came from branches, not the user; absent from raw input.
        $this->assertSame([], $object->getRawModelDataInput());

        // User-supplied value must override the agreed default.
        $withUserValue = new $className(['timeout' => 99]);
        $this->assertSame(99, $withUserValue->getTimeout());
        $this->assertSame(['timeout' => 99], $withUserValue->getRawModelDataInput());
    }
}
