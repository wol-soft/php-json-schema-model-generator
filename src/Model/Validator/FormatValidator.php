<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\String\FormatException;
use PHPModelGenerator\Format\FormatValidatorFromRegEx;
use PHPModelGenerator\Format\FormatValidatorInterface;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class FormatValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class FormatValidator extends AbstractPropertyValidator
{
    /**
     * FormatValidator constructor.
     */
    public function __construct(
        PropertyInterface $property,
        protected FormatValidatorInterface $validator,
        array $exceptionParams = [],
    ) {
        $this->isResolved = true;

        parent::__construct($property, FormatException::class, $exceptionParams);
    }

    /**
     * Get the source code for the check to perform
     */
    public function getCheck(): string
    {
        return $this->validator instanceof FormatValidatorFromRegEx
            ? sprintf(
                '!\%s::validate($value, %s)',
                $this->validator::class,
                var_export($this->validator->getPattern(), true),
            )
            : sprintf('!\%s::validate($value)', $this->validator::class);
    }
}
