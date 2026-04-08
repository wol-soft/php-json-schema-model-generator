<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\Utils\PropertyMerger;

class ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor extends PostProcessor
{
    /**
     * @throws SchemaException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $this->applyPatternPropertiesTypeIntersection($schema, $generatorConfiguration);
        $this->transferPatternPropertiesFilterToProperty($schema, $generatorConfiguration);

        $schema->addSchemaHook(
            new class ($schema) implements SetterBeforeValidationHookInterface {
                public function __construct(private readonly Schema $schema)
                {}

                public function getCode(PropertyInterface $property, bool $batchUpdate = false): string
                {
                    $json = $this->schema->getJsonSchema()->getJson();
                    // A batch update must execute the base validators to check the integrity of the object.
                    // Consequently, the schema hook must not add validation code in that places.
                    if ($batchUpdate || !isset($json['patternProperties'])) {
                        return '';
                    }

                    $matchesAnyPattern = false;

                    foreach (array_keys($json['patternProperties']) as $pattern) {
                        if (preg_match('/' . addcslashes($pattern, '/') . '/', $property->getName())) {
                            $matchesAnyPattern = true;
                        }
                    }

                    if (!$matchesAnyPattern) {
                        return '';
                    }

                    // TODO: extract pattern property validation from the base validator into a separate method and
                    // TODO: call only the pattern property validation at this location to avoid executing unnecessary
                    // TODO: validators
                    return sprintf(
                        '
                            $modelData = array_merge($this->rawModelDataInput, ["%s" => $value]);
                            $this->executeBaseValidators($modelData);
                        ',
                        $property->getName(),
                    );
                }
            },
        );
    }

    /**
     * For every declared/composition-transferred property whose name matches a patternProperties
     * pattern, intersect the property's type with the pattern's type constraint.
     *
     * patternProperties applies simultaneously with properties (allOf semantics), so the
     * effective type of a matching property is the intersection. An empty intersection means the
     * schema is unsatisfiable and a SchemaException is thrown.
     *
     * Properties with no type (truly untyped) and pattern validators with no type are skipped.
     *
     * @throws SchemaException
     */
    protected function applyPatternPropertiesTypeIntersection(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $patternPropertiesValidators = array_filter(
            $schema->getBaseValidators(),
            static fn(PropertyValidatorInterface $validator): bool => $validator instanceof PatternPropertiesValidator,
        );

        if (empty($patternPropertiesValidators)) {
            return;
        }

        $merger = new PropertyMerger($generatorConfiguration);

        foreach ($schema->getProperties() as $property) {
            if ($property->isInternal()) {
                continue;
            }

            $propertyType = $property->getType(true);

            if ($propertyType === null) {
                continue;
            }

            /** @var PatternPropertiesValidator $patternPropertiesValidator */
            foreach ($patternPropertiesValidators as $patternPropertiesValidator) {
                if (
                    !preg_match(
                        '/' . addcslashes($patternPropertiesValidator->getPattern(), '/') . '/',
                        $property->getName(),
                    )
                ) {
                    continue;
                }

                $patternType = $this->resolvePatternTypeFromJson(
                    $schema->getJsonSchema()->getJson()['patternProperties'][$patternPropertiesValidator->getPattern()],
                    $generatorConfiguration,
                );

                if ($patternType === null) {
                    continue;
                }

                $merger->applyTypeConstraint(
                    $property,
                    $patternType,
                    sprintf(
                        "Property '%s' has type %s but the matching patternProperties pattern '%s' requires type %s." .
                        " These constraints are contradictory, making this schema unsatisfiable.",
                        $property->getName(),
                        implode('|', $property->getType(true)->getNames()),
                        $patternPropertiesValidator->getPattern(),
                        implode('|', $patternType->getNames()),
                    ),
                );
            }
        }
    }

