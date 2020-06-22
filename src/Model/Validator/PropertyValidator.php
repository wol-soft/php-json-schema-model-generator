<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

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
     * @param string $check
     * @param string $exceptionClass
     * @param array  $exceptionParams
     */
    public function __construct(string $check, string $exceptionClass, array $exceptionParams = [])
    {
        $this->check = $check;
        $this->exceptionClass = $exceptionClass;
        $this->exceptionParams = $exceptionParams;
    }

    /**
     * Get the source code for the coeck to perform
     *
     * @return string
     */
    public function getCheck(): string
    {
        return $this->check;
    }
}
