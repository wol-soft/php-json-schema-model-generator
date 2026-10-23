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
        $json = $propertiesStructure->getJson();

        parent::__construct(
            new Property($propertyName ?? $compositionScope->getClassName(), null, $propertiesStructure),
            $templatePath,
            $extraTemplateValues + [
                'declaredPropertyNames' => RenderHelper::varExportArray(
                    array_keys($json['properties'] ?? []),
                ),
                'pcrePatterns' => RenderHelper::varExportPcrePatterns(
                    array_keys($json['patternProperties'] ?? []),
                ),
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
        // Resolved here — not in the constructor — because post-composition validators register
        // on the schema after this validator's constructor returns. By the time getCheck() runs
        // the base-validator list is final.
        $this->templateValues['compositionValidatorKeys'] = RenderHelper::varExportArray(
            $this->compositionScope->getCompositionValidatorKeys(),
        );

        return parent::getCheck();
    }
}
