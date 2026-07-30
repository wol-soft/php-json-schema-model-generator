<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\SchemaProcessor\Hook\SchemaHookResolver;
use PHPModelGenerator\Utils\RenderFactory;
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
                    // Rendered as its own template and embedded via viewHelper.indent() instead of being inlined
                    // directly: the shared rollback-tracking/setter-dispatch body sits at a different real
                    // nesting depth depending on whether collectErrors() wraps it in a bare block or a try {} -
                    // one literal indentation can't be correct for both, so the body is written once, at its own
                    // canonical depth, and the two call sites in Populate.phptpl each indent the rendered result
                    // to their own real depth.
                    'renderPopulateBody' => function () use (
                        $schema,
                        $generatorConfiguration,
                        $schemaHookResolver,
                    ): string {
                        return RenderFactory::create(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR)
                            ->renderTemplate('PopulateBody.phptpl', [
                                'schema' => $schema,
                                'schemaHookResolver' => $schemaHookResolver,
                                'viewHelper' => new RenderHelper($generatorConfiguration),
                            ]);
                    },
                ],
            )
        );
    }
}
