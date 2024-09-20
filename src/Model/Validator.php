<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

/**
 * Class Validator
 *
 * @package PHPModelGenerator\Model
 */
class Validator
{
    public function __construct(protected PropertyValidatorInterface $validator, protected int $priority) {}

    public function getValidator(): PropertyValidatorInterface
    {
        return $this->validator;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }
}
