<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\String\FormatException;
use PHPModelGenerator\Format\FormatValidatorFromRegEx;
use PHPModelGenerator\Format\FormatValidatorInterface;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\RenderHelper;

class FormatValidator extends AbstractPropertyValidator
{
    public function __construct(
        PropertyInterface $property,
        protected FormatValidatorInterface $validator,
        array $exceptionParams = [],
    ) {
        $this->isResolved = true;

        parent::__construct($property, FormatException::class, $exceptionParams);
    }

    public function getCheck(): string
    {
        $call = $this->validator instanceof FormatValidatorFromRegEx
            ? sprintf(
                '!\%s::validate($value, %s)',
                $this->validator::class,
                RenderHelper::varExportArray($this->validator->getPattern()),
            )
            : sprintf('!\%s::validate($value)', $this->validator::class);

        return 'is_string($value) && ' . $call;
    }
}
