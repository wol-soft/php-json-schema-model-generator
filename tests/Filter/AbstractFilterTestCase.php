<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Filter;

use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\PropertyProcessor\Filter\TrimFilter;
use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;

/**
 * Base class for all filter test groups.
 *
 * Provides shared helpers — getCustomFilter(), getCustomTransformingFilter(), and static callables
 * used by more than one test class — so they are not duplicated across the split test files.
 *
 * Each concrete test class resolves its schema files from its own
 * tests/Schema/<ClassName>/ directory via the default getStaticClassName() behaviour.
 */
abstract class AbstractFilterTestCase extends AbstractPHPModelGeneratorTestCase
{
    // -------------------------------------------------------------------------
    // Helper factory methods
    // -------------------------------------------------------------------------

    protected function getCustomFilter(
        array $customFilter,
        string $token = 'customFilter',
    ): FilterInterface {
        return new class ($customFilter, $token) implements FilterInterface {
            public function __construct(
                private readonly array $customFilter,
                private readonly string $token,
            ) {}

            public function getToken(): string
            {
                return $this->token;
            }

            public function getFilter(): array
            {
                return $this->customFilter;
            }
        };
    }

    protected function getCustomTransformingFilter(
        array $customSerializer,
        array $customFilter = [],
        string $token = 'customTransformingFilter',
    ): TransformingFilterInterface {
        return new class (
            $customSerializer,
            $customFilter,
            $token,
        ) extends TrimFilter implements TransformingFilterInterface
        {
            public function __construct(
                private readonly array $customSerializer,
                private readonly array $customFilter,
                private readonly string $token,
            ) {}

            public function getToken(): string
            {
                return $this->token;
            }

            public function getFilter(): array
            {
                return empty($this->customFilter) ? parent::getFilter() : $this->customFilter;
            }

            public function getSerializer(): array
            {
                return $this->customSerializer;
            }
        };
    }

    // -------------------------------------------------------------------------
    // Shared data providers
    // -------------------------------------------------------------------------

    /**
     * Cross-product of implicitNull × namespace; used by transforming-filter and chain tests.
     */
    public static function implicitNullNamespaceDataProvider(): array
    {
        return self::combineDataProvider(
            self::implicitNullDataProvider(),
            self::namespaceDataProvider(),
        );
    }

    // -------------------------------------------------------------------------
    // Shared static callables (used by two or more test classes)
    // -------------------------------------------------------------------------

    /** Accepts string; returns uppercase string. Used by type-compatibility and chain tests. */
    public static function uppercaseFilterStringOnly(string $value): string
    {
        return strtoupper($value);
    }

    /** Converts an integer to its binary string representation. Used by transforming-filter and type-compatibility tests. */
    public static function filterIntToBinary(int $value): string
    {
        return decbin($value);
    }

    /** Converts a binary string back to an integer. Used by transforming-filter and type-compatibility tests. */
    public static function serializeBinaryToInt(string $binary): int
    {
        return bindec($binary);
    }

    /** Casts a string to int. Used by composition-static and composition-runtime tests. */
    public static function convertStringToInt(string $value): int
    {
        return (int) $value;
    }

    /** Casts an int to string. Used by composition-static and composition-runtime tests. */
    public static function serializeIntToString(int $value): string
    {
        return (string) $value;
    }
}
