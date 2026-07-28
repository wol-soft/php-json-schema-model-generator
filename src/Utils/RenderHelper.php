<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\Validator\ExtractedMethodValidator;
use PHPModelGenerator\Model\Validator\PropertyTemplateValidator;
use PHPModelGenerator\Model\Validator\PropertyValidatorInterface;

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
