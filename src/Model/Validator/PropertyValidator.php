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
     * @param string $exceptionMessage
     */
    public function __construct(string $check, string $exceptionClass, string $exceptionMessage)
    {
        $this->check = $check;
        $this->exceptionClass = $exceptionClass;
        $this->exceptionMessage = $exceptionMessage;
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
