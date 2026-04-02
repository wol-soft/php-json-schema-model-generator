<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

/**
 * Marker interface for composition validator factories that transfer their composed
 * properties to the parent schema (allOf, anyOf, oneOf, if/then/else).
 *
 * Factories that do NOT implement this interface (i.e. NotValidatorFactory) do not
 * transfer properties and do not require a nested schema on their composition branches.
 */
interface ComposedPropertiesValidatorFactoryInterface
{
}
