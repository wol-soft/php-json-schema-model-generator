<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model;

use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

/**
 * Class Validator
 *
 * @package PHPModelGenerator\Model
 */
class Validator
{
    private ?string $sourceKey = null;

    public function __construct(protected PropertyValidatorInterface $validator, protected int $priority)
    {}

    public function getValidator(): PropertyValidatorInterface
    {
        return $this->validator;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    /**
     * The schema keyword (e.g. 'pattern', 'minimum') that caused this validator to be added,
     * as determined by the Draft modifier registry. Null for validators not produced by a
     * Draft AbstractValidatorFactory (e.g. TypeCheckValidator, RequiredPropertyValidator).
     */
    public function getSourceKey(): ?string
    {
        return $this->sourceKey;
    }

    public function setSourceKey(?string $sourceKey): void
    {
        $this->sourceKey = $sourceKey;
    }
}
