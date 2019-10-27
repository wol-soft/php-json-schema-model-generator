<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Utils;

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
        array $schema,
        bool $isMergeClass,
        string $currentClassName = ''
    ): string {
        $className = sprintf(
            $isMergeClass ? '%s_Merged_%s' : '%s_%s',
            $currentClassName,
            $schema['$id'] ?? ($propertyName . ($currentClassName ? uniqid() : ''))
        );

        return ucfirst(preg_replace('/\W/', '', trim($className, '_')));
    }
}
