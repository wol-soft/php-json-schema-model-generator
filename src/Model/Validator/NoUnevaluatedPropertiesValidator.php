<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Object\UnevaluatedPropertiesException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Validator emitted for schemas declaring `unevaluatedProperties: false`.
 *
 * Mirrors NoAdditionalPropertiesValidator, except the evaluated set also incorporates
 * keys claimed by sibling composition branches (via the _compositionEvaluations cache).
 */
class NoUnevaluatedPropertiesValidator extends AbstractUnevaluatedPropertiesValidator
{
    public function __construct(Schema $compositionScope, JsonSchema $propertiesStructure)
    {
        $this->isResolved = true;

        parent::__construct(
            $compositionScope,
            $propertiesStructure,
            DIRECTORY_SEPARATOR . 'Validator' . DIRECTORY_SEPARATOR . 'NoUnevaluatedProperties.phptpl',
            UnevaluatedPropertiesException::class,
            ['&$unevaluatedProperties'],
        );
    }
}
