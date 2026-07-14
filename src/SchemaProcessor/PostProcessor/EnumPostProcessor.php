<?php

declare(strict_types=1);

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
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\ArrayItemValidator;
use PHPModelGenerator\Model\Validator\EnumValidator;
use PHPModelGenerator\Model\Validator\Factory\Composition\AbstractCompositionValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\AllOfValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Composition\NotValidatorFactory;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\PropertyValidator;
use PHPModelGenerator\ModelGenerator;
use PHPModelGenerator\PropertyProcessor\Filter\FilterProcessor;
use PHPModelGenerator\Utils\ArrayHash;
use PHPModelGenerator\Utils\NormalizedName;
use PHPModelGenerator\Utils\TypeCheck;

/**
 * Generates a PHP enum for enums from JSON schemas which are automatically mapped for properties holding the enum
 */
class EnumPostProcessor extends PostProcessor
{
    private array $generatedEnums = [];

    private readonly string $namespace;
    private readonly Render $renderer;
    private readonly string $targetDirectory;
    private readonly string $enumFilterToken;

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
        private readonly bool $skipNonMappedEnums = false,
    ) {
        (new ModelGenerator())->generateModelDirectory($targetDirectory);

        $this->renderer = new Render(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR);
        $this->namespace = trim($namespace, '\\');
        $this->targetDirectory = $targetDirectory;
        $this->enumFilterToken = (new EnumFilter())->getToken();
    }

    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $generatorConfiguration->addFilter(new EnumFilter());

        foreach ($schema->getProperties() as $property) {
            $this->processProperty($property, $schema, $generatorConfiguration);
        }
    }

    /**
     * Convert enum sub-schemas to generated PHP enum classes on this property, then recurse
     * into its composition branches and array items. After branch recursion the parent
     * property's native type is recomputed so the enum class propagates into the type union.
     *
     * @throws SchemaException
     */
    private function processProperty(
        PropertyInterface $property,
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): void {
        $json = $property->getJsonSchema()->getJson();

        // Branches and array items may share underlying Property instances via $ref deduplication.
        // If the EnumFilter is already attached, the conversion was performed on this property
        // already; skip re-conversion but still recurse so deeper sub-schemas are reached.
        if (isset($json['enum']) && !$this->hasEnumFilterAlreadyApplied($property)) {
            // Filter incompatible values before validation so that e.g. a string-typed enum
            // with a stray integer value is still valid (and the integer is removed with a warning).
            $values = $this->filterValuesByDeclaredType($json, $property);

            if ($this->validateEnum($property, $values)) {
                $this->convertEnumProperty($property, $schema, $generatorConfiguration, $json, $values);
            }
        }

        foreach ($property->getValidators() as $wrapped) {
            $validator = $wrapped->getValidator();

            if ($validator instanceof AbstractComposedPropertyValidator) {
                // `not` branches are excluded by design — a value that fails the inner
                // schema is not itself enum-typed and contributes no useful type hint.
                if ($validator->getCompositionProcessor() === NotValidatorFactory::class) {
                    continue;
                }

                foreach ($validator->getComposedProperties() as $branch) {
                    $this->processProperty($branch, $schema, $generatorConfiguration);
                }

                AbstractCompositionValidatorFactory::transferPropertyType(
                    $property,
                    $validator->getComposedProperties(),
                    $validator->getCompositionProcessor() === AllOfValidatorFactory::class,
                );

                continue;
            }

            if ($validator instanceof ArrayItemValidator) {
                $this->processProperty($validator->getNestedProperty(), $schema, $generatorConfiguration);
            }
        }
    }

    /**
     * Apply the enum-class conversion to a single property whose JSON schema declares an enum.
     *
     * @param array $values Enum values after filterValuesByDeclaredType has removed
     *                      type-incompatible entries.
     */
    private function convertEnumProperty(
        PropertyInterface $property,
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        array $json,
        array $values,
    ): void {
        $this->checkForExistingTransformingFilter($property);

        $enumSignature = ArrayHash::hash($json, ['enum', 'enum-map', 'title', '$id', 'type']);
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
        $name = substr((string) $fqcn, strrpos((string) $fqcn, "\\") + 1);

        $inputType = $property->getType();

        (new FilterProcessor())->process(
            $property,
            ['filter' => (new EnumFilter())->getToken(), 'fqcn' => $fqcn],
            $generatorConfiguration,
            $schema,
            // This synthetic filter converts an already-validated scalar into the generated
            // enum-backed class; conceptually it's part of the enum keyword's contract.
            $property->getJsonSchema()->getPointer() . '/enum',
        );

        $schema->addUsedClass($fqcn);
        $property->setType($inputType, new PropertyType($name, !$property->isRequired()), true);

        if ($property->getDefaultValue()) {
            $caseName = $this->getCaseName($json['enum-map'] ?? null, $json['default'], $property->getJsonSchema());
            $property->setDefaultValue("$name::$caseName", true);
        }

        // TransformingFilterOutputTypePostProcessor runs before user post-processors and
        // therefore never sees the FilterValidator added above. Call the extension directly
        // so that any TypeCheckValidator added by a "type" keyword is wrapped into a
        // PassThroughTypeCheckValidator that accepts already-transformed enum instances.
        TypeCheck::extendTypeCheckValidatorToAllowTransformedValue($property, [$name]);

        // remove the enum validator as the validation is performed by the PHP enum
        $property->filterValidators(
            static fn(Validator $validator): bool => !is_a($validator->getValidator(), EnumValidator::class),
        );

        // if an enum value is provided the transforming filter will add a value pass through. As the filter doesn't
        // know the exact enum type the pass through allows every UnitEnum instance. Consequently add a validator to
        // avoid wrong enums by validating against the generated enum
        $schema->addUsedClass($fqcn);
        $property->addValidator(
            new class ($property, $name) extends PropertyValidator {
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

    /**
     * Returns true when the EnumFilter is already attached to the property (e.g. because the
     * property was reached previously through another composition branch sharing the same
     * underlying $ref-resolved Property instance).
     */
    private function hasEnumFilterAlreadyApplied(PropertyInterface $property): bool
    {
        foreach ($property->getValidators() as $wrapped) {
            $validator = $wrapped->getValidator();

            if (
                $validator instanceof FilterValidator
                && $validator->getFilter()->getToken() === $this->enumFilterToken
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws SchemaException
     */
    private function checkForExistingTransformingFilter(PropertyInterface $property): void
    {
        foreach ($property->getValidators() as $validator) {
            $validator = $validator->getValidator();

            if (
                $validator instanceof FilterValidator
                && $validator->getFilter() instanceof TransformingFilterInterface
            ) {
                throw new SchemaException(sprintf(
                    "Can't apply enum filter to an already transformed value on property %s in file %s",
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ), $property->getJsonSchema());
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
    private function validateEnum(PropertyInterface $property, array $values): bool
    {
        $throw = function (string $message) use ($property): void {
            throw new SchemaException(
                sprintf(
                    $message,
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                ),
                $property->getJsonSchema(),
            );
        };

        $json = $property->getJsonSchema()->getJson();

        $types = $this->getArrayTypes($values);

        // the enum must contain either only string values or provide a value map to resolve the values
        if ($types !== ['string'] && !isset($json['enum-map'])) {
            if ($this->skipNonMappedEnums) {
                return false;
            }

            $throw('Unmapped enum %s in file %s');
        }

        if (isset($json['enum-map'])) {
            $sortedValues = $values;
            asort($sortedValues);
            $enumMap = $json['enum-map'];
            if (is_array($enumMap)) {
                asort($enumMap);
            }

            if (
                !is_array($enumMap)
                || $this->getArrayTypes(array_keys($enumMap)) !== ['string']
                || count(array_uintersect(
                    $enumMap,
                    $sortedValues,
                    fn($a, $b): int => $a === $b ? 0 : 1,
                )) !== count($sortedValues)
            ) {
                $throw('invalid enum map %s in file %s');
            }
        }

        return true;
    }

    /**
     * Return the enum values restricted to those compatible with the declared "type" keyword.
     * Removes values that can never satisfy the type constraint and emits a warning for each
     * removed value so the developer is aware at generation time.
     */
    private function filterValuesByDeclaredType(array $json, PropertyInterface $property): array
    {
        $values = $json['enum'];

        if (!isset($json['type'])) {
            return $values;
        }

        $declaredTypes = is_array($json['type']) ? $json['type'] : [$json['type']];

        // Map JSON Schema type names to PHP gettype() return values
        $phpTypeMap = [
            'string'  => ['string'],
            'integer' => ['integer'],
            'number'  => ['integer', 'double'],
            'boolean' => ['boolean'],
            'null'    => ['NULL'],
            'array'   => ['array'],
            'object'  => ['object'],
        ];

        $allowedPhpTypes = [];
        foreach ($declaredTypes as $declaredType) {
            if (isset($phpTypeMap[$declaredType])) {
                $allowedPhpTypes = array_merge($allowedPhpTypes, $phpTypeMap[$declaredType]);
            }
        }

        if (empty($allowedPhpTypes)) {
            return $values;
        }

        $compatibleValues = [];
        $removedValues    = [];

        foreach ($values as $value) {
            if (in_array(gettype($value), $allowedPhpTypes, true)) {
                $compatibleValues[] = $value;
            } else {
                $removedValues[] = $value;
            }
        }

        if (!empty($removedValues)) {
            $typeLabel   = implode('|', $declaredTypes);
            $removedList = implode(', ', array_map(
                static fn($value): string => var_export($value, true),
                $removedValues,
            ));

            echo sprintf(
                "Warning: enum property '%s' in file %s declares type '%s' but contains incompatible values: %s."
                    . " These values have been removed from the generated enum.\n",
                $property->getName(),
                $property->getJsonSchema()->getFile(),
                $typeLabel,
                $removedList,
            );
        }

        return $compatibleValues;
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
        $name = ucfirst((string) preg_replace('/\W/', '', ucwords($name, '_-. ')));

        foreach ($values as $value) {
            $cases[$this->getCaseName($map, $value, $jsonSchema)] = var_export($value, true);
        }

        $backedType = null;
        switch ($this->getArrayTypes($values)) {
            case ['string']:
                $backedType = 'string';
                break;
            case ['integer']:
                $backedType = 'int';
                break;
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
            return "_$caseName";
        }

        return $caseName;
    }
}
