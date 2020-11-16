<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class PropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PropertyValidator extends AbstractPropertyValidator
{
    /** @var string */
    protected $check;

    /**
     * PropertyValidator constructor.
     *
     * @param PropertyInterface $property
     * @param string            $check
     * @param string            $exceptionClass
     * @param array             $exceptionParams
     */
    public function __construct(
        PropertyInterface $property,
        string $check,
        string $exceptionClass,
        array $exceptionParams = []
    ) {
        $this->check = $check;

        parent::__construct($property, $exceptionClass, $exceptionParams);
    }

    /**
     * Get the source code for the check to perform
     *
     * @return string
     */
    public function getCheck(): string
    {
        return $this->check;
    }
}
