<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

interface DraftInterface
{
    public function getDefinition(): DraftBuilder;
}
