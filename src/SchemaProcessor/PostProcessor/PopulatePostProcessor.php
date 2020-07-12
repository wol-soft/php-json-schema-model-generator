<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;

/**
 * Class PopulatePostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class PopulatePostProcessor implements PostProcessorInterface
{
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $schema->addMethod('populate', new RenderedMethod($schema, $generatorConfiguration, 'Populate.phptpl'));
    }
}
