<?php

namespace PHPModelGenerator\Model\Validator;

use Closure;

/**
 * Class CallbackPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PropertyCallbackValidator extends AbstractPropertyValidator implements PropertyValidatorInterface
{
    /** @var array */
    protected static $callbacks = [];
    /** @var int */
    protected static $idCounter = 0;
    /** @var int */
    private $id;

    /**
     * PropertyValidator constructor.
     *
     * @param Closure $check
     * @param string  $exceptionClass
     * @param string  $exceptionMessage
     */
    public function __construct(Closure $check, string $exceptionClass, string $exceptionMessage)
    {
        $this->id = static::$idCounter++;
        $this->exceptionClass = $exceptionClass;
        $this->exceptionMessage = $exceptionMessage;

        static::$callbacks[$this->id] = $check;
    }

    /**
     * perform a callback validation
     *
     * @param int $callbackId
     * @param     $value
     *
     * @return bool
     */
    public static function validate(int $callbackId, $value): bool
    {
        return (bool) (static::$callbacks)[$callbackId]($value);
    }

    /**
     * Get the source code for the coeck to perform
     *
     * @return string
     */
    public function getCheck(): string
    {
        return "\PHPModelGenerator\Model\Validator\PropertyCallbackValidator::validate({$this->id}, \$value)";
    }
}
