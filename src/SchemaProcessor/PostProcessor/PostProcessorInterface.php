<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;

interface PostProcessorInterface
{
    /**
     * Have fun doin' crazy stuff with the schema
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void;
}
