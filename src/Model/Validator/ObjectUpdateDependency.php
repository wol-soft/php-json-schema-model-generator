<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

/**
 * How the result of a validator of an object depends on an update (setter, populate) of one key of the object.
 */
enum ObjectUpdateDependency
{
    /** An update of the key can't change the result */
    case None;
    /** Adding the key may change the result, changing the value of a present key can't */
    case AddedKey;
    /** Any update of the key may change the result */
    case Value;
}
