<?php

declare(strict_types=1);

namespace PHPModelGenerator\Exception;

use Error;
use PHPModelGenerator\Model\Property\PropertyProxy;

/**
 * A property referencing a definition was read while the definition is still being created (a recursive reference): the
 * property the reference delegates to doesn't exist yet. Code which has to inspect the property catches the exception
 * and continues once the reference is resolved, see PropertyProxy::onResolve().
 *
 * An Error, not an Exception: reading the property is a programming error unless the caller handles it. A generic
 * `catch (Exception)` (e.g. the reference resolution restating a failure as "Unresolved Reference") must not turn an
 * unhandled read into a misleading schema error.
 */
class UnresolvedPropertyException extends Error
{
    public function __construct(private readonly PropertyProxy $proxy)
    {
        parent::__construct(
            sprintf(
                "Property '%s' references a definition which is still being created",
                $proxy->getName(),
            ),
        );
    }

    public function getProxy(): PropertyProxy
    {
        return $this->proxy;
    }
}
