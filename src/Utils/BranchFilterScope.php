<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\BranchFilteredValueValidator;
use PHPModelGenerator\Model\Validator\ConditionalPropertyValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;
use ReflectionException;
use WeakMap;

/**
 * Decides which declaration of a filter takes effect when the properties of composition branches are transferred to
 * the schema which contains the composition: rejects filters which can't be attributed to exactly one declaration,
 * keeps the filter on the branch class which owns it and lets the schema take over the value of the active branch.
 */
class BranchFilterScope
{
    /**
     * The filters which composition branches declare for a property of a schema, per schema and property name: the
     * token of the first filter, the composition which owns it, the signature of the filter list and whether the
     * branch executes the filters itself. A WeakMap, so the entries disappear together with the schema and a recycled
     * object id can never inherit the declarations of another schema.
     *
     * @var WeakMap<Schema, array<string, array<int, array{
     *     token: string,
     *     owner: AbstractComposedPropertyValidator,
     *     signature: string,
     *     executed: bool,
     * }>>>
     */
    private WeakMap $filterDeclarations;

    /**
     * The filters which a property of a schema adopted from a branch (see adoptExecutedBranchFilters()). They belong
     * to the branch declaration, not to the schema itself.
     *
     * @var WeakMap<FilterValidator, true>
     */
    private WeakMap $adoptedFilters;

    public function __construct(private readonly GeneratorConfiguration $generatorConfiguration)
    {
        $this->filterDeclarations = new WeakMap();
        $this->adoptedFilters = new WeakMap();
    }

    /**
     * Several branches of an anyOf can match, so the filter of a property can only be attributed when the outcome
     * doesn't depend on the matching branch: every branch declares the property with the same filters. Anything
     * else (a branch without the property, different filters, a filter handed over by a nested composition) is
     * rejected.
     *
     * @param string[] $propertyNames
     *
     * @throws SchemaException
     */
    public function assertAnyOfFiltersAreUnambiguous(
        AbstractComposedPropertyValidator $validator,
        Schema $schema,
        array $propertyNames,
    ): void {
        if (!$validator->isAnyOf()) {
            return;
        }

        foreach ($propertyNames as $propertyName) {
            $signatures = [];
            $firstFilteredProperty = null;
            $allFiltersExecuted = true;

            foreach ($validator->getComposedProperties() as $branch) {
                $branchProperty = $branch->getNestedSchema()?->getProperty($propertyName);
                $filterValidators = $branchProperty === null ? [] : FilterValidator::of($branchProperty);

                if ($filterValidators !== [] && $firstFilteredProperty === null) {
                    $firstFilteredProperty = [$branchProperty, $filterValidators[0]];
                }

                foreach ($filterValidators as $filterValidator) {
                    $allFiltersExecuted = $allFiltersExecuted && $filterValidator->isExecuted();
                }

                // null: the branch doesn't declare the property at all.
                $signatures[] = $branchProperty === null
                    ? null
                    : json_encode(array_map(
                        static fn(FilterValidator $filterValidator): array => [
                            $filterValidator->getFilter()->getToken(),
                            $filterValidator->getFilterOptions(),
                        ],
                        $filterValidators,
                    ));
            }

            if (
                $firstFilteredProperty === null
                || ($allFiltersExecuted && !in_array(null, $signatures, true) && count(array_unique($signatures)) === 1)
            ) {
                continue;
            }

            throw new SchemaException(
                sprintf(
                    "Filter '%s' on property '%s' declared in a branch of an 'anyOf' composition is not supported"
                        . ' in file %s: more than one branch can match, the filtered value can only be attributed'
                        . ' when every branch declares the property with the same filter',
                    $firstFilteredProperty[1]->getFilter()->getToken(),
                    $propertyName,
                    $schema->getJsonSchema()->getFile(),
                ),
                $firstFilteredProperty[0]->getJsonSchema(),
            );
        }
    }

