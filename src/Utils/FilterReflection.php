<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Exception\InvalidFilterException;
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
     * 'self' and 'static' in the parameter type are resolved to the declaring class FQCN so
     * that generated type-guard code contains a valid class name.
     *
     * @return string[]
     *
     * @throws InvalidFilterException when the first parameter has no type hint
     * @throws ReflectionException
     */
    public static function getAcceptedTypes(FilterInterface $filter, PropertyInterface $property): array
    {
        $reflectionMethod = new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]);
        $params = $reflectionMethod->getParameters();

        if (empty($params) || $params[0]->getType() === null) {
            throw new InvalidFilterException(
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

            $types = [self::resolveTypeName($type->getName(), $reflectionMethod)];

            if ($type->allowsNull() && $type->getName() !== 'null') {
                $types[] = 'null';
            }

            return $types;
        }

        if ($type instanceof ReflectionUnionType) {
            return array_map(
                static fn(ReflectionNamedType $namedType): string =>
                    self::resolveTypeName($namedType->getName(), $reflectionMethod),
                $type->getTypes(),
            );
        }

        return [];
    }

    /**
     * Extract non-null return type names from the transforming filter's callable.
     *
     * 'self' and 'static' in the return type are resolved to the declaring class FQCN so that
     * the output type registered on the property is a valid, importable class name.
     *
     * @return string[]
     *
     * @throws InvalidFilterException when return type is missing or void
     * @throws ReflectionException
     */
    public static function getReturnTypeNames(
        TransformingFilterInterface $filter,
        PropertyInterface $property,
    ): array {
        $reflectionMethod = self::reflectMethod($filter);
        $returnType = $reflectionMethod->getReturnType();

        if ($returnType === null) {
            throw new InvalidFilterException(
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

            if ($name === 'void' || $name === 'never') {
                throw new InvalidFilterException(
                    sprintf(
                        'Transforming filter %s must not declare a %s return type'
                            . ' for property %s in file %s',
                        $filter->getToken(),
                        $name,
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    ),
                );
            }

            if ($name === 'null' || $name === 'mixed') {
                return [];
            }

            return [self::resolveTypeName($name, $reflectionMethod)];
        }

        if ($returnType instanceof ReflectionUnionType) {
            return array_values(array_filter(
                array_map(
                    static fn(ReflectionNamedType $namedType): string =>
                        self::resolveTypeName($namedType->getName(), $reflectionMethod),
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
        $returnType = self::reflectMethod($filter)->getReturnType();

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
     * Reflect the filter callable into a ReflectionMethod for further type analysis.
     *
     * @throws ReflectionException
     */
    private static function reflectMethod(TransformingFilterInterface $filter): ReflectionMethod
    {
        return new ReflectionMethod($filter->getFilter()[0], $filter->getFilter()[1]);
    }

    /**
     * Resolve 'self' and 'static' type names to the declaring class FQCN.
     *
     * PHP's Reflection API returns the literal string 'self' or 'static' for self-referential
     * type hints. Neither is a valid class name for runtime use (e.g. is_a, instanceof, use
     * imports). This helper replaces them with the FQCN of the class that declares the method.
     *
     * For any other type name the input is returned unchanged.
     */
    private static function resolveTypeName(string $name, ReflectionMethod $method): string
    {
        return ($name === 'self' || $name === 'static')
            ? $method->getDeclaringClass()->getName()
            : $name;
    }
}
