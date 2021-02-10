<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use ReflectionType;

/**
 * Class PassThroughTypeCheckValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PassThroughTypeCheckValidator extends PropertyValidator implements TypeCheckInterface
{
    /** @var string[] */
    protected $types;

    /**
     * PassThroughTypeCheckValidator constructor.
     *
     * @param ReflectionType $passThroughType
     * @param PropertyInterface $property
     * @param TypeCheckValidator $typeCheckValidator
     */
    public function __construct(
        ReflectionType $passThroughType,
        PropertyInterface $property,
        TypeCheckValidator $typeCheckValidator
    ) {
        $this->types = array_merge($typeCheckValidator->getTypes(), [$passThroughType->getName()]);

        parent::__construct(
            $property,
            sprintf(
                '%s && %s',
                ReflectionTypeCheckValidator::fromReflectionType($passThroughType, $property)->getCheck(),
                $typeCheckValidator->getCheck()
            ),
            InvalidTypeException::class,
            [[$passThroughType->getName(), $property->getType()->getName()]]
        );
    }

    /**
     * @inheritDoc
     */
    public function getTypes(): array
    {
        return $this->types;
    }
}
