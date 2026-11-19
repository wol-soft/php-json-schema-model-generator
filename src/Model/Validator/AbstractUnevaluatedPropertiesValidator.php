<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Shared scaffolding for the two unevaluatedProperties validator flavours: the `false`-form
 * (`NoUnevaluatedPropertiesValidator`) and the `<schema>`-form (`UnevaluatedPropertiesValidator`).
 *
 * Both emit a template that calls `$this->collectUnevaluatedKeys(...)` from
 * `CompositionEvaluationTrait` with the same three argument shapes: the local declared property
 * names, the local PCRE-ready patternProperties, and the composition-validator key list. The
 * last is resolved at render time because post-composition validators register on the schema
 * before `ComposedValueProcessor` finishes; subclasses cannot know the final list at
 * construction time.
 */
abstract class AbstractUnevaluatedPropertiesValidator extends PropertyTemplateValidator
{
    /**
     * @param array<string, mixed> $extraTemplateValues Subclass-specific template values merged
     *                                                  on top of the shared set.
     */
    public function __construct(
        protected readonly Schema $compositionScope,
        JsonSchema $propertiesStructure,
        string $templatePath,
        string $exceptionClass,
        array $exceptionParams,
        array $extraTemplateValues = [],
        ?string $propertyName = null,
    ) {
        parent::__construct(
            new Property($propertyName ?? $compositionScope->getClassName(), null, $propertiesStructure),
            $templatePath,
            $extraTemplateValues + [
                'declaredPropertyNames' => '[]',
                'pcrePatterns' => '[]',
                'hasDependentAllKeysClaim' => false,
                'dependentAllKeysTriggerKeys' => '[]',
                'claimsEveryKey' => false,
            ],
            $exceptionClass,
            $exceptionParams,
        );
    }

    /**
     * @inheritDoc
     */
    public function getCheck(): string
    {
        // Every template value below is resolved here rather than in the constructor because none
        // of them is final while it runs: post-composition validators register on the schema
        // afterwards, and a conjunctive `$ref` merges the referenced schema's names in only once
        // that reference resolves. By the time getCheck() runs the schema is fully assembled.
        $json = $this->property->getJsonSchema()->getJson();

        $this->templateValues['declaredPropertyNames'] = self::buildNameListExpression(
            RenderHelper::varExportArray(
                array_values(array_unique(array_merge(
                    array_map(strval(...), array_keys($json['properties'] ?? [])),
                    $this->compositionScope->getUnconditionallyEvaluatedPropertyNames(),
                ))),
            ),
            array_map(
                RenderHelper::varExportArray(...),
                $this->compositionScope->getDependentEvaluatedNamesByTrigger(),
            ),
        );
        // No trigger-dependent patterns here, unlike the names above: a pattern match is credited
        // only through the class's own `_patternProperties` record, which a dependent subschema's
        // patternProperties never write into this class. See Schema::addDependentEvaluatedProperties().
        $this->templateValues['pcrePatterns'] = RenderHelper::varExportPcrePatterns(
            array_values(array_unique(array_merge(
                array_map(strval(...), array_keys($json['patternProperties'] ?? [])),
                $this->compositionScope->getUnconditionallyEvaluatedPropertyPatterns(),
            ))),
        );

        // A dependent subschema declaring a non-false `additionalProperties` claims every
        // remaining key rather than a set of names, so while its trigger is present nothing can be
        // unevaluated at all. Rendered as a guard in front of the call instead of a name list.
        $allKeysTriggerKeys = $this->compositionScope->getDependentAllKeysTriggerNames();
        $this->templateValues['hasDependentAllKeysClaim'] = $allKeysTriggerKeys !== [];
        $this->templateValues['dependentAllKeysTriggerKeys'] = RenderHelper::varExportArray(
            array_fill_keys($allKeysTriggerKeys, true),
        );

        // A referenced schema with a non-false `additionalProperties` claims every key whatever the instance
        // holds, unlike the dependent claim above, which depends on its trigger being present.
        $this->templateValues['claimsEveryKey'] = $this->compositionScope->claimsEveryKeyThroughReference();

        $this->templateValues['compositionValidatorKeys'] = RenderHelper::varExportArray(
            $this->compositionScope->getCompositionValidatorKeys(),
        );

        return parent::getCheck();
    }

    /**
     * Renders the argument expression for collectUnevaluatedKeys()'s declared-names list: the
     * static list on its own when nothing is trigger-dependent, otherwise that list merged at
     * runtime with the entries whose trigger property is present in the model data.
     *
     * `array_intersect_key` performs the presence test for every trigger at once, keeping the
     * emitted expression flat regardless of how many dependencies the schema declares.
     *
     * Public and static because a branch class's `_getEvaluatedProperties()` renders the same
     * expression against its own copy of the input, and both have to agree on what is credited.
     *
     * @param array<string, string> $dependentListsByTriggerKey Trigger name => PHP array literal
     * @param string                $dataVariable               The array whose keys are the present properties
     */
    public static function buildNameListExpression(
        string $staticList,
        array $dependentListsByTriggerKey,
        string $dataVariable = '$modelData',
    ): string {
        if ($dependentListsByTriggerKey === []) {
            return $staticList;
        }

        $literalEntries = [];
        foreach ($dependentListsByTriggerKey as $triggerKey => $list) {
            $literalEntries[] = var_export((string) $triggerKey, true) . ' => ' . $list;
        }

        return sprintf(
            'array_merge(%s, ...array_values(array_intersect_key([%s], %s)))',
            $staticList,
            implode(', ', $literalEntries),
            $dataVariable,
        );
    }
}
