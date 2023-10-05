<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use Exception;
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
use PHPModelGenerator\PropertyProcessor\Decorator\TypeHint\ClearTypeHintDecorator;
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;

/**
 * Generates a PHP enum for enums from JSON schemas which are automatically mapped for properties holding the enum
 */
class EnumPostProcessor extends PostProcessor
{
    private $generatedEnums = [];

    /** @var string */
    private $namespace;
    /** @var Render */
    private $renderer;
    /** @var string */
    private $targetDirectory;
    /** @var bool */
    private $skipNonMappedEnums;

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
        bool $skipNonMappedEnums = false
    ) {
        if (PHP_VERSION_ID < 80100) {
            throw new Exception('Enumerations are only allowed since PHP 8.1');
        }

        (new ModelGenerator())->generateModelDirectory($targetDirectory);

        $this->renderer = new Render(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR);
        $this->namespace = trim($namespace, '\\');
        $this->targetDirectory = $targetDirectory;
        $this->skipNonMappedEnums = $skipNonMappedEnums;
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
            sort($values);
            $hash = md5(print_r($values, true));

            if (!isset($this->generatedEnums[$hash])) {
                $this->generatedEnums[$hash] = $this->renderEnum(
                    $generatorConfiguration,
                    $json['$id'] ?? $schema->getClassName() . ucfirst($property->getName()),
                    $values,
                    $json['map'] ?? null
                );
            }

            $fqcn = $this->generatedEnums[$hash];
            $name = substr($fqcn, strrpos($fqcn, "\\") + 1);

            $inputType = $property->getType();

            (new FilterProcessor())->process(
                $property,
                ['filter' => (new EnumFilter())->getToken(), 'fqcn' => $fqcn],
                $generatorConfiguration,
                $schema
            );

            $schema->addUsedClass($fqcn);
            $property->setType($inputType, new PropertyType($name, !$property->isRequired()), true);

            if ($property->getDefaultValue() && in_array($property->getJsonSchema()->getJson()['default'], $values)) {
                $property->setDefaultValue("$name::{$property->getJsonSchema()->getJson()['default']}", true);
            }

            // remove the enum validator as the validation is performed by the PHP enum
            $property->filterValidators(static function (Validator $validator): bool {
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
                throw new SchemaException(sprintf(
                    "Can't apply enum filter to an already transformed value on property %s in file %s",
                    $property->getName(),
                    $property->getJsonSchema()->getFile()
                ));
            }
        }
    }

    public function postProcess(): void
    {
        $this->generatedEnums = [];

        parent::postProcess();
    }

    private function validateEnum(PropertyInterface $property): bool
    {
        $throw = fn (string $message) => throw new SchemaException(
            sprintf(
                $message,
                $property->getName(),
                $property->getJsonSchema()->getFile()
            )
        );

        $json = $property->getJsonSchema()->getJson();

        $types = $this->getArrayTypes($json['enum']);

        // the enum must contain either only string or int values to be represented by a backed enum or provide a value
        // map to resolve the values
        if ($types !== ['string'] && !isset($json['map'])) {
            if ($this->skipNonMappedEnums) {
                return false;
            }

            $throw('Unmapped enum %s in file %s');
        }

        if (isset($json['map'])) {
            if (count(array_uintersect(
                    $json['map'],
                    $json['enum'],
                    fn($a, $b) => $a === $b ? 0 : 1
                )) !== count($json['enum'])
            ) {
                $throw('invalid enum map %s in file %s');
            }
        }

        return true;
    }

    private function getArrayTypes(array $array): array
    {
        return array_unique(array_map(
            static function ($item): string {
                return gettype($item);
            },
            $array
        ));
    }

    private function renderEnum(
        GeneratorConfiguration $generatorConfiguration,
        string $name,
        array $values,
        ?array $map
    ): string {
        $cases = [];

        foreach ($values as $value) {
            $cases[ucfirst($map ? array_search($value, $map) : $value)] = var_export($value, true);
        }

        file_put_contents(
            $this->targetDirectory . DIRECTORY_SEPARATOR . $name . '.php',
            $this->renderer->renderTemplate(
                'Enum.phptpl',
                [
                    'namespace' => $this->namespace,
                    'name' => $name,
                    'cases' => $cases,
                    'backedType' => match ($this->getArrayTypes($values)) {
                        ['string'] => 'string',
                        ['int'] => 'int',
                        default => null,
                    },
                ]
            )
        );

        $fqcn = "$this->namespace\\$name";

        if ($generatorConfiguration->isOutputEnabled()) {
            echo "Rendered enum $fqcn\n";
        }

        return $fqcn;
    }
}
