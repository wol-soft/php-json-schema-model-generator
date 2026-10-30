<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use JsonSerializable;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Interfaces\SerializationInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator\AdditionalPropertiesValidator;
use PHPModelGenerator\Model\Validator\ArrayItemValidator;
use PHPModelGenerator\Model\Validator\ArrayTupleValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\Model\Validator\UnevaluatedPropertiesValidator;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\SchemaProcessor\Hook\SerializationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;
use PHPModelGenerator\Traits\SerializableTrait;
use PHPModelGenerator\Utils\ArrayItemFilterScope;
use PHPModelGenerator\Utils\FilterReflection;
use PHPModelGenerator\Utils\RenderHelper;
use PHPModelGenerator\Utils\TypeCheck;
use ReflectionException;

class SerializationPostProcessor extends PostProcessor
{
    /**
     * Add serialization support to the provided schema
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $schema
            ->addTrait(SerializableTrait::class)
            ->addInterface(JsonSerializable::class)
            ->addInterface(SerializationInterface::class);

        $this->addSerializeFunctionsForTransformingFilters($schema, $generatorConfiguration);
        $this->addSerializationHookMethod($schema, $generatorConfiguration);
        $this->addSkipNotProvidedPropertiesMap($schema, $generatorConfiguration);
        $this->addWriteOnlyExclusion($schema, $generatorConfiguration);

        $json = $schema->getJsonSchema()->getJson();
        if (isset($json['additionalProperties']) && $json['additionalProperties'] !== false) {
            $this->addAdditionalPropertiesTransformingFilterSerializer($schema, $generatorConfiguration);
        }

        if (isset($json['unevaluatedProperties']) && $json['unevaluatedProperties'] !== false) {
            $this->addUnevaluatedPropertiesTransformingFilterSerializer($schema, $generatorConfiguration);
        }
    }

    /**
     * Renders the body of the serializer of a property which holds the value as provided or the output of one of
     * several filters. A filter which declares no return type can't be recognized by its output, it is tried last:
     * trying it earlier would make the serializers of the other filters unreachable. Of several such filters only
     * the first one is reachable.
     *
     * @param FilterValidator[] $filterValidators
     *
     * @throws ReflectionException
     */
    private function renderGuardedSerializers(array $filterValidators, PropertyInterface $property): string
    {
        $code = ['$value = $this->' . $property->getAttribute(true) . ';', ''];
        $unconditionalSerialization = null;

        // Several filters can produce a value of the same type: the property remembers the filter which produced the
        // value. A value no branch filter produced (e.g. set while no branch which filters the property is active)
        // falls back to the serializers guarded by the output type.
        if (FilterValidator::requiresSourceTracking($property)) {
            $code[] = sprintf(
                'switch ($this->_branchFilteredSources[%s] ?? null) {',
                var_export($property->getName(), true),
            );

            foreach (FilterValidator::branchTransformingFiltersOf($property) as $filterValidator) {
                array_push(
                    $code,
                    '    case ' . var_export($filterValidator->getIdentifier(), true) . ':',
                    '        ' . $this->renderSerialization($filterValidator),
                );
            }

            array_push($code, '}', '');
        }

        foreach ($filterValidators as $filterValidator) {
            $returnTypeNames = FilterReflection::getReturnTypeNames($filterValidator->getFilter(), $property);
            $serialization = $this->renderSerialization($filterValidator);

            if ($returnTypeNames === []) {
                $unconditionalSerialization ??= $serialization;

                continue;
            }

            array_push(
                $code,
                'if ' . TypeCheck::buildCompound($returnTypeNames) . ' {',
                "    $serialization",
                '}',
                '',
            );
        }

        $code[] = $unconditionalSerialization ?? 'return $value;';

        return implode("\n", $code);
    }

    private function renderSerialization(FilterValidator $filterValidator): string
    {
        [$serializerClass, $serializerMethod] = $filterValidator->getFilter()->getSerializer();

        return "return \\{$serializerClass}::{$serializerMethod}("
            . '$value, ' . RenderHelper::varExportArray($filterValidator->getFilterOptions()) . ');';
    }

