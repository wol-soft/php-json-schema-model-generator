<?php

namespace PHPModelGenerator\Model;

/**
 * Class PropertyValidator
 *
 * @package PHPModelGenerator\Model
 */
class PropertyValidator
{
    /** @var string */
    protected $check;
    /** @var string */
    protected $exceptionClass;
    /** @var string */
    protected $exceptionMessage;

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
     * @return string
     */
    public function getCheck(): string
    {
        return $this->check;
    }

    /**
     * @return string
     */
    public function getExceptionClass(): string
    {
        return $this->exceptionClass;
    }

    /**
     * @return string
     */
    public function getExceptionMessage(): string
    {
        return $this->exceptionMessage;
    }
}
