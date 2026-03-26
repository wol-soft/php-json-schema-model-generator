<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Draft\Element\Type;

class Draft_07 implements DraftInterface
{
    public function getDefinition(): DraftBuilder
    {
        return (new DraftBuilder())
            ->addType(new Type('object'))
            ->addType(new Type('array'))
            ->addType(new Type('string'))
            ->addType(new Type('integer'))
            ->addType(new Type('number'))
            ->addType(new Type('boolean'))
            ->addType(new Type('null'))
            ->addType(new Type('any'));
    }
}
