<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class PopulatePostProcessor
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class PopulatePostProcessor extends PostProcessor
{
    public function process(Schema $schema, GeneratorConfiguration $generatorConfiguration): void
    {
        $schemaHookResolver = new SchemaHookResolver($schema);

        $schema->addMethod(
            'populate',
            new RenderedMethod(
                $schema,
                $generatorConfiguration,
                'Populate.phptpl',
                [
                    'schemaHookResolver' => $schemaHookResolver,
                    'true' => true,
                    // Rendered as its own template: the body is wrapped into the model state rollback
                    // (RenderHelper::wrapInModelStateRollback()), which needs the code as a string to embed it at
                    // its own nesting depth.
                    'renderPopulateBody' => static fn(): string => RenderedMethod::renderPart(
                        $schema,
                        $generatorConfiguration,
                        'PopulateBody.phptpl',
                        [
                            'schemaHookResolver' => $schemaHookResolver,
                            'dependencyValidation' => (new RenderHelper($generatorConfiguration))
                                ->addPopulateDependencyValidation($schema),
                        ],
                    ),
                ],
            )
        );
    }
}
