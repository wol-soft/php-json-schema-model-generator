<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

use Exception;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * Class ConstProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class ReferenceProcessor extends AbstractNestedValueProcessor
{
    /**
     * @inheritdoc
     */
    public function process(string $propertyName, array $propertyData): PropertyInterface
    {
        if (strpos($propertyData['$ref'], '#') === 0) {
            $path = explode('/', $propertyData['$ref']);
            array_shift($path);

            $definition = $this->schema->getDefinition(array_shift($path));

            if ($definition) {
                try {
                    return $definition->resolveReference($propertyName, $path);
                } catch (Exception $exception) {
                    throw new SchemaException("Unresolved Reference: {$propertyData['$ref']}", 0, $exception);
                }
            }

            throw new SchemaException("Unresolved Reference: {$propertyData['$ref']}");
        }

        throw new SchemaException("Unsupported Reference: {$propertyData['$ref']}");
    }
}
