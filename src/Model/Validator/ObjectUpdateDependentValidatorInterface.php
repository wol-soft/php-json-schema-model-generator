<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

/**
 * A validator of an object (a base validator of a schema or a dependency validator of a property) which knows how an
 * update of a key of the object may change its result (see ObjectUpdateDependency). Updates validate only the
 * validators an update of the updated key may affect, and compositions keep the cached result of a branch as long
 * as no update may change it. A validator which doesn't implement the interface depends on the value of every key.
 */
interface ObjectUpdateDependentValidatorInterface
{
    public function getObjectUpdateDependency(string $key): ObjectUpdateDependency;

    /**
     * The strongest dependency on a key the object doesn't declare as property (e.g. an additional property). Used
     * where the updated keys are only known at runtime (populate, additional property accessors).
     */
    public function getUndeclaredKeyDependency(): ObjectUpdateDependency;
}
