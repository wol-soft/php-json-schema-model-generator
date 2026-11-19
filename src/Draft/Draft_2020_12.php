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
        // - Draft 2020-12 has no tuple form of `items` and no `additionalItems` (see ItemsValidatorFactory)
        // - the items matched by `contains` count as evaluated for unevaluatedItems (Draft 2019-09 gives the keyword no
        //   annotation)
        $builder->getType('array')
            ->addValidator('items', new ItemsValidatorFactory(supportTupleForm: false))
            ->addValidator('contains', new ContainsValidatorFactory(evaluatesItems: true));

        return $builder;
    }
}
