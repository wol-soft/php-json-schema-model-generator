<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use Exception;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
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
use PHPModelGenerator\Utils\NormalizedName;

/**
 * Generates a PHP enum for enums from JSON schemas which are automatically mapped for properties holding the enum
 */
class EnumPostProcessor extends PostProcessor
{
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
            $enumSignature = ArrayHash::hash($json, ['enum', 'enum-map', 'title', '$id']);
            $enumName = $json['title']
                ?? basename($json['$id'] ?? $schema->getClassName() . ucfirst($property->getName()));

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

            if ($property->getDefaultValue() && in_array($json['default'], $values)) {
                $caseName = $this->getCaseName($json['enum-map'] ?? null, $json['default'], $property->getJsonSchema());
                $property->setDefaultValue("$name::$caseName", true);
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

    /**
     * @throws SchemaException
     */
    private function validateEnum(PropertyInterface $property): bool
    {
        $throw = function (string $message) use ($property): void {
            throw new SchemaException(
                sprintf(
                    $message,
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                )
            );
        };

        $json = $property->getJsonSchema()->getJson();

        $types = $this->getArrayTypes($json['enum']);

        // the enum must contain either only string values or provide a value map to resolve the values
        if ($types !== ['string'] && !isset($json['enum-map'])) {
            if ($this->skipNonMappedEnums) {
                return false;
            }

            $throw('Unmapped enum %s in file %s');
        }

        if (isset($json['enum-map'])) {
            asort($json['enum']);
            if (is_array($json['enum-map'])) {
                asort($json['enum-map']);
            }

            if (!is_array($json['enum-map'])
                || $this->getArrayTypes(array_keys($json['enum-map'])) !== ['string']
                || count(array_uintersect(
                    $json['enum-map'],
                    $json['enum'],
                    fn($a, $b): int => $a === $b ? 0 : 1,
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
            static fn($item): string => gettype($item),
            $array,
        ));
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
            $cases[$this->getCaseName($map, $value, $jsonSchema)] = var_export($value, true);
        }

        $backedType = null;
        switch ($this->getArrayTypes($values)) {
            case ['string']: $backedType = 'string'; break;
            case ['integer']: $backedType = 'int'; break;
        }

        // make sure different enums with an identical name don't overwrite each other
        while (in_array("$this->namespace\\$name", array_column($this->generatedEnums, 'fqcn'))) {
            $name .= '_1';
        }

        $result = file_put_contents(
            $filename = $this->targetDirectory . DIRECTORY_SEPARATOR . $name . '.php',
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

        if ($result === false) {
            // @codeCoverageIgnoreStart
            throw new FileSystemException("Can't write enum $fqcn.");
            // @codeCoverageIgnoreEnd
        }

        require $filename;

        if ($generatorConfiguration->isOutputEnabled()) {
            // @codeCoverageIgnoreStart
            echo "Rendered enum $fqcn\n";
            // @codeCoverageIgnoreEnd
        }

        return $fqcn;
    }

    private function getCaseName(?array $map, mixed $value, JsonSchema $jsonSchema): string
    {
        $caseName = ucfirst(NormalizedName::from($map ? array_search($value, $map, true) : $value, $jsonSchema));

        if (preg_match('/^\d/', $caseName) === 1) {
            $caseName = "_$caseName";
        }

        return $caseName;
    }
}
