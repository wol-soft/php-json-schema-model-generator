<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\SchemaDefinition;

use ArrayObject;
use PHPModelGenerator\Model\Property\PropertyInterface;
use WeakMap;

class ResolvedDefinitionsCollection extends ArrayObject
{
    /** @var WeakMap<PropertyInterface, true>|null */
    private static ?WeakMap $sharedProperties = null;

    public function offsetSet(mixed $key, mixed $value): void
    {
        if ($value instanceof PropertyInterface) {
            self::$sharedProperties ??= new WeakMap();
            self::$sharedProperties[$value] = true;
        }

        parent::offsetSet($key, $value);
    }

    /**
     * Whether the property is the resolved property of a definition, which every other reference to the definition
     * (PropertyProxy) delegates to. The first reference to a definition is this property itself.
     */
    public static function isShared(PropertyInterface $property): bool
    {
        return isset(self::$sharedProperties[$property]);
    }
}
