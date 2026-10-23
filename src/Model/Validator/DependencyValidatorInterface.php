<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

/**
 * A validator attached to a property which validates other keys of the object (e.g. the property and schema
 * dependencies). It depends on keys the property doesn't own: an update or removal of any key may change its result,
 * and it only applies where the property is declared (not to a property transferred from a composition branch).
 */
interface DependencyValidatorInterface
{
}
