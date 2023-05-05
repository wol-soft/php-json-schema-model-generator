<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\EnumValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;

class EnumPostProcessor extends PostProcessor
{
    /** @var string */
    private $namespace;
    /** @var Render */
    private $renderer;
    /** @var string */
    private $targetDirectory;

    private $hasAddedFilter = false;

    public function __construct(string $targetDirectory, string $namespace)
    {
        if (PHP_VERSION_ID < 80100) {
            throw new \Exception('Enumerations are only allowed since PHP 8.1');
        }

        (new ModelGenerator())->generateModelDirectory($targetDirectory);

        $this->renderer = new Render(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR);
        $this->namespace = trim($namespace, '\\');
        $this->targetDirectory = $targetDirectory;
    }

    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        if (!$this->hasAddedFilter) {
            $generatorConfiguration->addFilter(new EnumFilter());
            $this->hasAddedFilter = true;
        }

        foreach ($schema->getProperties() as $property) {
            if (!isset($property->getJsonSchema()->getJson()['enum'])) {
                continue;
            }

            $this->checkForExistingTransformingFilter($property);

            $values = $property->getJsonSchema()->getJson()['enum'];
            sort($values);

            $name = $schema->getClassName() . ucfirst($property->getName());
            file_put_contents(
                $this->targetDirectory . DIRECTORY_SEPARATOR . $name . '.php',
                $this->renderer->renderTemplate(
                    'Enum.phptpl',
                    [
                        'namespace' => $this->namespace,
                        'name' => $name,
                        'backedType' => 'string',
                        'cases' => array_combine(
                            $values,
                            array_map(
                                static function ($value): string {
                                    return var_export($value, true);
                                },
                                $values
                            )
                        ),
                    ]
                )
            );

            $fqcn = "$this->namespace\\$name";
            if ($generatorConfiguration->isOutputEnabled()) {
                echo "Rendered enum $fqcn\n";
            }

            $inputType = $property->getType();

            (new FilterProcessor())->process(
                $property,
                ['filter' => 'php_model_generator_enum', 'fqcn' => $fqcn],
                $generatorConfiguration,
                $schema
            );

            $schema->addUsedClass($fqcn);
            $property->setType($inputType, new PropertyType($name, !$property->isRequired()));

            if ($property->getDefaultValue() && in_array($property->getJsonSchema()->getJson()['default'], $values)) {
                $property->setDefaultValue("$name::{$property->getJsonSchema()->getJson()['default']}", true);
            }

            // remove the enum validator as the validation is performed by the PHP enum
            $property->filterValidators(static function (Validator $validator): bool {
                echo "filter validator: " . $validator->getValidator()::class . PHP_EOL;
                return !is_a($validator->getValidator(), EnumValidator::class);
            });
        }
    }

    /**
     * @throws SchemaException
     */
    private function checkForExistingTransformingFilter(PropertyInterface $property): void
    {
        foreach ($property->getValidators() as $validator) {
            $validator = $validator->getValidator();

            if ($validator instanceof FilterValidator
                && $validator->getFilter() instanceof TransformingFilterInterface
            ) {
                throw new SchemaException("Can't apply enum filter to an already transformed value");
            }
        }
    }

    public function postProcess(): void
    {
        $this->hasAddedFilter = false;

        parent::postProcess();
    }
}
