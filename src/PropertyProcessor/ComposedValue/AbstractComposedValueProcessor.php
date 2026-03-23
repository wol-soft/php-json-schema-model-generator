<?php

declare(strict_types=1);

namespace PHPModelGenerator\PropertyProcessor\ComposedValue;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ClearTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\CompositionTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Property\AbstractValueProcessor;
use PHPModelGenerator\PropertyProcessor\PropertyMetaDataCollection;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\PropertyProcessor\PropertyProcessorFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class AbstractComposedValueProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\ComposedValue
 */
abstract class AbstractComposedValueProcessor extends AbstractValueProcessor
{
    private ?PropertyInterface $mergedProperty = null;

    /**
     * AbstractComposedValueProcessor constructor.
     */
    public function __construct(
        PropertyMetaDataCollection $propertyMetaDataCollection,
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        private readonly bool $rootLevelComposition,
    ) {
        parent::__construct($propertyMetaDataCollection, $schemaProcessor, $schema);
    }

    /**
     * @inheritdoc
     */
    protected function generateValidators(PropertyInterface $property, JsonSchema $propertySchema): void
    {
        $json = $propertySchema->getJson()['propertySchema']->getJson();

        if (
            empty($json[$propertySchema->getJson()['type']]) &&
            $this->schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()
        ) {
            // @codeCoverageIgnoreStart
            echo "Warning: empty composition for {$property->getName()} may lead to unexpected results\n";
            // @codeCoverageIgnoreEnd
        }

        $compositionProperties = $this->getCompositionProperties($property, $propertySchema);

        $resolvedCompositions = 0;
        foreach ($compositionProperties as $compositionProperty) {
            $compositionProperty->onResolve(
                function () use (&$resolvedCompositions, $property, $compositionProperties, $propertySchema): void {
                    if (++$resolvedCompositions === count($compositionProperties)) {
                        $this->transferPropertyType($property, $compositionProperties);

                        $this->mergedProperty = !$this->rootLevelComposition
                            && $this instanceof MergedComposedPropertiesInterface
                                ? $this->schemaProcessor->createMergedProperty(
                                    $this->schema,
                                    $property,
                                    $compositionProperties,
                                    $propertySchema,
                                )
                                : null;
                    }
                },
            );
        }

        $availableAmount = count($compositionProperties);

        $property->addValidator(
            new ComposedPropertyValidator(
                $this->schemaProcessor->getGeneratorConfiguration(),
                $property,
                $compositionProperties,
                static::class,
                [
                    'compositionProperties' => $compositionProperties,
                    'schema' => $this->schema,
                    'generatorConfiguration' => $this->schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($this->schemaProcessor->getGeneratorConfiguration()),
                    'availableAmount' => $availableAmount,
                    'composedValueValidation' => $this->getComposedValueValidation($availableAmount),
                    // if the property is a composed property the resulting value of a validation must be proposed
                    // to be the final value after the validations (e.g. object instantiations may be performed).
                    // Otherwise (eg. a NotProcessor) the value must be proposed before the validation
                    'postPropose' => $this instanceof ComposedPropertiesInterface,
                    'mergedProperty' => &$this->mergedProperty,
                    'onlyForDefinedValues' =>
                        $propertySchema->getJson()['onlyForDefinedValues']
                        && $this instanceof ComposedPropertiesInterface,
                ],
            ),
            100,
        );
    }

