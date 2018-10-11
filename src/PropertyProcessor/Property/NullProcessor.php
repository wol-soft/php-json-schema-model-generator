<?php

declare(strict_types = 1);

namespace PHPModelGenerator\PropertyProcessor\Property;

/**
 * Class NullProcessor
 *
 * @package PHPModelGenerator\PropertyProcessor\Property
 */
class NullProcessor extends AbstractScalarValueProcessor
{
    protected const TYPE = 'null';
}