    /**
     * Resolve the PHP-side PropertyType from a raw patternProperties JSON schema entry.
     *
     * Returns null when the schema has no 'type' key or when the pattern carries a transforming
     * filter (in that case the declared property type has already been replaced by the filter's
     * output type by transferPatternPropertiesFilterToProperty, so a JSON-level type comparison
     * would produce a false conflict).
     *
     * Non-transforming filters (e.g. trim, notEmpty) do not change the PHP type and are not a
     * reason to skip the intersection check.
     */
    private function resolvePatternTypeFromJson(
        array $patternJson,
        GeneratorConfiguration $generatorConfiguration,
    ): ?PropertyType {
        if (!isset($patternJson['type'])) {
            return null;
        }

        // A transforming filter changes the PHP type of the property (e.g. string → DateTime).
        // Skip the intersection only when at least one filter in the pattern is transforming.
        if ($this->patternHasTransformingFilter($patternJson, $generatorConfiguration)) {
            return null;
        }

        /** @var string|string[] $rawType */
        $rawType = $patternJson['type'];
        $typeNames = is_array($rawType) ? $rawType : [$rawType];

        $phpTypeMap = [
            'integer' => 'int',
            'number'  => 'float',
            'boolean' => 'bool',
        ];

        $phpNames = array_map(
            static fn(string $t): string => $phpTypeMap[$t] ?? $t,
            array_filter($typeNames, static fn(string $t): bool => $t !== 'null'),
        );

        if (empty($phpNames)) {
            return null;
        }

        $nullable = in_array('null', $typeNames, true) ? true : null;

        return new PropertyType(array_values($phpNames), $nullable);
    }

    /**
     * Returns true if the pattern JSON contains at least one transforming filter.
     */
    private function patternHasTransformingFilter(
        array $patternJson,
        GeneratorConfiguration $generatorConfiguration,
    ): bool {
        if (!isset($patternJson['filter'])) {
            return false;
        }

        foreach (FilterProcessor::normalizeFilterList($patternJson['filter']) as $filterToken) {
            if (is_array($filterToken)) {
                $filterToken = $filterToken['filter'] ?? '';
            }

            if ($generatorConfiguration->getFilter((string) $filterToken) instanceof TransformingFilterInterface) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws SchemaException
     */
    protected function transferPatternPropertiesFilterToProperty(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $patternPropertiesValidators = array_filter(
            $schema->getBaseValidators(),
            static fn(PropertyValidatorInterface $validator): bool => $validator instanceof PatternPropertiesValidator
        );

        if (empty($patternPropertiesValidators)) {
            return;
        }

        foreach ($schema->getProperties() as $property) {
            $propertyHasTransformingFilter = !empty(
                array_filter(
                    $property->getValidators(),
                    static fn(Validator $validator): bool =>
                        $validator->getValidator() instanceof FilterValidator &&
                        $validator->getValidator()->getFilter() instanceof TransformingFilterInterface,
                )
            );

            /** @var PatternPropertiesValidator $patternPropertiesValidator */
            foreach ($patternPropertiesValidators as $patternPropertiesValidator) {
                if (
                    !preg_match(
                        '/' . addcslashes($patternPropertiesValidator->getPattern(), '/') . '/',
                        $property->getName(),
                    )
                ) {
                    continue;
                }
                if (
                    !isset(
                        $schema->getJsonSchema()->getJson()
                        ['patternProperties']
                        [$patternPropertiesValidator->getPattern()]
                        ['filter'],
                    )
                ) {
                    continue;
                }
                if ($propertyHasTransformingFilter) {
                    foreach (
                        $patternPropertiesValidator->getValidationProperty()->getValidators() as $validator
                    ) {
                        if (
                            $validator->getValidator() instanceof FilterValidator &&
                            $validator->getValidator()->getFilter() instanceof TransformingFilterInterface
                        ) {
                            throw new SchemaException(
                                sprintf(
                                    'Applying multiple transforming filters for property %s'
                                        . ' is not supported in file %s',
                                    $property->getName(),
                                    $property->getJsonSchema()->getFile(),
                                )
                            );
                        }
                    }
                }

                (new FilterProcessor())->process(
                    $property,
                    $schema->getJsonSchema()->getJson()
                        ['patternProperties']
                        [$patternPropertiesValidator->getPattern()]
                        ['filter'],
                    $generatorConfiguration,
                    $schema,
                );
            }
        }
    }
}
