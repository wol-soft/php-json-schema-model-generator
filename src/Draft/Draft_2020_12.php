<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Model\Validator\Factory\Arrays\ItemsValidatorFactory;

class Draft_2020_12 extends Draft_2019_09
{
    public function getDefinition(): DraftBuilder
    {
        $builder = parent::getDefinition();

        // Draft 2020-12 moved the tuple form of `items` to its own keyword and left `items` itself
        // as the schema for whatever the tuple did not cover. The tuple behaves identically under
        // both spellings, so the same factory drives it; it resolves the trailing-entry keyword
        // from the tuple keyword it was registered under.
        $builder->getType('array')->addValidator('prefixItems', new ItemsValidatorFactory());

        return $builder;
    }
}
