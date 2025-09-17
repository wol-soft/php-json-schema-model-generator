<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use Exception;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\Generic\InvalidTypeException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Filter\TransformingFilterInterface;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Property\PropertyType;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Model\Validator;
use PHPModelGenerator\Model\Validator\EnumValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;
use PHPModelGenerator\Utils\ArrayHash;

/**
 * Generates a PHP enum for enums from JSON schemas which are automatically mapped for properties holding the enum
 */
class EnumPostProcessor extends PostProcessor
{
    use EnumTrait;

    private array $generatedEnums = [];

    private string $namespace;
    private Render $renderer;
    private string $targetDirectory;

    /**
     * @param string $targetDirectory  The directory where to put the generated PHP enums
     * @param string $namespace        The namespace for the generated enums
     * @param bool $skipNonMappedEnums By default, enums which not contain only strings and don't provide a mapping for
     *                                 the enum will throw an exception. If set to true, those enums will be skipped
     *
     * @throws Exception
     */
    public function __construct(
        string $targetDirectory,
        string $namespace,
        private bool $skipNonMappedEnums = false,
    ) {
        if (PHP_VERSION_ID < 80100) {
            // @codeCoverageIgnoreStart
            throw new Exception('Enumerations are only allowed since PHP 8.1');
            // @codeCoverageIgnoreEnd
        }

        (new ModelGenerator())->generateModelDirectory($targetDirectory);

        $this->renderer = new Render(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR);
        $this->namespace = trim($namespace, '\\');
        $this->targetDirectory = $targetDirectory;
    }

    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $generatorConfiguration->addFilter(new EnumFilter());

        foreach ($schema->getProperties() as $property) {
            $json = $property->getJsonSchema()->getJson();

            if (!isset($json['enum']) || !$this->validateEnum($property)) {
                continue;
            }

            $this->checkForExistingTransformingFilter($property);

            $values = $json['enum'];
            $enumSignature = ArrayHash::hash($json, ['enum', 'enum-map', '$id']);
            $enumName = $json['$id'] ?? $schema->getClassName() . ucfirst($property->getName());

            if (!isset($this->generatedEnums[$enumSignature])) {
                $this->generatedEnums[$enumSignature] = [
                    'name' => $enumName,
                    'fqcn' => $this->renderEnum(
                        $generatorConfiguration,
                        $schema->getJsonSchema(),
                        $enumName,
                        $values,
                        $json['enum-map'] ?? null,
                    ),
                ];
            } else {
                if ($generatorConfiguration->isOutputEnabled()) {
                    // @codeCoverageIgnoreStart
                    echo "Duplicated signature $enumSignature for enum $enumName." .
                        " Redirecting to {$this->generatedEnums[$enumSignature]['name']}\n";
                    // @codeCoverageIgnoreEnd
                }
            }

            $fqcn = $this->generatedEnums[$enumSignature]['fqcn'];
            $name = substr($fqcn, strrpos($fqcn, "\\") + 1);

            $inputType = $property->getType();

            (new FilterProcessor())->process(
                $property,
                ['filter' => (new EnumFilter())->getToken(), 'fqcn' => $fqcn],
                $generatorConfiguration,
                $schema,
            );

            $schema->addUsedClass($fqcn);
            $property->setType($inputType, new PropertyType($name, !$property->isRequired()), true);

            if ($property->getDefaultValue() && in_array($property->getJsonSchema()->getJson()['default'], $values)) {
                $property->setDefaultValue("$name::{$property->getJsonSchema()->getJson()['default']}", true);
            }

            // remove the enum validator as the validation is performed by the PHP enum
            $property->filterValidators(
                static fn(Validator $validator): bool => !is_a($validator->getValidator(), EnumValidator::class),
            );

            // if an enum value is provided the transforming filter will add a value pass through. As the filter doesn't
            // know the exact enum type the pass through allows every UnitEnum instance. Consequently add a validator to
            // avoid wrong enums by validating against the generated enum
            $property->addValidator(
                new class ($property, $enumName) extends PropertyValidator {
                    public function __construct(PropertyInterface $property, string $enumName)
                    {
                        parent::__construct(
                            $property,
                            sprintf('$value instanceof UnitEnum && !($value instanceof %s)', $enumName),
                            InvalidTypeException::class,
                            [$enumName],
                        );
                    }
                },
                0,
            );
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
                throw new SchemaException(sprintf(
                    "Can't apply enum filter to an already transformed value on property %s in file %s",
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ));
            }
        }
    }

    public function postProcess(): void
    {
        $this->generatedEnums = [];

        parent::postProcess();
    }

    private function renderEnum(
        GeneratorConfiguration $generatorConfiguration,
        JsonSchema $jsonSchema,
        string $name,
        array $values,
        ?array $map,
    ): string {
        $cases = [];

        foreach ($values as $value) {
            $cases[$this->getCaseName($value, $map, $jsonSchema)] = var_export($value, true);
        }

        $backedType = match ($this->getArrayTypes($values)) {
            ['string']  => 'string',
            ['integer'] => 'int',
            default     => null,
        };

        // make sure different enums with an identical name don't overwrite each other
        while (in_array("$this->namespace\\$name", array_column($this->generatedEnums, 'fqcn'))) {
            $name .= '_1';
        }

        file_put_contents(
            $this->targetDirectory . DIRECTORY_SEPARATOR . $name . '.php',
            $this->renderer->renderTemplate(
                'Enum.phptpl',
                [
                    'namespace' => $this->namespace,
                    'name' => $name,
                    'cases' => $cases,
                    'backedType' => $backedType,
                ],
            )
        );

        $fqcn = "$this->namespace\\$name";

        if ($generatorConfiguration->isOutputEnabled()) {
            // @codeCoverageIgnoreStart
            echo "Rendered enum $fqcn\n";
            // @codeCoverageIgnoreEnd
        }

        return $fqcn;
    }
}
