<?php

declare(strict_types=1);

namespace PHPModelGenerator\Model\Validator;

use PHPModelGenerator\Exception\Arrays\InvalidAdditionalTupleItemsException;

class AdditionalItemsValidator extends AdditionalPropertiesValidator
{
    protected const PROPERTY_NAME = 'additional item';

    protected const PROPERTIES_KEY = 'items';
    protected const ADDITIONAL_PROPERTIES_KEY = 'additionalItems';

    protected const EXCEPTION_CLASS = InvalidAdditionalTupleItemsException::class;
    protected const EXTRACTED_VALIDATION_KIND = 'AdditionalItem';
}
