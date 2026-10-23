<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\AbstractComposedPropertyValidator;
use PHPModelGenerator\Model\Validator\DependencyValidatorInterface;
use PHPModelGenerator\Model\Validator\ExtractedMethodValidator;
use PHPModelGenerator\Model\Validator\FilterValidator;
use PHPModelGenerator\Model\Validator\ObjectUpdateDependency;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\SchemaProcessor\PostProcessor\RenderedMethod;

/**
 * Class RenderHelper
 *
 * @package PHPModelGenerator\Utils
 */
class RenderHelper
{
    public function __construct(protected GeneratorConfiguration $generatorConfiguration)
    {}

    public function ucfirst(string $value): string
    {
        return ucfirst($value);
    }

    public function isNull(mixed $value): bool
    {
        return $value === null;
    }

    public function exportValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: var_export($value, true);
    }

    public function getSimpleClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    public function joinClassNames(array $fqcns): string
    {
        return join(', ', array_map([$this, 'getSimpleClassName'], $fqcns));
    }

    /**
     * Resolve all associated decorators of a property
     */
    public function resolvePropertyDecorator(
        PropertyInterface $property,
        bool $nestedProperty = false,
        int $indentLevel = 0,
    ): string {
        if (!$property->getDecorators()) {
            return '';
        }

        return self::indent(
            '$value = ' . $property->resolveDecorator('$value', $nestedProperty) . ';',
            $indentLevel,
        );
    }

    /**
     * Indent every line of $code but the first by $indentLevel additional levels of 4 spaces. The first line is
     * left untouched as it directly follows the calling context's own leading whitespace, which already puts it at
     * the correct column - only the continuation lines of a multi-line snippet need to be shifted to match.
     */
    public static function indent(string $code, int $indentLevel): string
    {
        if ($indentLevel === 0 || $code === '') {
            return $code;
        }

        return str_replace("\n", "\n" . str_repeat('    ', $indentLevel), $code);
    }

    /**
     * Wrap $check in parentheses for use as an if-condition. PSR-12 requires a multi-line condition's first
     * expression to start on the line after the opening parenthesis, with the closing parenthesis back at the
     * condition's own column - a single-line check keeps the compact "(check)" form instead, since that rule only
     * applies once a condition already spans multiple lines. $indentLevel is the level the "if" keyword itself sits
     * at (0 if it starts its own line/string), used to align the closing parenthesis under it and the check one
     * level deeper.
     */
    public static function formatCondition(string $check, int $indentLevel): string
    {
        if (!str_contains($check, "\n")) {
            return "($check)";
        }

        return "(\n" . str_repeat('    ', $indentLevel + 1) . self::indent($check, $indentLevel + 1)
            . "\n" . str_repeat('    ', $indentLevel) . ')';
    }

    /**
     * Generate code to handle a validation error
     */
    public function validationError(PropertyValidatorInterface $validator): string
    {
        $exceptionConstructor = sprintf(
            'new \%s($value ?? null, ...%s)',
            $validator->getExceptionClass(),
            preg_replace('/\'&(\$\w+)\'/i', '$1', self::varExportArray($validator->getExceptionParams())),
        );

        if ($this->generatorConfiguration->collectErrors()) {
            return "\$this->_errorRegistry->addError($exceptionConstructor);";
        }

        return "throw $exceptionConstructor;";
    }

    /**
     * check if the property may contain/accept null
     * - if the property is required the property may never contain null (if it's a null property null is already
     *   contained in the property type hints)
     * - if the output type is requested null may be contained (if the property was not set)
     *   if implicitNull is enabled null may be set for the property
     * - except the property contains a default value and implicit null is disabled. in this case null is not
     *   possible
     */
    public function isPropertyNullable(PropertyInterface $property, bool $outputType = false): bool
    {
        return !$property->isRequired()
            && ($outputType || $this->generatorConfiguration->isImplicitNullAllowed())
            && !($property->getDefaultValue() !== null && !$this->generatorConfiguration->isImplicitNullAllowed());
    }

    public function getType(PropertyInterface $property, bool $outputType = false, bool $forceNullable = false): string
    {
        $type = $property->getType($outputType);

        if (!$type) {
            return 'mixed';
        }

        $nullable = ($type->isNullable() ?? $this->isPropertyNullable($property, $outputType)) || $forceNullable;
        $names = $type->getNames();

        if ($type->isUnion()) {
            if ($nullable) {
                $names[] = 'null';
            }
            return implode(' | ', array_unique($names));
        }

        // Single type — preserve ?Type shorthand
        return ($nullable ? '?' : '') . $names[0];
    }

    public function getTypeHintAnnotation(
        PropertyInterface $property,
        bool $outputType = false,
        bool $forceNullable = false,
    ): string {
        $typeHint = $property->getTypeHint($outputType);
        $hasDefinedNullability = ($type = $property->getType($outputType)) && $type->isNullable() !== null;

        $nullable = ($hasDefinedNullability && $type->isNullable())
            || (!$hasDefinedNullability && $this->isPropertyNullable($property, $outputType))
            || $forceNullable;

        $parts = array_unique(explode('|', $typeHint));

        if ($nullable && !in_array('mixed', $parts, true) && !in_array('null', $parts, true)) {
            $parts[] = 'null';
        }

        return implode('|', $parts);
    }

    public function renderValidator(PropertyValidatorInterface $validator, Schema $schema, int $indentLevel = 0): string
    {
        // scoping of the validator might be required as validators from a composition might be transferred to a
        // different schema
        if ($validator instanceof PropertyTemplateValidator) {
            $validator->setScope($schema);
        }

        if ($validator instanceof FilterValidator && !$validator->isExecuted()) {
            return '';
        }

        if (!$validator instanceof ExtractedMethodValidator) {
            $setUp = $validator->getValidatorSetUp();

            $code = ($setUp !== '' ? "{$setUp}\n" : '')
                . 'if ' . self::formatCondition($validator->getCheck(), 0)
                . " {\n    {$this->validationError($validator)}\n}";

            return self::indent($code, $indentLevel);
        }

        if (!$schema->hasMethod($validator->getExtractedMethodName())) {
            $schema->addMethod($validator->getExtractedMethodName(), $validator->getMethod());
        }

        return "\$this->{$validator->getExtractedMethodName()}(\$value, \$modelData);";
    }

    /**
     * Every rendered method's own getCode() output is self-contained, built relative to its own first line sitting
     * at column 0 - the same convention every other multi-line snippet in this class follows. Concatenating several
     * methods together means only the very first one could ever inherit a "free" ambient column from the calling
     * template (and only if it happened to be first), so every method here - including the first - is explicitly
     * indented one full level (matching the class body) instead of relying on template-side ambient indentation.
     */
    public function renderMethods(Schema $schema): string
    {
        $renderedMethods = '';

        // don't change to a foreach loop as the render process of a method might add additional methods
        for ($i = 0; $i < count($schema->getMethods()); $i++) {
            $code = $schema->getMethods()[array_keys($schema->getMethods())[$i]]->getCode();

            $renderedMethods .= '    ' . self::indent($code, 1) . "\n\n";
        }

        return $renderedMethods;
    }

    /**
     * Wraps the code of an update (setter, populate, additional property accessors) which may change the model state
     * before the update is accepted (compositions record their results and apply branch defaults, base validators
     * store additional and pattern properties, custom setter hooks may change anything): a rejected update restores
     * the complete model state. The snapshot and restore methods are added to the schema on first use, a class
     * without any such update doesn't contain them.
     *
     * Restoring only the state known to be changed by specific validators (one rollback mechanism per validator)
     * was rejected: each validator needs its own bookkeeping, state changed by custom hooks isn't covered, and a
     * validator which succeeds before a later validator rejects the update isn't restored at all.
     */
    public function wrapInModelStateRollback(Schema $schema, string $code): string
    {
        $methodTemplates = ['_backupModelState' => 'BackupModelState', '_restoreModelState' => 'RestoreModelState'];

        foreach ($methodTemplates as $method => $template) {
            if (!$schema->hasMethod($method)) {
                $schema->addMethod(
                    $method,
                    new RenderedMethod(
                        $schema,
                        $this->generatorConfiguration,
                        join(DIRECTORY_SEPARATOR, ['ModelState', "$template.phptpl"]),
                    ),
                );
            }
        }

        return join("\n", [
            '$modelStateBackup = $this->_backupModelState();',
            '',
            'try {',
            '    ' . self::indent($code, 1),
            '} catch (\\Throwable $exception) {',
            '    $this->_restoreModelState($modelStateBackup);',
            '',
            '    throw $exception;',
            '}',
        ]);
    }

    /**
     * The validation of a setter. Only the validation of the object (object-level validators, compositions,
     * dependencies of other properties) and before validation hook code change the model state before the update is
     * accepted - the validators of the property itself never write the model state -, so only a setter executing
     * such code is wrapped into the model state rollback.
     *
     * The setter of a key which is already stored only executes the validation which depends on the value of the key:
     * changing the value of a present key can't change the result of e.g. maxProperties. Snapshotting and validating
     * the object on every call was rejected: it made the setters of a model with such a constraint (e.g. every
     * composition branch with denied additional properties) expensive although most updates change present values.
     */
    public function renderSetterValidation(
        Schema $schema,
        PropertyInterface $property,
        SchemaHookResolver $schemaHookResolver,
    ): string {
        $hookCode = $schemaHookResolver->resolveSetterBeforeValidationHook($property);
        $addedKeyCode = $this->renderSetterUpdateValidation($schema, $property, $hookCode, true);
        $presentKeyCode = $this->renderSetterUpdateValidation($schema, $property, $hookCode, false);

        // a path which doesn't validate a composition doesn't stage filtered values of an active branch
        $initialisation = $this->stagesBranchFilteredValues($schema, $property)
            ? "\$preparedBranchFilteredValues = null;\n\n"
            : '';

        if ($addedKeyCode === $presentKeyCode) {
            return $initialisation . $addedKeyCode;
        }

        return $initialisation . join("\n", [
            sprintf('if (!array_key_exists(%s, $this->_rawModelDataInput)) {', var_export($property->getName(), true)),
            '    ' . self::indent($addedKeyCode, 1),
            '} else {',
            '    ' . self::indent($presentKeyCode, 1),
            '}',
        ]);
    }

    /**
     * Adopts the filtered values the active branches of the compositions staged during the setter once the update
     * succeeded (see renderSetterValidation()).
     */
    public function renderSetterBranchFilteredValueApplication(Schema $schema, PropertyInterface $property): string
    {
        return $this->stagesBranchFilteredValues($schema, $property)
            ? '$this->_applyBranchFilteredValues($preparedBranchFilteredValues);'
            : '';
    }

    /**
     * Whether the setter validates a composition which hands over filtered values of its active branch
     */
    private function stagesBranchFilteredValues(Schema $schema, PropertyInterface $property): bool
    {
        // adding the key validates every composition changing the value of a present key validates
        return $schema->hasMethod('_prepareBranchFilteredValues')
            && $this->getSetterObjectValidation($schema, $property, true)[1] !== '';
    }

    /**
     * Whether the setter of the property validates more than the property itself
     */
    public function hasSetterObjectValidation(Schema $schema, PropertyInterface $property): bool
    {
        // adding the key validates everything changing the value of a present key validates
        return array_filter($this->getSetterObjectValidation($schema, $property, true)) !== [];
    }

    private function renderSetterUpdateValidation(
        Schema $schema,
        PropertyInterface $property,
        string $hookCode,
        bool $keyAdded,
    ): string {
        [$objectCode, $compositionCode, $dependencyCode] = $this->getSetterObjectValidation(
            $schema,
            $property,
            $keyAdded,
        );

        // the order of the constructor: object validators, compositions, properties
        $code = join("\n\n", array_filter([
            $objectCode,
            $compositionCode,
            $hookCode,
            $dependencyCode,
            sprintf('$value = $this->_validate%s($value, $modelData);', $this->ucfirst($property->getAttribute())),
            $this->generatorConfiguration->collectErrors()
                ? "if (\$this->_errorRegistry->getErrors()) {\n    throw \$this->_errorRegistry;\n}"
                : '',
        ]));

        return $objectCode . $compositionCode . $hookCode . $dependencyCode === ''
            ? $code
            : $this->wrapInModelStateRollback($schema, $code);
    }

    /**
     * The validation of the object an update of the property requires, like the constructor validates the data:
     * - the object-level validators (e.g. maxProperties). Only the updated key is passed: the validators which count
     *   the properties include the stored data themselves, the others only validate the provided keys.
     * - the compositions of the schema, which evaluate their branches again as far as the update may change them
     * - the dependencies of the other properties (a schema dependency validates the whole object), the dependencies
     *   of the property itself are validated with the property
     *
     * Executing the object validators in every setter was rejected: the setter of a declared property can't violate
     * e.g. additionalProperties, every setter of such a common model would validate and snapshot the model state for
     * nothing.
     *
     * @return string[] The code for the object-level validators, the compositions and the dependencies
     */
    private function getSetterObjectValidation(Schema $schema, PropertyInterface $property, bool $keyAdded): array
    {
        $key = $property->getName();

        $objectCode = ObjectUpdateDependency::ofValidators($schema->getObjectValidators(), $key)
            ->requiresValidation($keyAdded)
            // a copy: the validators must not change the value of the setter
            ? "\$objectModelData = \$modelData;\n\$this->_executeObjectValidators(\$objectModelData);"
            : '';

        $compositionCalls = [];
        foreach ($schema->getCompositionValidatorKeys() as $validatorIndex) {
            /** @var AbstractComposedPropertyValidator $compositionValidator */
            $compositionValidator = $schema->getBaseValidators()[$validatorIndex];

            if ($compositionValidator->getObjectUpdateDependency($key)->requiresValidation($keyAdded)) {
                $compositionCalls[] = sprintf('$this->_validateComposition_%s($modelData);', $validatorIndex);
            }
        }

        // The compositions stage the filtered values of their active branches: the setter starts with an empty
        // staging and prepares the values before anything is assigned (a rejected dependent property rejects the
        // update), they are applied once the update succeeded (see renderSetterBranchFilteredValueApplication()).
        if ($compositionCalls !== [] && $schema->hasMethod('_prepareBranchFilteredValues')) {
            array_unshift($compositionCalls, '$this->_stagedBranchFilteredValues = [];');
            $compositionCalls[] = sprintf(
                '$preparedBranchFilteredValues = $this->_prepareBranchFilteredValues(%s, %s);',
                self::varExportArray([$property->getAttribute(true)]),
                'array_merge($this->_rawModelDataInput, $modelData)',
            );
        }

        $dependency = ObjectUpdateDependency::None;
        foreach (self::getDependencyValidators($schema) as $propertyName => $validators) {
            if ($propertyName !== $key) {
                $dependency = $dependency->strongest(ObjectUpdateDependency::ofValidators($validators, $key));
            }
        }

        $dependencyCode = '';
        if ($dependency->requiresValidation($keyAdded)) {
            $this->addDependencyValidationMethod($schema);

            $dependencyCode = sprintf(
                '$this->_executeDependencyValidators(array_merge($this->_rawModelDataInput, $modelData), [%s]);',
                var_export($key, true),
            );
        }

        return [$objectCode, join("\n", $compositionCalls), $dependencyCode];
    }

    /**
     * Whether populate() must validate the dependencies of the properties it doesn't update (adds the method
     * executing them if so). populate() may update any key: each property and any undeclared key are checked.
     */
    public function addPopulateDependencyValidation(Schema $schema): bool
    {
        foreach (self::getDependencyValidators($schema) as $validators) {
            $dependency = ObjectUpdateDependency::ofValidators($validators, null);

            foreach ($schema->getProperties() as $property) {
                if (!$property->isInternal()) {
                    $dependency = $dependency->strongest(
                        ObjectUpdateDependency::ofValidators($validators, $property->getName()),
                    );
                }
            }

            if ($dependency->requiresValidation(true)) {
                $this->addDependencyValidationMethod($schema);

                return true;
            }
        }

        return false;
    }

    /**
     * The dependency validators of the properties of the schema
     *
     * @return array<string, PropertyValidatorInterface[]> The dependency validators keyed by the property name
     */
    public static function getDependencyValidators(Schema $schema): array
    {
        $dependencyValidators = [];

        foreach ($schema->getProperties() as $property) {
            if ($property->isInternal()) {
                continue;
            }

            foreach ($property->getOrderedValidators() as $validator) {
                if ($validator instanceof DependencyValidatorInterface) {
                    $dependencyValidators[$property->getName()][] = $validator;
                }
            }
        }

        return $dependencyValidators;
    }

    /**
     * Adds _executeDependencyValidators() to the schema on first use. It validates the dependency validators of the
     * properties outside the validation of the property, e.g. after another property was updated or removed.
     */
    public function addDependencyValidationMethod(Schema $schema): void
    {
        if ($schema->hasMethod('_executeDependencyValidators')) {
            return;
        }

        $schema->addMethod(
            '_executeDependencyValidators',
            new RenderedMethod(
                $schema,
                $this->generatorConfiguration,
                join(DIRECTORY_SEPARATOR, ['Dependencies', 'ExecuteDependencyValidators.phptpl']),
                ['dependencyValidators' => self::getDependencyValidators($schema)],
            ),
        );
    }

    public function isMutableBaseValidator(GeneratorConfiguration $generatorConfiguration, bool $isBaseValidator): bool
    {
        return !$generatorConfiguration->isImmutable() && $isBaseValidator;
    }

    /**
     * Render $value as a PHP literal suitable for embedding directly in generated code: short-array ([...]) syntax
     * for arrays (recursively, preserving string keys for associative arrays), plain var_export() for everything
     * else. var_export() alone always emits the old array (...) syntax for arrays, which - unlike its output for
     * scalars - is genuinely multi-line and needs the caller to account for indentation; routing every array value
     * embedded in generated code through this method avoids that.
     */
    public static function varExportArray(mixed $values): string
    {
        if (!is_array($values)) {
            return self::exportScalar($values);
        }

        $isList = array_is_list($values);

        $entries = [];
        foreach ($values as $key => $value) {
            $exportedValue = is_array($value) ? self::varExportArray($value) : self::exportScalar($value);
            $entries[] = $isList ? $exportedValue : var_export($key, true) . ' => ' . $exportedValue;
        }

        return '[' . implode(', ', $entries) . ']';
    }

    /**
     * var_export(null, true) returns the string "NULL" - unlike its output for true/false, which is already
     * lowercase "true"/"false" - so it's the one scalar case PSR-12's lowercase-constant rule always rejects.
     */
    private static function exportScalar(mixed $value): string
    {
        return $value === null ? 'null' : var_export($value, true);
    }

    public static function filterClassImports(array $imports, string $namespace): array
    {
        // filter out non-compound uses and uses which link to the current namespace
        return array_filter(
            $imports,
            static fn($classPath): bool =>
                strstr(trim(str_replace("$namespace", '', $classPath), '\\'), '\\')
                    || (!str_contains($classPath, '\\') && !empty($namespace)),
        );
    }

    /**
     * The template engine only removes {% %} tags themselves, not the surrounding whitespace/newline of the line
     * they occupy, so a tag placed alone on its own line (the common case for {% if %}/{% foreach %}) leaves a
     * whitespace-only line behind in the rendered output. Normalize those away instead of restructuring every
     * template to avoid a tag ever sitting alone on a line, which would make the templates themselves far less
     * readable. Also drops blank lines directly after an opening brace or directly before a closing one, which
     * PSR-12 disallows regardless of how they got there.
     *
     * Shared by every renderer that writes a class file to disk (the main RenderJob, and the
     * Builder/Companion/Enum post processors), since every one of them renders through the same templating engine
     * and is subject to the same artifact.
     */
    public static function collapseBlankLines(string $code): string
    {
        $code = preg_replace('/^[ \t]+$/m', '', $code);
        $code = preg_replace('/\n{3,}/', "\n\n", $code);
        $code = preg_replace('/\{\n\n+/', "{\n", $code);
        $code = preg_replace('/\n\n+([ \t]*\})/', "\n$1", $code);

        return $code;
    }
}