    /**
     * Each transforming filter must provide a method to serialize the value. Add a method to the schema to call the
     * serialization for each property with a transforming filter
     */
    private function addSerializeFunctionsForTransformingFilters(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        foreach ($schema->getProperties() as $property) {
            $transformingFilterValidators = [];

            foreach ($property->getValidators() as $propertyValidator) {
                $validator = $propertyValidator->getValidator();

                if (
                    $validator instanceof FilterValidator
                    && $validator->getFilter() instanceof TransformingFilterInterface
                ) {
                    $transformingFilterValidators[] = $validator;
                }
            }

            if ($transformingFilterValidators !== []) {
                $this->addPropertyTransformingFilterSerializer(
                    $schema,
                    $generatorConfiguration,
                    $property,
                    $transformingFilterValidators,
                );
            }

            $this->addArrayItemsTransformingFilterSerializer($schema, $generatorConfiguration, $property);
        }

        foreach ($schema->getBaseValidators() as $validator) {
            if (!$validator instanceof PatternPropertiesValidator) {
                continue;
            }

            $levelsOnPath = [];
            $entrySerializer = self::serializerOf($this->createSlot(
                $schema,
                $generatorConfiguration,
                $validator->getValidationProperty(),
                false,
                'Pattern' . $validator->getKey(),
                $levelsOnPath,
            ));

            if ($entrySerializer === null) {
                continue;
            }

            $schema->addMethod(
                "_serialize{$validator->getKey()}",
                new RenderedMethod(
                    $schema,
                    $generatorConfiguration,
                    join(
                        DIRECTORY_SEPARATOR,
                        ['Serialization', 'PatternPropertyTransformingFilterSerializer.phptpl'],
                    ),
                    ['key' => $validator->getKey(), 'entrySerializer' => $entrySerializer],
                )
            );
        }
    }