    /**
     * Rejects filters which cannot be attributed to exactly one declaration.
     *
     * - A property which is filtered more than once by declarations that can apply to the same value: the schema
     *   itself plus any branch, several branches of an allOf, or the branches of different compositions. The
     *   filters have no defined order and only the first declaration would take effect. The branches of one
     *   oneOf or if/then/else exclude each other and may filter the same property differently.
     * - The condition of an if/then/else keeps its filters to itself, they never reach the schema.
     *
     * @throws SchemaException
     */
    public function assertBranchFilterIsDeclaredOnce(
        Schema $schema,
        AbstractComposedPropertyValidator $validator,
        CompositionPropertyDecorator $sourceBranch,
        PropertyInterface $branchProperty,
    ): void {
        $filterValidators = FilterValidator::of($branchProperty);
        $branchFilters = array_map(
            static fn(FilterValidator $filterValidator): string => $filterValidator->getFilter()->getToken(),
            $filterValidators,
        );

        if ($branchFilters === []) {
            return;
        }

        $signature = self::getFilterListSignature($filterValidators);
        $executed = array_filter(
            $filterValidators,
            static fn(FilterValidator $filterValidator): bool => !$filterValidator->isExecuted(),
        ) === [];

        if ($validator instanceof ConditionalPropertyValidator && $sourceBranch === $validator->getIfBranch()) {
            return;
        }

        $propertyName = $branchProperty->getName();

        $this->assertNotFilteredBySchemaItself($schema, $branchProperty, $branchFilters[0]);

        $declarations = $this->filterDeclarations[$schema][$propertyName] ?? [];

        foreach ($declarations as $declaration) {
            // Branches of the same oneOf or if/then/else are never active at the same time.
            if ($declaration['owner'] === $validator && !$validator->isAllOf()) {
                continue;
            }

            // All branches of an allOf filter the same input: identical filter lists (same filters, options and
            // order) which the branches execute themselves give the same result, the filters run once. Not for values
            // which the branches forward from nested compositions: these can select different branches, the
            // forwarded values can diverge.
            if (
                $declaration['owner'] === $validator
                && $declaration['executed']
                && $executed
                && $declaration['signature'] === $signature
            ) {
                continue;
            }

            throw $this->createFilteredMoreThanOnceException(
                $schema,
                $branchProperty,
                $declaration['token'],
                $branchFilters[0],
            );
        }

        $declarations[] = [
            'token' => $branchFilters[0],
            'owner' => $validator,
            'signature' => $signature,
            'executed' => $executed,
        ];

        $filterDeclarations = $this->filterDeclarations[$schema] ?? [];
        $filterDeclarations[$propertyName] = $declarations;
        $this->filterDeclarations[$schema] = $filterDeclarations;
    }

    /**
     * Rejects a branch filter for a property which the schema itself already filters. The filters of the property
     * are read when the check runs: a property which is not resolved yet (e.g. a $ref) gets its validators later, the
     * check is deferred until then.
     *
     * @throws SchemaException
     */
    private function assertNotFilteredBySchemaItself(
        Schema $schema,
        PropertyInterface $branchProperty,
        string $branchFilterToken,
    ): void {
        $schemaProperty = $schema->isRootRegistered($branchProperty->getName())
            ? $schema->getProperty($branchProperty->getName())
            : null;

        if ($schemaProperty === null) {
            return;
        }

        $assertNotFiltered = function () use ($schema, $schemaProperty, $branchProperty, $branchFilterToken): void {
            foreach (FilterValidator::of($schemaProperty) as $filterValidator) {
                // The non-executing filters describe the branches which hand over their value to the property, the
                // adopted filters belong to a branch declaration.
                if ($filterValidator->isExecuted() && !isset($this->adoptedFilters[$filterValidator])) {
                    throw $this->createFilteredMoreThanOnceException(
                        $schema,
                        $branchProperty,
                        $filterValidator->getFilter()->getToken(),
                        $branchFilterToken,
                    );
                }
            }
        };

        $schemaProperty->isResolved() ? $assertNotFiltered() : $schemaProperty->onResolve($assertNotFiltered);
    }

    /**
     * @param FilterValidator[] $filterValidators
     */
    private static function getFilterListSignature(array $filterValidators): string
    {
        return (string) json_encode(array_map(
            static fn(FilterValidator $filterValidator): array => [
                $filterValidator->getFilter()->getToken(),
                $filterValidator->getFilterOptions(),
            ],
            $filterValidators,
        ));
    }

