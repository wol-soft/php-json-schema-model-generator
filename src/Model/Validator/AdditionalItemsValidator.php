<?php

declare(strict_types = 1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Arrays\InvalidAdditionalTupleItemsException;
use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\SchemaProcessor\SchemaProcessor;

/**
 * Class AdditionalItemsValidator
 *
 * @package PHPModelGenerator\Model\Validator
 */
class AdditionalItemsValidator extends AdditionalPropertiesValidator
{
    protected const PROPERTY_NAME = 'additional item';

    protected const PROPERTIES_KEY = 'items';
    protected const ADDITIONAL_PROPERTIES_KEY = 'additionalItems';

    protected const EXCEPTION_CLASS = InvalidAdditionalTupleItemsException::class;
}
