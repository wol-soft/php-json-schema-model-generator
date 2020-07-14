<?php

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Class NotProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
class NotProcessor extends AbstractComposedValueProcessor
{
    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson()['propertySchema']->getJson();

        // as the not composition only takes one schema nest it one level deeper to use the ComposedValueProcessor
        $json['not'] = [$json['not']];

        // strict type checks for not constraint to avoid issues with null
        $property->setRequired(true);
        parent::generateValidators(
            $property,
            $propertySchema->withJson(
                array_merge(
                    $propertySchema->getJson(),
                    ['propertySchema' => $propertySchema->getJson()['propertySchema']->withJson($json)]
                )
            )
        );
    }

    /**
     * @inheritdoc
     */
    protected function getComposedValueValidation(int $composedElements): string
    {
        return '$succeededCompositionElements === 0';
    }
}
