<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException;
use PHPModelGenerator\Model\Property\Property;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Validator for patternProperties where the pattern schema is `false`.
 *
 * Any property whose key matches the pattern is forbidden. Throws
 * InvalidPatternPropertiesException listing the matched property names, consistent with the
 * exception thrown by PatternPropertiesValidator for schema-constrained pattern properties.
 */
class ForbiddenPatternPropertiesValidator extends AbstractPropertyValidator
{
    /**
     * @param string $pattern The unescaped regex pattern from the schema
     */
    public function __construct(private readonly string $pattern, string $className, JsonSchema $propertySchema)
    {
        parent::__construct(
            new Property($className, null, $propertySchema->withJson([])),
            InvalidPatternPropertiesException::class,
            [$pattern, '&$invalidProperties'],
        );
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getValidatorSetUp(): string
    {
        return '
            $properties = $value;
            $invalidProperties = [];
        ';
    }

    public function getCheck(): string
    {
        $escapedPattern = addcslashes($this->pattern, '/');

        return <<<PHP
(function () use (\$properties, &\$invalidProperties) {
    foreach (\$properties as \$propertyKey => \$propertyValue) {
        \$propertyKey = (string) \$propertyKey;
        if (!preg_match('/$escapedPattern/', \$propertyKey)) {
            continue;
        }
        \$invalidProperties[\$propertyKey] = [
            new \PHPModelGenerator\Exception\Generic\DeniedPropertyException(\$propertyValue, \$propertyKey),
        ];
    }
    return !empty(\$invalidProperties);
})()
PHP;
    }
}
