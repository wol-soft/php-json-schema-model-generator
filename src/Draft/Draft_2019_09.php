<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Model\Validator\Factory\Arrays\ContainsValidatorFactory;

class Draft_2019_09 extends Draft_07
{
    public function getDefinition(): DraftBuilder
    {
        $builder = parent::getDefinition();

        $builder->getType('array')
            ->addValidator('contains', new ContainsValidatorFactory());

        return $builder;
    }
}
