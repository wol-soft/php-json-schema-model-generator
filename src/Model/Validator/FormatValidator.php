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
    /** @var FormatValidatorInterface */
    protected $validator;

    /**
     * FormatValidator constructor.
     *
     * @param PropertyInterface $property
     * @param FormatValidatorInterface $validator
     * @param array $exceptionParams
     */
    public function __construct(
        PropertyInterface $property,
        FormatValidatorInterface $validator,
        array $exceptionParams = []
    ) {
        $this->validator = $validator;

        parent::__construct($property, FormatException::class, $exceptionParams);
    }

    /**
     * Get the source code for the check to perform
     *
     * @return string
     */
    public function getCheck(): string
    {
        return $this->validator instanceof FormatValidatorFromRegEx
            ? sprintf(
                '!\%s::validate($value, %s)',
                get_class($this->validator),
                var_export($this->validator->getPattern(), true)
            )
            : sprintf('!\%s::validate($value)', get_class($this->validator));
    }
}
