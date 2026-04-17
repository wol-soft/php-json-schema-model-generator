<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Utils\TypeCheck;

/**
 * Class PassThroughTypeCheckValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class PassThroughTypeCheckValidator extends PropertyValidator implements TypeCheckInterface
{
    /** @var string[] */
    protected array $types;

    /**
     * PassThroughTypeCheckValidator constructor.
     *
     * @param string[] $passThroughTypeNames  Simple PHP type names of the transformed output
     *                                        (e.g. ['DateTime'] or ['DateTime', 'Date', 'Time'])
     */
    public function __construct(
        array $passThroughTypeNames,
        PropertyInterface $property,
        TypeCheckValidator|MultiTypeCheckValidator $typeCheckValidator,
    ) {
        $this->types = array_values(array_unique(array_merge($typeCheckValidator->getTypes(), $passThroughTypeNames)));

        // Condition for throwing: value is neither the transformed type nor the original type.
        $passThroughCheck = TypeCheck::buildNegatedCompound($passThroughTypeNames);

        parent::__construct(
            $property,
            sprintf('%s && %s', $passThroughCheck, $typeCheckValidator->getCheck()),
            InvalidTypeException::class,
            [[implode(' | ', $passThroughTypeNames), implode(' | ', $typeCheckValidator->getTypes())]],
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
