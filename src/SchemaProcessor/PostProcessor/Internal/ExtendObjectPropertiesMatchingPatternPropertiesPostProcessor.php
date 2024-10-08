<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor\Internal;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PatternPropertiesValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;
use PHPModelGenerator\SchemaProcessor\Hook\SetterBeforeValidationHookInterface;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;

class ExtendObjectPropertiesMatchingPatternPropertiesPostProcessor extends PostProcessor
{
    /**
     * @throws SchemaException
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $this->transferPatternPropertiesFilterToProperty($schema, $generatorConfiguration);

        $schema->addSchemaHook(
            new class ($schema) implements SetterBeforeValidationHookInterface {
                public function __construct(private Schema $schema) {}

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
                    return sprintf('
                            $modelData = array_merge($this->_rawModelDataInput, ["%s" => $value]);
                            $this->executeBaseValidators($modelData);
                        ',
                        $property->getName(),
                    );
                }
            },
        );
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
            static fn(PropertyValidatorInterface $validator): bool => $validator instanceof PatternPropertiesValidator);

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
                if (!preg_match(
                        '/' . addcslashes($patternPropertiesValidator->getPattern(), '/') . '/',
                        $property->getName(),
                    )) {
                    continue;
                }
                if (!isset(
                    $schema->getJsonSchema()->getJson()
                        ['patternProperties']
                        [$patternPropertiesValidator->getPattern()]
                        ['filter'],
                )) {
                    continue;
                }
                if ($propertyHasTransformingFilter) {
                    foreach (
                        $patternPropertiesValidator->getValidationProperty()->getValidators() as $validator
                    ) {
                        if ($validator->getValidator() instanceof FilterValidator &&
                            $validator->getValidator()->getFilter() instanceof TransformingFilterInterface
                        ) {
                            throw new SchemaException(
                                sprintf(
                                    'Applying multiple transforming filters for property %s is not supported in file %s',
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
