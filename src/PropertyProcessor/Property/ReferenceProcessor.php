<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition;

/**
 * Class ConstProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ReferenceProcessor extends AbstractTypedValueProcessor
{
    /**
     * @inheritdoc
     *
     * @throws SchemaException
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        $reference = $propertyData['$ref'];

        if ($this->schema->getDefinition($reference)) {
            return $this->resolveDefinition($propertyName, $this->schema->getDefinition($reference), $reference);
        }

        if (strpos($reference, '#') === 0 && strpos($reference, '/')) {
            $path = explode('/', $reference);
            array_shift($path);

            return $this->resolveDefinition(
                $propertyName,
                $this->schema->getDefinition(array_shift($path)),
                $reference,
                $path
            );
        }

        throw new SchemaException("Unresolved Reference: $reference");
    }

    /**
     * Resolve a given definition into a Property
     *
     * @param string                $propertyName
     * @param SchemaDefinition|null $definition
     * @param string                $reference
     * @param array                 $path
     *
     * @return PropertyInterface
     *
     * @throws SchemaException
     */
    protected function resolveDefinition(
        string $propertyName,
        ?SchemaDefinition $definition,
        string $reference,
        array $path = []
    ): PropertyInterface {
        if ($definition) {
            try {
                return $definition->resolveReference($propertyName, $path, $this->propertyCollectionProcessor);
            } catch (Exception $exception) {
                throw new SchemaException("Unresolved Reference: $reference", 0, $exception);
            }
        }

        throw new SchemaException("Unresolved Reference: $reference");
    }
}
