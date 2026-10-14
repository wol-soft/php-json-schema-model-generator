<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\Hook;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Restores state which a hook changes while an update is validated if the update fails.
 *
 * The backup code is rendered at the beginning of a setter, the restore code is executed if the update is rejected
 * (an exception is thrown by the before hooks or the validation, or errors are collected) before the exception
 * is passed on. If no hook provides restore code the setter is rendered without the additional handling.
 */
interface SetterRollbackHookInterface extends SchemaHookInterface
{
    public function getBackupCode(PropertyInterface $property): string;

    public function getRestoreCode(PropertyInterface $property): string;
}
