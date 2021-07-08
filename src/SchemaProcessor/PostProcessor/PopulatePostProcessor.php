<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;

/**
 * Class PopulatePostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class PopulatePostProcessor extends PostProcessor
{
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $schema->addMethod(
            'populate',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'Populate.phptpl',
                [
                    'schemaHookResolver' => new SchemaHookResolver($schema),
                    'true' => true,
                ]
            )
        );
    }
}