    /**
     * A filter which a branch executes on the schema for every input (a single allOf branch, the identical filter
     * lists of several allOf branches or of every anyOf branch) is adopted by a property which is already registered
     * on the schema without a filter, as if the property were declared with the filter: the filter runs first, the
     * validators of the registered property check the filtered value. Without the adoption the registered property
     * kept its validators and the branch filter was silently dropped when the properties were merged.
     *
     * Detaches the filters from the transferred copy before it is merged (the merge compares the output types, the
     * output type of a transforming filter would conflict with the type of the registered property) and returns a
     * callback which lets the registered property adopt them after the merge, null if nothing is adopted.
     */
    public function detachFiltersForAdoption(
        Schema $schema,
        PropertyInterface $transferredProperty,
        PropertyInterface $branchProperty,
    ): ?callable {
        $registeredProperty = $schema->getProperty($transferredProperty->getName());
        $executedFilters = array_filter(
            FilterValidator::of($transferredProperty),
            static fn(FilterValidator $filterValidator): bool => $filterValidator->isExecuted(),
        );

        if (
            $registeredProperty === null
            || $executedFilters === []
            || !$registeredProperty->isResolved()
            // Filtered already: by the schema itself or by a previous branch (rejected or identical, see
            // assertBranchFilterIsDeclaredOnce()), the filters run once.
            || FilterValidator::of($registeredProperty) !== []
        ) {
            return null;
        }

        $transferredProperty->filterValidators(
            static fn(Validator $wrapper): bool => !in_array($wrapper->getValidator(), $executedFilters, true),
        );
        $transferredProperty->setType($transferredProperty->getType(), $transferredProperty->getType());

        // Rebuilt from the filters instead of read from the filter keyword of the branch property: a property which is
        // declared via $ref doesn't carry the keyword itself.
        $filterList = array_map(
            static fn(FilterValidator $filterValidator): array =>
                ['filter' => $filterValidator->getFilter()->getToken()] + $filterValidator->getFilterOptions(),
            array_values($executedFilters),
        );
        $jsonPointer = $branchProperty->getJsonSchema()->getPointer() . '/filter';

        $propertyName = $registeredProperty->getName();

        return function () use ($schema, $propertyName, $filterList, $jsonPointer): void {
            // Read after the merge: a property referencing a definition is detached from the definition by the merge,
            // the filter must not be added to the definition other properties share.
            $registeredProperty = $schema->getProperty($propertyName);
            $validatorsBefore = FilterValidator::of($registeredProperty);

            (new FilterProcessor())->process(
                $registeredProperty,
                $filterList,
                $this->generatorConfiguration,
                $schema,
                $jsonPointer,
            );

            foreach (FilterValidator::of($registeredProperty) as $filterValidator) {
                if (!in_array($filterValidator, $validatorsBefore, true)) {
                    $this->adoptedFilters[$filterValidator] = true;
                }
            }
        };
    }

    private function createFilteredMoreThanOnceException(
        Schema $schema,
        PropertyInterface $branchProperty,
        string $firstFilterToken,
        string $secondFilterToken,
    ): SchemaException {
        return new SchemaException(
            sprintf(
                "Property '%s' is filtered more than once ('%s', '%s') in file %s:"
                    . ' filters of the same property have no defined order',
                $branchProperty->getName(),
                $firstFilterToken,
                $secondFilterToken,
                $schema->getJsonSchema()->getFile(),
            ),
            $branchProperty->getJsonSchema(),
        );
    }

