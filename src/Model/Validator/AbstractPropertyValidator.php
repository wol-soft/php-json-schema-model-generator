<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Utils\ResolvableTrait;

/**
 * Class AbstractPropertyValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
abstract class AbstractPropertyValidator implements PropertyValidatorInterface
{
    use ResolvableTrait;

    /**
     * AbstractPropertyValidator constructor.
     */
    protected string $jsonPointer = '';

    public function __construct(
        protected PropertyInterface $property,
        protected string $exceptionClass,
        protected array $exceptionParams = [],
    ) {}

    /**
     * @inheritDoc
     */
    public function withProperty(PropertyInterface $property): PropertyValidatorInterface
    {
        $clone = clone $this;
        $clone->property = $property;

        return $clone;
    }

    /**
     * @inheritDoc
     */
    public function getExceptionClass(): string
    {
        return $this->exceptionClass;
    }

    public function withJsonPointer(string $jsonPointer): static
    {
        $clone = clone $this;
        $clone->jsonPointer = $jsonPointer;

        // If the original is not yet resolved (e.g. ArrayItemValidator waiting on a recursive
        // $ref), the SchemaDefinition resolution chain holds a reference to the original, not
        // the clone. Forward resolution so the clone resolves when the original does — otherwise
        // the property that received the clone never decrements its pending-validator count and
        // finalizeMultiTypeProperty never fires.
        if (!$this->isResolved) {
            $this->onResolve(static function () use ($clone): void {
                $clone->resolve();
            });
        }

        return $clone;
    }

    public function getJsonPointer(): string
    {
        return $this->jsonPointer;
    }

    /**
     * @inheritDoc
     */
    public function getExceptionParams(): array
    {
        return array_merge([$this->property->getName(), $this->jsonPointer], $this->exceptionParams);
    }

    /**
     * By default a validator doesn't require a set up
     */
    public function getValidatorSetUp(): string
    {
        return '';
    }

    /**
     * Helper function to remove a RequiredPropertyValidator
     */
    protected function removeRequiredPropertyValidator(PropertyInterface $property): void
    {
        $property->onResolve(static function () use ($property): void {
            $property->filterValidators(static fn(Validator $validator): bool =>
                !is_a($validator->getValidator(), RequiredPropertyValidator::class));
        });
    }
}
