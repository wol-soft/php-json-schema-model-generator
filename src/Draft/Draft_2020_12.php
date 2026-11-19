<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Model\Validator\Factory\Arrays\ContainsValidatorFactory;
use PHPModelGenerator\Model\Validator\Factory\Arrays\ItemsValidatorFactory;

class Draft_2020_12 extends Draft_2019_09
{
    public function getDefinition(): DraftBuilder
    {
        $builder = parent::getDefinition();

        // Both factories replace the ones registered by Draft 2019-09 at their position:
        // - `items` has no tuple form and `additionalItems` doesn't exist (see ItemsValidatorFactory)
        // - the items matched by `contains` count as evaluated for unevaluatedItems (Draft 2019-09 gives the keyword no
        //   annotation)
        //
        // Draft 2020-12 moved the tuple form of `items` to its own keyword and left `items` itself
        // as the schema for whatever the tuple did not cover. The tuple behaves identically under
        // both spellings, so the same factory drives it; it resolves the trailing-entry keyword
        // from the tuple keyword it was registered under.
        $builder->getType('array')
            ->addValidator('items', new ItemsValidatorFactory(supportTupleForm: false))
            ->addValidator('contains', new ContainsValidatorFactory(evaluatesItems: true))
            ->addValidator('prefixItems', new ItemsValidatorFactory());

        return $builder;
    }
}
