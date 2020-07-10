<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Model\Schema;

interface PostProcessorInterface
{
    /**
     * Have fun doin' crazy stuff with the schema
     *
     * @param Schema $schema
     */
    public function process(Schema $schema): void;
}
