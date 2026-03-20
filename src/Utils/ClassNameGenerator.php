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
            ucfirst((string) match(true) {
                isset($json['title']) => $json['title'],
                isset($json['$id']) => basename((string) $json['$id']),
                default => ($propertyName . ($currentClassName ? md5(json_encode($json)) : '')),
            }),
        );

        return ucfirst((string) preg_replace('/\W/', '', ucwords(trim($className, '_'), '_-. ')));
    }
}
