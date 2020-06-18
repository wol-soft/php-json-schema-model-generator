<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

/**
 * Interface TypeCheckInterface
 *
 * @package PHPModelGenerator\Model\Validator
 */
interface TypeCheckInterface
{
    /**
     * Get all types accepted by the type check validator
     *
     * @return string[]
     */
    public function getTypes(): array;
}
