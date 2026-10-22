<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

/**
 * A validator of an object (a base validator of a schema or a dependency validator of a property) which knows how an
 * update of a key of the object affects its result. Updates validate only the validators an update of the updated key
 * may affect. A validator which doesn't implement the interface is treated as depending on the value of every key.
 */
interface ObjectUpdateDependentValidatorInterface
{
    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency;
}