    /**
     * Makes sure the transferred copy of a branch property doesn't execute the filter of the branch.
     *
     * The branch class owns the filter. A branch which publishes its filtered value (see
     * AbstractComposedPropertyValidator::publishesFilteredValueOf) hands the result to the schema, so the copy
     * keeps a non-executing description of the filter: the output type and the serializer of a transforming
     * filter are derived from the filters of a property. The condition of an if/then/else only selects the
     * branch, its filters keep running inside the condition but the schema keeps the value as provided, so
     * the copy must not announce a filter at all. Executing the filter on the schema would run it
     * regardless of the active branch.
     */
    public function scopeFiltersToBranch(
        PropertyInterface $transferredProperty,
        CompositionPropertyDecorator $sourceBranch,
        AbstractComposedPropertyValidator $validator,
    ): void {
        $isFilterValidator = static fn(Validator $wrapper): bool => $wrapper->getValidator() instanceof FilterValidator;

        if ($validator instanceof ConditionalPropertyValidator && $sourceBranch === $validator->getIfBranch()) {
            $transferredProperty->filterValidators(
                static fn(Validator $wrapper): bool => !$isFilterValidator($wrapper),
            );

            return;
        }

        if (!$validator->publishesFilteredValueOf($sourceBranch, $transferredProperty)) {
            return;
        }

        $executedFilters = array_filter(
            $transferredProperty->getValidators(),
            static fn(Validator $wrapper): bool => $isFilterValidator($wrapper)
                && $wrapper->getValidator()->isExecuted(),
        );

        $transferredProperty->filterValidators(
            static fn(Validator $wrapper): bool => !in_array($wrapper, $executedFilters, true),
        );

        foreach ($executedFilters as $wrapper) {
            $transferredProperty->addValidator(
                $wrapper->getValidator()->withoutExecution(),
                $wrapper->getPriority(),
                $wrapper->getSourceKey(),
            );

            $this->widenOutputTypeForBranchFilter($transferredProperty, $wrapper->getValidator());
        }
    }

    /**
     * The branch which owns a transforming filter may be inactive, so the transferred property holds the
     * value as provided or the transformed value. The output type of the branch class only describes the
     * transformed value; merging the copies of several branches would then drop the raw type.
     *
     * @throws ReflectionException
     */
    private function widenOutputTypeForBranchFilter(PropertyInterface $property, FilterValidator $filterValidator): void
    {
        $filter = $filterValidator->getFilter();
        $inputType = $property->getType();

        // Without a type the property accepts every value, there is no output type to widen.
        if (!($filter instanceof TransformingFilterInterface) || $inputType === null) {
            return;
        }

        $property->setType(
            $inputType,
            BranchFilterOutputType::create(
                $inputType,
                $property->getType(true),
                FilterReflection::getReturnTypeNames($filter, $property),
                FilterReflection::isReturnNullable($filter),
                $this->generatorConfiguration,
            ),
        );
    }

    /**
     * Lets the property which is registered on the schema take the value which the active branch published.
     *
     * The registered property is not necessarily the transferred copy: a property which is declared by the
     * schema itself or by another branch is registered first and the validators of a later copy are dropped
     * when the properties are merged. Registering a property by name happens immediately, so the transferred copy
     * has always been registered at this point.
     */
    public function takeOverBranchFilteredValue(Schema $schema, PropertyInterface $branchProperty): void
    {
        $registeredProperty = $schema->getProperty($branchProperty->getName());

        $hasSubstitution = false;
        $describedFilters = array_map(
            static fn(FilterValidator $filterValidator): string => $filterValidator->getIdentifier(),
            FilterValidator::of($registeredProperty),
        );

        foreach ($registeredProperty->getValidators() as $wrapper) {
            $hasSubstitution = $hasSubstitution || $wrapper->getValidator() instanceof BranchFilteredValueValidator;
        }

        if (!$hasSubstitution) {
            // Runs in front of every other validator of the property.
            $registeredProperty->addValidator(new BranchFilteredValueValidator($registeredProperty), 0);
        }

        // Then and else (or the branches of a oneOf) may transform the same property differently: the output type
        // and the serializer of the property have to know every filter whose value the property can hold. The same
        // filter with different options counts as a different filter, it serializes differently.
        foreach ($branchProperty->getValidators() as $wrapper) {
            $filterValidator = $wrapper->getValidator();

            if (
                !($filterValidator instanceof FilterValidator)
                || in_array($filterValidator->getIdentifier(), $describedFilters, true)
            ) {
                continue;
            }

            $describedFilters[] = $filterValidator->getIdentifier();
            $registeredProperty->addValidator(
                $filterValidator->withoutExecution(),
                $wrapper->getPriority(),
                $wrapper->getSourceKey(),
            );
        }
    }
}
