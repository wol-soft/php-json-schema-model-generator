<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator\Factory\Composition;

use PHPModelGenerator\Exception\Generic\DeniedPropertyException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\BaseProperty;
use PHPModelGenerator\Model\Property\CompositionPropertyDecorator;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\ComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ConditionalPropertyValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\Model\Validator\RequiredPropertyValidator;
use PHPModelGenerator\PropertyProcessor\PropertyFactory;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;
use PHPModelGenerator\Utils\RenderHelper;

class IfValidatorFactory
    extends AbstractCompositionValidatorFactory
    implements ComposedPropertiesValidatorFactoryInterface
{
    /**
     * @throws SchemaException
     */
    public function modify(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): void {
        if (!isset($propertySchema->getJson()[$this->key]) || $this->shouldSkip($property, $propertySchema)) {
            return;
        }

        $json = $propertySchema->getJson();

        if (!isset($json['then']) && !isset($json['else'])) {
            throw new SchemaException(
                sprintf(
                    'Incomplete conditional composition for property %s in file %s',
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ),
            );
        }

        $json = $this->resolveBooleanBranches($json, $property, $schemaProcessor);

        if ($json === null) {
            return;
        }

        $propertySchema = $this->inheritPropertyType($propertySchema->withJson($json));
        $json = $propertySchema->getJson();

        $propertyFactory = new PropertyFactory();

        $onlyForDefinedValues = !($property instanceof BaseProperty)
            && (!$property->isRequired()
                && $schemaProcessor->getGeneratorConfiguration()->isImplicitNullAllowed());

        /** @var array<string, CompositionPropertyDecorator|null> $properties */
        $properties = [];

        foreach (['if', 'then', 'else'] as $keyword) {
            if (!isset($json[$keyword])) {
                $properties[$keyword] = null;
                continue;
            }

            if ($json[$keyword] === false) {
                $properties[$keyword] = $this->createAlwaysFailingBranchProperty(
                    $schemaProcessor,
                    $schema,
                    $property,
                    $propertySchema,
                );
                continue;
            }

            $compositionSchema = $propertySchema->navigate($keyword);

            $compositionProperty = new CompositionPropertyDecorator(
                $property->getName(),
                $compositionSchema,
                $propertyFactory->create(
                    $schemaProcessor,
                    $schema,
                    $property->getName(),
                    $compositionSchema,
                    $property->isRequired(),
                ),
            );

            $compositionProperty->onResolve(static function () use ($compositionProperty): void {
                $compositionProperty->filterValidators(
                    static fn(Validator $validator): bool =>
                        !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                        !is_a($validator->getValidator(), ComposedPropertyValidator::class),
                );
            });

            $properties[$keyword] = $compositionProperty;
        }

        $property->addValidator(
            new ConditionalPropertyValidator(
                $schemaProcessor->getGeneratorConfiguration(),
                $property,
                array_values(array_filter($properties)),
                array_values(array_filter([$properties['then'], $properties['else']])),
                [
                    'ifProperty' => $properties['if'],
                    'thenProperty' => $properties['then'],
                    'elseProperty' => $properties['else'],
                    'schema' => $schema,
                    'generatorConfiguration' => $schemaProcessor->getGeneratorConfiguration(),
                    'viewHelper' => new RenderHelper($schemaProcessor->getGeneratorConfiguration()),
                    'onlyForDefinedValues' => $onlyForDefinedValues,
                ],
            ),
            100,
        );
    }

    /**
     * Create a composition branch that always fails, used for boolean `false` if/then/else branches.
     *
     * Unlike the allOf/anyOf/oneOf false-branch (which uses array_key_exists to guard absent
     * optional properties), here the outer ConditionalComposedItem template's onlyForDefinedValues
     * guard already prevents the entire conditional from running for absent properties. So the
     * branch itself just needs to always throw regardless of the value.
     */
    private function createAlwaysFailingBranchProperty(
        SchemaProcessor $schemaProcessor,
        Schema $schema,
        PropertyInterface $property,
        JsonSchema $propertySchema,
    ): CompositionPropertyDecorator {
        $propertyFactory = new PropertyFactory();
        $branchSchema = $propertySchema->withJson([]);

        $branchProperty = new CompositionPropertyDecorator(
            $property->getName(),
            $branchSchema,
            $propertyFactory->create(
                $schemaProcessor,
                $schema,
                $property->getName(),
                $branchSchema,
                $property->isRequired(),
            ),
        );

        $branchProperty->onResolve(function () use ($branchProperty): void {
            $branchProperty->filterValidators(
                static fn(Validator $validator): bool =>
                    !is_a($validator->getValidator(), RequiredPropertyValidator::class) &&
                    !is_a($validator->getValidator(), ComposedPropertyValidator::class),
            );
            $branchProperty->addValidator(
                new PropertyValidator(
                    $branchProperty,
                    'true',
                    DeniedPropertyException::class,
                ),
            );
        });

        return $branchProperty;
    }

    /**
     * Resolve boolean `if`/`then`/`else` branches into concrete schema arrays or return-null signals.
     *
     * Returns null when the entire if/then/else imposes no constraint and modify() should return
     * early. Returns the (possibly rewritten) $json array otherwise. Always-false branches are left
     * as boolean false values in the returned array so the foreach loop in modify() can handle them.
     *
     * @throws SchemaException
     */
    private function resolveBooleanBranches(
        array $json,
        PropertyInterface $property,
        SchemaProcessor $schemaProcessor,
    ): ?array {
        if (is_bool($json['if'])) {
            if ($json['if'] === false) {
                if (!isset($json['else'])) {
                    if (isset($json['then']) && $schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()) {
                        // @codeCoverageIgnoreStart
                        echo "Warning: if: false for property '{$property->getName()}'"
                            . " — then branch will never apply (condition never matches); no constraint generated.\n";
                        // @codeCoverageIgnoreEnd
                    }
                    return null;
                }

                if ($json['else'] === true) {
                    return null;
                }

                if ($json['else'] === false) {
                    $this->warnIfAlwaysFalse(
                        $schemaProcessor,
                        $property,
                        'if: false with else: false means the composition is always unsatisfiable',
                    );
                    // Rewrite as if: {} (always passes), then: false (always fails).
                    // The false then-branch is handled in the foreach loop below.
                    $json['if'] = [];
                    $json['then'] = false;
                    unset($json['else']);
                    return $json;
                }

                // Rewrite if: false, else: X as if: {}, then: X.
                // An empty if schema always passes so then always applies.
                // The ConditionalException will say "Condition: Valid" which is accurate
                // for if: {} but won't mention "else"; the message still correctly names
                // the failing branch constraint.
                $json['if'] = [];
                $json['then'] = $json['else'];
                unset($json['else']);

                return $json;
            }

            if (!isset($json['then'])) {
                if (isset($json['else']) && $schemaProcessor->getGeneratorConfiguration()->isOutputEnabled()) {
                    // @codeCoverageIgnoreStart
                    echo "Warning: if: true for property '{$property->getName()}'"
                        . " — else branch will never apply (condition always matches); no constraint generated.\n";
                    // @codeCoverageIgnoreEnd
                }
                return null;
            }

            if ($json['then'] === true) {
                return null;
            }

            if ($json['then'] === false) {
                $this->warnIfAlwaysFalse(
                    $schemaProcessor,
                    $property,
                    'if: true with then: false means the composition is always unsatisfiable',
                );
            }

            // Rewrite if: true, then: Y as if: {}, then: Y (removing else — it never applies).
            // If then is false the false-branch is handled in the foreach loop below.
            $json['if'] = [];
            unset($json['else']);

            return $json;
        }

        if (isset($json['then']) && is_bool($json['then'])) {
            if ($json['then'] === false) {
                throw new SchemaException(
                    sprintf(
                        'then: false is unsatisfiable for property %s in file %s',
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    ),
                );
            }

            unset($json['then']);
        }

        if (isset($json['else']) && is_bool($json['else'])) {
            if ($json['else'] === false) {
                throw new SchemaException(
                    sprintf(
                        'else: false is unsatisfiable for property %s in file %s',
                        $property->getName(),
                        $property->getJsonSchema()->getFile(),
                    ),
                );
            }

            unset($json['else']);
        }

        if (!isset($json['then']) && !isset($json['else'])) {
            return null;
        }

        return $json;
    }
}