    /**
     * @param FilterValidator[] $transformingFilterValidators The transforming filters of the property
     *
     * @throws ReflectionException
     */
    private function addPropertyTransformingFilterSerializer(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $property,
        array $transformingFilterValidators,
    ): void {
        $templateValues = ['property' => $property, 'guardedSerializers' => ''];

        // A filter which is owned by a branch of a composition may not have run: the property then still holds
        // the value as provided. Only a value of the output type of a filter is serialized by it.
        $ownedByBranch = array_filter(
            $transformingFilterValidators,
            static fn(FilterValidator $validator): bool => !$validator->isExecuted(),
        );

        if ($ownedByBranch !== []) {
            $templateValues['guardedSerializers'] = $this->renderGuardedSerializers(
                $transformingFilterValidators,
                $property,
            );
        } else {
            // Only one transforming filter can be executed on a property.
            $validator = $transformingFilterValidators[0];
            [$serializerClass, $serializerMethod] = $validator->getFilter()->getSerializer();

            $templateValues += [
                'serializerClass' => $serializerClass,
                'serializerMethod' => $serializerMethod,
                'serializerOptions' => RenderHelper::varExportArray($validator->getFilterOptions()),
            ];
        }

        $schema->addMethod(
            "_serialize{$property->getAttribute()}",
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                join(DIRECTORY_SEPARATOR, ['Serialization', 'TransformingFilterSerializer.phptpl']),
                $templateValues,
            ),
        );
    }

    /**
     * Generates the serializer of the items of an array property. The items are validated by the property itself and
     * by the branches of its compositions, the filters of every declaration are combined into a single generated
     * _serialize{Property}() method: two independent addMethod() calls with the same method name would silently
     * overwrite each other (Schema::addMethod() is a plain array write).
     *
     * Per slot a single declaration filters the items (see ArrayItemFilterScope::assertItemSlotsAreFilteredOnce()):
     * the schema-form items, each tuple index and the unevaluatedItems. Schema-form items and unevaluatedItems of the
     * same property are declared by different branches, the indices evaluated by unevaluatedItems are serialized by
     * its filter, all other indices by the filter of the schema-form items.
     *
     * @throws ReflectionException
     */
    private function addArrayItemsTransformingFilterSerializer(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $arrayProperty,
    ): void {
        if (!ArrayItemFilterScope::filtersItemsOnAnyLevel($arrayProperty)) {
            return;
        }

        $levelsOnPath = [];
        $level = $this->buildArrayLevel(
            $schema,
            $generatorConfiguration,
            $arrayProperty,
            false,
            true,
            ucfirst($arrayProperty->getAttribute()),
            $levelsOnPath,
        );

        $schema->addMethod(
            "_serialize{$arrayProperty->getAttribute()}",
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                join(DIRECTORY_SEPARATOR, ['Serialization', 'ArrayItemsTransformingFilterSerializer.phptpl']),
                $level + [
                    'property' => $arrayProperty,
                    'arrayPropertyName' => $arrayProperty->getName(),
                    'methodName' => '',
                ],
            ),
        );
    }

    /**
     * Collects how the slots of one array level are serialized: the schema-form items, each tuple index and, on the
     * level of the property itself, the unevaluatedItems.
     *
     * The caller has to make sure that the level filters items (ArrayItemFilterScope::filtersItemsOnAnyLevel()). The
     * check follows the declarations the same way as this method, so at least one slot is serialized. A level which
     * serializes nothing is not guarded here, the method would only be an empty copy of its parameter.
     *
     * A slot holds either the serializer of a filter or, if the items are arrays themselves, a call of the generated
     * method of the nested level (see createSlot()). Several declarations may contribute to one slot (the branches of
     * an anyOf): see finishSlot().
     *
     * The unevaluatedItems of a nested level are not visited. Their indices are tracked per property name
     * (_evaluatedItemIndices), the nested arrays of all elements would share one entry and the serializer could not
     * tell which indices the keyword evaluated in which nested array.
     *
     * The indices which have a serializer of their own (`serializedIndices`) are skipped by the serializer of the
     * schema-form items: the slots overlap and may reach the very same filter declaration, the serializer must run
     * once for the item. Rejecting the combination of schema-form items and a tuple index which reach the same
     * filter was considered and dropped: a definition shared by both declarations is a valid schema.
     *
     * @param bool $conditional Whether the level itself is reached through a branch which may be inactive
     * @param bool $root Whether the level is the array property of the model (it owns the unevaluatedItems tracking)
     * @param string $levelName The part of the generated method name which identifies the level
     * @param array<string, string> $levelsOnPath Method names of the levels on the way to this level
     *
     * @return array{
     *     itemsSerializer: array{call: string, guard: string}|null,
     *     indexSerializers: array<int, array{index: int, call: string, guard: string}>,
     *     serializedIndices: int[],
     *     staticIndices: int[],
     *     dynamicSerializer: array{call: string, guard: string}|null,
     * }
     *
     * @throws ReflectionException
     */
    private function buildArrayLevel(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $arrayProperty,
        bool $conditional,
        bool $root,
        string $levelName,
        array &$levelsOnPath,
    ): array {
        $itemsParts = self::emptySlot();
        $indexParts = [];
        $dynamicParts = self::emptySlot();

        foreach (ArrayItemFilterScope::declarationsOf($arrayProperty) as $declaration) {
            $validator = $declaration->validator;
            $declarationConditional = $conditional || $declaration->conditional;

            if ($validator instanceof ArrayItemValidator) {
                self::addToSlot(
                    $itemsParts,
                    $this->createSlot(
                        $schema,
                        $generatorConfiguration,
                        $validator->getNestedProperty(),
                        $declarationConditional,
                        $levelName . 'Items',
                        $levelsOnPath,
                    ),
                );
            } elseif ($validator instanceof ArrayTupleValidator) {
                foreach ($validator->getTupleProperties() as $index => $tupleProperty) {
                    $indexParts[$index] ??= self::emptySlot();

                    self::addToSlot(
                        $indexParts[$index],
                        $this->createSlot(
                            $schema,
                            $generatorConfiguration,
                            $tupleProperty,
                            $declarationConditional,
                            $levelName . 'Index' . $index,
                            $levelsOnPath,
                        ),
                    );
                }
            } elseif ($root) {
                self::addToSlot(
                    $dynamicParts,
                    $this->createSlot(
                        $schema,
                        $generatorConfiguration,
                        $validator->getValidationProperty(),
                        $declarationConditional,
                        $levelName . 'Unevaluated',
                        $levelsOnPath,
                    ),
                );
            }
        }

        $itemsSerializer = self::finishSlot($itemsParts);
        $dynamicSerializer = self::finishSlot($dynamicParts);
        $indexSerializers = [];

        foreach ($indexParts as $index => $parts) {
            $indexSerializer = self::finishSlot($parts);

            if ($indexSerializer !== null) {
                $indexSerializers[] = ['index' => $index] + $indexSerializer;
            }
        }

        return [
            'itemsSerializer' => $itemsSerializer,
            'indexSerializers' => $indexSerializers,
            'serializedIndices' => array_column($indexSerializers, 'index'),
            'staticIndices' => array_keys($indexParts),
            'dynamicSerializer' => $dynamicSerializer,
        ];
    }

    /**
     * @return array{filter: array{call: string, guard: string}|null, levels: array<string, string>}
     */
    private static function emptySlot(): array
    {
        return ['filter' => null, 'levels' => []];
    }

    /**
     * @param array{filter: array{call: string, guard: string}|null, levels: array<string, string>} $slot
     * @param array{kind: string, call: string, guard: string, method?: string}|null $contribution
     */
    private static function addToSlot(array &$slot, ?array $contribution): void
    {
        if ($contribution === null) {
            return;
        }

        if ($contribution['kind'] === 'level') {
            $slot['levels'][$contribution['method']] = $contribution['method'];

            return;
        }

        // The declarations of one slot reach the same filter (ArrayItemFilterScope::assertItemSlotsAreFilteredOnce()),
        // the first stands for all of them.
        $slot['filter'] ??= ['call' => $contribution['call'], 'guard' => $contribution['guard']];
    }

    /**
     * Combines the contributions of the declarations of a slot into the serialization of one value.
     *
     * The declarations of a slot are alternatives if they are declared by the branches of a composition: an element is
     * either an array validated by one of the nested levels or it is filtered itself (a tree of arrays whose leaves
     * are arrays of filtered items). An element which is an array is run through the nested levels, any other element
     * through the filter. A nested level only serializes what its own filter produced, so the levels of alternative
     * branches can be applied one after the other.
     *
     * Choosing the first contribution only was rejected: the nested level of an earlier branch would hide the filter
     * of a later one.
     *
     * @param array{filter: array{call: string, guard: string}|null, levels: array<string, string>} $slot
     *
     * @return array{call: string, guard: string}|null
     */
    private static function finishSlot(array $slot): ?array
    {
        if ($slot['levels'] === []) {
            return $slot['filter'];
        }

        $levelCall = '$value';
        foreach ($slot['levels'] as $methodName) {
            $levelCall = '$this->' . $methodName . '(' . $levelCall . ')';
        }

        if ($slot['filter'] === null) {
            return ['call' => $levelCall, 'guard' => 'is_array($value)'];
        }

        $filterCall = $slot['filter']['guard'] === ''
            ? $slot['filter']['call']
            : '(' . $slot['filter']['guard'] . ' ? ' . $slot['filter']['call'] . ' : $value)';

        return ['call' => 'is_array($value) ? ' . $levelCall . ' : ' . $filterCall, 'guard' => ''];
    }

    /**
     * The serialization of the value of a slot which a single declaration contributes (an entry of the additional,
     * pattern or unevaluated properties). Null if the value isn't filtered.
     *
     * @param array{kind: string, call: string, guard: string, method?: string}|null $contribution
     *
     * @return array{call: string, guard: string}|null
     */
    private static function serializerOf(?array $contribution): ?array
    {
        $slot = self::emptySlot();
        self::addToSlot($slot, $contribution);

        return self::finishSlot($slot);
    }

    /**
     * Describes what a declaration contributes to the serialization of the value of one slot (the items of an array,
     * an entry of the additional, pattern or unevaluated properties). Null if the value of the slot isn't filtered.
     * The contribution is either `filter` (the value is filtered itself) or `level` (the value is an array with
     * filtered items on a nested level, `method` serializes it). `call` is an expression which serializes `$value`,
     * `guard` the condition under which the call is applied (empty if it always is).
     *
     * An array never has a transforming filter of its own (FilterProcessor rejects it), so a declaration contributes
     * one or the other.
     *
     * A nested level is generated as a method of its own. Inlining the levels was rejected: the levels of a
     * schema which references itself can't be unrolled, a call of the method of the level on the way back is the
     * natural form of the recursion.
     *
     * @param array<string, string> $levelsOnPath Method names of the levels on the way to this slot
     *
     * @return array{kind: string, call: string, guard: string, method?: string}|null
     *
     * @throws ReflectionException
     */
    private function createSlot(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        PropertyInterface $valueProperty,
        bool $conditional,
        string $levelName,
        array &$levelsOnPath,
    ): ?array {
        $filterValidator = ArrayItemFilterScope::transformingFilterOf($valueProperty);
        if ($filterValidator !== null) {
            return $this->createFilterSlot($filterValidator, $valueProperty, $conditional);
        }

        if (!ArrayItemFilterScope::filtersItemsOnAnyLevel($valueProperty, false)) {
            return null;
        }

        // A level is shared by the paths which reach it with the same activity: the guards of the filters inside are
        // part of the method.
        $levelKey = ArrayItemFilterScope::identityOf($valueProperty) . ($conditional ? ':conditional' : ':certain');

        // The slot refers to a level which is being built further up (the schema references itself): the level calls
        // its own method.
        if (isset($levelsOnPath[$levelKey])) {
            return self::levelContribution($levelsOnPath[$levelKey]);
        }

        $methodName = $schema->reserveMethodName('_nestedItemsSerializer' . $levelName);
        $levelsOnPath[$levelKey] = $methodName;

        $level = $this->buildArrayLevel(
            $schema,
            $generatorConfiguration,
            $valueProperty,
            $conditional,
            false,
            $levelName,
            $levelsOnPath,
        );

        unset($levelsOnPath[$levelKey]);

        $schema->addMethod(
            $methodName,
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                join(DIRECTORY_SEPARATOR, ['Serialization', 'ArrayItemsTransformingFilterSerializer.phptpl']),
                $level + [
                    'property' => $valueProperty,
                    'arrayPropertyName' => $valueProperty->getName(),
                    'methodName' => $methodName,
                ],
            ),
        );

        return self::levelContribution($methodName);
    }

    /**
     * @return array{kind: string, call: string, guard: string, method: string}
     */
    private static function levelContribution(string $methodName): array
    {
        return [
            'kind' => 'level',
            'call' => '$this->' . $methodName . '($value)',
            'guard' => 'is_array($value)',
            'method' => $methodName,
        ];
    }

    /**
     * An item of a declaration inside a branch which may be inactive is only serialized if it is an instance of the
     * output type of the filter (the guard): the branch may not have transformed the items, an item which still has
     * the value as provided must not reach the serializer. A filter without a declared return type can't be guarded,
     * its serializer is always applied - the same limitation as for the filters of properties of several branches.
     *
     * @return array{kind: string, call: string, guard: string}
     *
     * @throws ReflectionException
     */
    private function createFilterSlot(
        FilterValidator $filterValidator,
        PropertyInterface $valueProperty,
        bool $conditional,
    ): array {
        [$serializerClass, $serializerMethod] = $filterValidator->getFilter()->getSerializer();

        $returnTypeNames = $conditional
            ? FilterReflection::getReturnTypeNames($filterValidator->getFilter(), $valueProperty)
            : [];

        return [
            'kind' => 'filter',
            'call' => sprintf(
                '\\%s::%s($value, %s)',
                $serializerClass,
                $serializerMethod,
                RenderHelper::varExportArray($filterValidator->getFilterOptions()),
            ),
            'guard' => $returnTypeNames === [] ? '' : TypeCheck::buildCompound($returnTypeNames),
        ];
    }

    private function addSerializationHookMethod(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $schema->addMethod(
            '_resolveSerializationHook',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                join(DIRECTORY_SEPARATOR, ['Serialization', 'SerializationHook.phptpl']),
                [
                    'schemaHookResolver' => new SchemaHookResolver($schema),
                ],
            )
        );
    }

    /**
     * When additional properties have a transforming filter, override _serializeAdditionalProperties on the model
     * so that the filter's deserializer runs before values are serialized.
     *
     * For the generic case (no transforming filter), SerializableTrait._serializeAdditionalProperties handles
     * serialization directly — no model-side override is needed.
     */
    public function addAdditionalPropertiesTransformingFilterSerializer(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $validationProperty = null;
        foreach ($schema->getBaseValidators() as $validator) {
            if (is_a($validator, AdditionalPropertiesValidator::class)) {
                $validationProperty = $validator->getValidationProperty();
            }
        }

        if (!$validationProperty) {
            return;
        }

        $levelsOnPath = [];
        $entrySerializer = self::serializerOf($this->createSlot(
            $schema,
            $generatorConfiguration,
            $validationProperty,
            false,
            'AdditionalProperties',
            $levelsOnPath,
        ));

        // Only generate the model-side override when the values are filtered themselves or are arrays with filtered
        // items. The trait's default _serializeAdditionalProperties handles the generic case.
        if ($entrySerializer === null) {
            return;
        }

        $schema->addMethod(
            '_serializeAdditionalProperties',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'Serialization/AdditionalPropertiesSerializer.phptpl',
                ['entrySerializer' => $entrySerializer],
            )
        );
    }

    /**
     * When unevaluated properties have a transforming filter, override
     * _serializeUnevaluatedProperties on the model so the filter's serialize() runs before the
     * values reach the generic serializer. Without this, transformed values (e.g. DateTime
     * instances) reach the generic path unfiltered and drop to empty arrays in the output.
     *
     * The generic case (no transforming filter) is handled by
     * SerializableTrait::_serializeUnevaluatedProperties.
     */
    public function addUnevaluatedPropertiesTransformingFilterSerializer(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $validationProperty = null;
        foreach ($schema->getPostCompositionValidators() as $validator) {
            if (is_a($validator, UnevaluatedPropertiesValidator::class)) {
                $validationProperty = $validator->getValidationProperty();
                break;
            }
        }

        if (!$validationProperty) {
            return;
        }

        $levelsOnPath = [];
        $entrySerializer = self::serializerOf($this->createSlot(
            $schema,
            $generatorConfiguration,
            $validationProperty,
            false,
            'UnevaluatedProperties',
            $levelsOnPath,
        ));

        if ($entrySerializer === null) {
            return;
        }

        $schema->addMethod(
            '_serializeUnevaluatedProperties',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'Serialization/UnevaluatedPropertiesSerializer.phptpl',
                ['entrySerializer' => $entrySerializer],
            )
        );
    }

    private function addWriteOnlyExclusion(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $writeOnlyAttributes = array_map(
            static fn(PropertyInterface $property): string => $property->getAttribute(true),
            array_filter(
                $schema->getProperties(),
                static fn(PropertyInterface $property): bool => $property->isWriteOnly(),
            ),
        );

        if (!$writeOnlyAttributes) {
            return;
        }

        $keysExport = RenderHelper::varExportArray(array_values($writeOnlyAttributes));

        $schema->addSchemaHook(
            new class ($keysExport) implements SerializationHookInterface
            {
                public function __construct(private readonly string $keysExport)
                {}

                public function getCode(): string
                {
                    return sprintf(
                        <<<'CODE'
                        foreach (%s as $writeOnlyKey) {
                            unset($data[$writeOnlyKey]);
                        }
                        CODE,
                        $this->keysExport,
                    );
                }
            },
        );
    }

    private function addSkipNotProvidedPropertiesMap(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        if ($generatorConfiguration->isImplicitNullAllowed()) {
            return;
        }

        $skipNotProvidedValues = array_map(
            static fn(PropertyInterface $property): string => $property->getName(),
            array_filter(
                $schema->getProperties(),
                static fn(PropertyInterface $property): bool =>
                    !$property->isRequired() && !$property->getDefaultValue(),
            )
        );

        $schema->addProperty(
            (new Property(
                'skipNotProvidedPropertiesMap',
                new PropertyType('array'),
                new JsonSchema(__FILE__, []),
                'Values which might be skipped for serialization if not provided',
            ))
                ->setDefaultValue($skipNotProvidedValues)
                ->setInternal(true),
        );
    }
}
