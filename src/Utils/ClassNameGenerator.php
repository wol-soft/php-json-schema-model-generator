<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Class ClassNameGenerator
 *
 * @package PHPModelGenerator\Utils
 */
class ClassNameGenerator implements ClassNameGeneratorInterface
{
    /**
     * @inheritDoc
     */
    public function getClassName(
        string $propertyName,
        JsonSchema $schema,
        bool $isMergeClass,
        string $currentClassName = ''
    ): string {
        $className = sprintf(
            $isMergeClass ? '%s_Merged_%s' : '%s_%s',
            $currentClassName,
            ucfirst(
                isset($schema->getJson()['$id'])
                    ? str_replace('#', '', $schema->getJson()['$id'])
                    : ($propertyName . ($currentClassName ? uniqid() : ''))
            )
        );

        return ucfirst(preg_replace('/\W/', '', trim($className, '_')));
    }
}
