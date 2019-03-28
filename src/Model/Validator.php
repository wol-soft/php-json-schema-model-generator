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
    /** @var PropertyValidatorInterface */
    protected $validator;
    /** @var int */
    protected $priority;

    public function __construct(PropertyValidatorInterface $validator, int $priority)
    {
        $this->validator = $validator;
        $this->priority = $priority;
    }

    /**
     * @return PropertyValidatorInterface
     */
    public function getValidator(): PropertyValidatorInterface
    {
        return $this->validator;
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }
}
