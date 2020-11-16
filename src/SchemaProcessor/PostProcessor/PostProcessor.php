<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;

abstract class PostProcessor
{
    /**
     * Have fun doin' crazy stuff with the schema
     *
     * @param Schema $schema
     * @param GeneratorConfiguration $generatorConfiguration
     */
    abstract public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void;

    /**
     * Overwrite this function to execute code before the schemas are processed by the post processor
     */
    public function preProcess(): void
    {
    }

    /**
     * Overwrite this function to execute code after the schemas are processed by the post processor
     */
    public function postProcess(): void
    {
    }
}