    /**
     * Set up composition properties for the given property schema
     *
     * @return CompositionPropertyDecorator[]
     *
     * @throws SchemaException
     */
    protected function getCompositionProperties(PropertyInterface $property, JsonSchema $propertySchema): array
    {
        $propertyFactory = new PropertyFactory(new PropertyProcessorFactory());
        $compositionProperties = [];
        $json = $propertySchema->getJson()['propertySchema']->getJson();

        // clear the base type of the property to keep only the types of the composition.
        // This avoids e.g. "array|int[]" for a property which is known to contain always an integer array
        $property->addTypeHintDecorator(new ClearTypeHintDecorator());

        foreach ($json[$propertySchema->getJson()['type']] as $compositionElement) {
            $compositionSchema = $propertySchema->getJson()['propertySchema']->withJson($compositionElement);

            $compositionProperty = new CompositionPropertyDecorator(
                $property->getName(),
                $compositionSchema,
                $propertyFactory
                    ->create(
                        new PropertyMetaDataCollection([$property->getName() => $property->isRequired()]),
                        $this->schemaProcessor,
                        $this->schema,
                        $property->getName(),
                        $compositionSchema,
                    )
            );

            $compositionProperty->onResolve(function () use ($compositionProperty, $property): void {
                $compositionProperty->filterValidators(
                    static fn(Validator $validator): bool =>
                        !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                        !is_a($validator->getValidator(), ComposedPropertyValidator::class)
                );

                // only create a composed type hint if we aren't a AnyOf or an AllOf processor and the
                // compositionProperty contains no object. This results in objects being composed each separately for a
                // OneOf processor (e.g. string|ObjectA|ObjectB). For a merged composed property the objects are merged
                // together, so it results in string|MergedObject
                if (!($this instanceof MergedComposedPropertiesInterface && $compositionProperty->getNestedSchema())) {
                    $property->addTypeHintDecorator(new CompositionTypeHintDecorator($compositionProperty));
                }
            });

            $compositionProperties[] = $compositionProperty;
        }

        return $compositionProperties;
    }

    /**
     * Check if the provided property can inherit a single type from the composition properties.
     *
     * @param CompositionPropertyDecorator[] $compositionProperties
     */
    private function transferPropertyType(PropertyInterface $property, array $compositionProperties): void
    {
        if ($this instanceof NotProcessor) {
            return;
        }

        // Skip widening when any branch has a nested schema (object): the merged-property
        // mechanism creates a combined class whose name is not among the per-branch type names.
        foreach ($compositionProperties as $p) {
            if ($p->getNestedSchema() !== null) {
                return;
            }
        }

        // Flatten all type names from all branches. Use getNames() to handle branches that
        // already carry a union PropertyType (e.g. from Phase 4 or Phase 5).
        $allNames = array_merge(...array_map(
            static fn(CompositionPropertyDecorator $p): array => $p->getType() ? $p->getType()->getNames() : [],
            $compositionProperties,
        ));

        // A branch with no type contributes nothing but signals that nullable=true is required.
        $hasBranchWithNoType = array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $p): bool => $p->getType() === null,
        ) !== [];

        // An optional branch (property not required in that branch) means the property can be
        // absent at runtime, causing the root getter to return null. This is a structural
        // nullable — independent of the implicit-null configuration setting.
        //
        // For oneOf/anyOf: any optional branch makes the property nullable (the branch that
        // omits the property can match, leaving the value as null).
        //
        // For allOf: all branches must hold simultaneously. If at least one branch marks the
        // property as required, the property is required overall — an optional branch in allOf
        // does not by itself make the property nullable. Only if NO branch requires the property
        // (i.e. the property is optional across all allOf branches) is it structurally nullable.
        $hasBranchWithRequiredProperty = array_filter(
            $compositionProperties,
            static fn(CompositionPropertyDecorator $p): bool => $p->isRequired(),
        ) !== [];
        $hasBranchWithOptionalProperty = $this instanceof AllOfProcessor
            ? !$hasBranchWithRequiredProperty
            : array_filter(
                $compositionProperties,
                static fn(CompositionPropertyDecorator $p): bool => !$p->isRequired(),
            ) !== [];

        // Strip 'null' → nullable flag; PropertyType constructor deduplicates the rest.
        $hasNull = in_array('null', $allNames, true);
        $nonNullNames = array_values(array_filter(
            array_unique($allNames),
            fn(string $t): bool => $t !== 'null',
        ));

        if (!$nonNullNames) {
            return;
        }

        $nullable = ($hasNull || $hasBranchWithNoType || $hasBranchWithOptionalProperty) ? true : null;

        $property->setType(new PropertyType($nonNullNames, $nullable));
    }

    /**
     * @param int $composedElements The amount of elements which are composed together
     */
    abstract protected function getComposedValueValidation(int $composedElements): string;
}
