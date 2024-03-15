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
        string $currentClassName = '',
    ): string {
        $json = $isMergeClass && isset($schema->getJson()['propertySchema'])
            ? $schema->getJson()['propertySchema']->getJson()
            : $schema->getJson();

        $className = sprintf(
            $isMergeClass ? '%s_Merged_%s' : '%s_%s',
            $currentClassName,
            ucfirst(
                isset($json['$id'])
                    ? str_replace('#', '', $json['$id'])
                    : ($propertyName . ($currentClassName ? uniqid() : '')),
            )
        );

        return ucfirst(preg_replace('/\W/', '', trim($className, '_')));
    }
}
