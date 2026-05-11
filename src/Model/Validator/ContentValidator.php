<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\String\ContentException;
use PHPModelGenerator\MediaString\ContentValidatorInterface;
use PHPModelGenerator\Model\Property\PropertyInterface;

class ContentValidator extends AbstractPropertyValidator
{
    public function __construct(
        PropertyInterface $property,
        private readonly ContentValidatorInterface $validator,
        private readonly ?string $mediaType,
        private readonly ?string $encoding,
    ) {
        $this->isResolved = true;

        parent::__construct(
            $property,
            ContentException::class,
            [$mediaType, $encoding, '&$contentValidatorException'],
        );
    }

    public function getValidatorSetUp(): string
    {
        return '$contentValidatorException = null;';
    }

    public function getCheck(): string
    {
        return sprintf(
            'is_string($value) && (function () use ($value, &$contentValidatorException): bool {
                try {
                    \%s::validate($value);
                } catch (\Throwable $contentValidatorException) {
                    return true;
                }
                return false;
            })()',
            $this->validator::class,
        );
    }
}
