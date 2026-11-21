<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\ResolvableInterface;

interface PropertyValidatorInterface extends ResolvableInterface
{
    /**
     * Get the source code for the check to perform
     */
    public function getCheck(): string;

    /**
     * Get the class of the exception which is thrown if the validation fails
     */
    public function getExceptionClass(): string;

    /**
     * Get the additional data of the exception which is thrown if the validation fails
     */
    public function getExceptionParams(): array;

    /**
     * Get the source code of the value which is provided to the exception if the validation fails. The default is the
     * validated value, a validator which compares the value in another representation than the validated one (eg. the
     * data of a model) provides the value it compared.
     */
    public function getProvidedValueCode(): string;

    /**
     * Whether the validator validates the generated class itself (a validator of the base property of a schema, eg.
     * minProperties or the composition of an object) instead of a value of the data. The name of the property which
     * its exception receives is the name of the generated class: a label for the message text, no name inside of the
     * validated data.
     */
    public function validatesGeneratedClass(): bool;

    /**
     * Get the source code which is required to set up the validator (eg. initialize variables)
     */
    public function getValidatorSetUp(): string;

    /**
     * Get an instance of the validator which is mapped to the property $property
     */
    public function withProperty(PropertyInterface $property): PropertyValidatorInterface;
}
