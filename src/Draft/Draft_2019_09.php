<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Draft\Producer\RefResolver;
use PHPModelGenerator\Model\Validator\Factory\Arrays\ContainsValidatorFactory;

class Draft_2019_09 extends Draft_07
{
    public function getDefinition(): DraftBuilder
    {
        $builder = parent::getDefinition();

        // From Draft 2019-09 onwards $ref is conjunctive: siblings apply alongside the reference.
        // Overwrite the Draft-07 ExclusiveProducer with the bare resolver (keeping its position).
        $builder->addProducer('$ref', new RefResolver());

        $builder->getType('array')
            ->addValidator('contains', new ContainsValidatorFactory());

        return $builder;
    }
}
