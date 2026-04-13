<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\FilterInterface;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\Property\PropertyInterface;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Reflection utilities for filter callables.
 *
 * Derives accepted types from the callable's first parameter and return type
 * information from the callable's return type hint.
 */
class FilterReflection
{
    /**
     * Derive accepted PHP type names from the first parameter of the filter callable.
     *
     * Returns an empty array for 'mixed' (accepts all types — no runtime type guard is generated).
     * Throws when the parameter has no type hint; all filter callables must declare one.
     *
     * @return string[]
     *
     * @throws SchemaException when the first parameter has no type hint
     * @throws ReflectionException
     */
    public static function getAcceptedTypes(FilterInterface $filter, PropertyInterface $property): array
    {
        $params = (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))->getParameters();

        if (empty($params) || $params[0]->getType() === null) {
            throw new SchemaException(
                sprintf(
                    'Filter %s must declare a type hint on its first parameter for property %s in file %s',
                    $filter->getToken(),
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ),
            );
        }

        $type = $params[0]->getType();

        if ($type instanceof ReflectionNamedType) {
            if ($type->getName() === 'mixed') {
                return [];
            }

            $types = [$type->getName()];

            if ($type->allowsNull() && $type->getName() !== 'null') {
                $types[] = 'null';
            }

            return $types;
        }

        if ($type instanceof ReflectionUnionType) {
            return array_map(
                static fn(ReflectionNamedType $namedType): string => $namedType->getName(),
                $type->getTypes(),
            );
        }

        return [];
    }

    /**
     * Extract non-null return type names from the transforming filter's callable.
     *
     * @return string[]
     *
     * @throws SchemaException when return type is missing or void
     * @throws ReflectionException
     */
    public static function getReturnTypeNames(
        TransformingFilterInterface $filter,
        PropertyInterface $property,
    ): array {
        $returnType = self::reflectReturnType($filter);

        if ($returnType === null) {
            throw new SchemaException(
                sprintf(
                    'Transforming filter %s must declare a return type for property %s in file %s',
                    $filter->getToken(),
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ),
            );
        }

        if ($returnType instanceof ReflectionNamedType) {
            $name = $returnType->getName();

            if ($name === 'void') {
                throw new SchemaException(
                    sprintf(
                        'Transforming filter %s must not declare a void return type'
                            . ' for property %s in file %s',
                        $filter->getToken(),
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    ),
                );
            }

            if ($name === 'null' || $name === 'mixed') {
                return [];
            }

            return [$name];
        }

        if ($returnType instanceof ReflectionUnionType) {
            return array_values(array_filter(
                array_map(
                    static fn(ReflectionNamedType $namedType): string => $namedType->getName(),
                    $returnType->getTypes(),
                ),
                static fn(string $name): bool => $name !== 'null',
            ));
        }

        return [];
    }

    /**
     * Whether the transforming filter's return type is nullable.
     *
     * @throws ReflectionException
     */
    public static function isReturnNullable(TransformingFilterInterface $filter): bool
    {
        $returnType = self::reflectReturnType($filter);

        if ($returnType === null) {
            return false;
        }

        if ($returnType instanceof ReflectionNamedType) {
            $name = $returnType->getName();
            // 'mixed' covers all types including null, but is treated as unconstrained
            // (not as a nullable specific type), so we report it as non-nullable here.
            if ($name === 'null' || $name === 'mixed') {
                return false;
            }

            return $returnType->allowsNull();
        }

        if ($returnType instanceof ReflectionUnionType) {
            foreach ($returnType->getTypes() as $namedType) {
                if ($namedType->getName() === 'null') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @throws ReflectionException
     */
    private static function reflectReturnType(TransformingFilterInterface $filter): ?\ReflectionType
    {
        return (new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]))->getReturnType();
    }
}
