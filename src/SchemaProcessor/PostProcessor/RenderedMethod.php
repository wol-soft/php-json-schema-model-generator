<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\MethodInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Utils\RenderFactory;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class RenderedMethod
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class RenderedMethod implements MethodInterface
{
    public function __construct(
        private readonly Schema $schema,
        private readonly GeneratorConfiguration $generatorConfiguration,
        private readonly string $template,
        private readonly array $templateValues = [],
    ) {}

    /**
     * @inheritDoc
     *
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    public function getCode(): string
    {
        return $this->getRenderer()->renderTemplate(
            $this->template,
            array_merge(
                [
                    'true' => true,
                    'schema' => $this->schema,
                    'viewHelper' => new RenderHelper($this->generatorConfiguration),
                    'generatorConfiguration' => $this->generatorConfiguration,
                ],
                $this->templateValues,
            )
        );
    }

    /**
     * Render a part of a method on its own with the same default template values as a method, e.g. to embed it into
     * the model state rollback (RenderHelper::wrapInModelStateRollback()), which needs the code as a string.
     *
     * @throws FileSystemException
     * @throws SyntaxErrorException
     * @throws UndefinedSymbolException
     */
    public static function renderPart(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
        string $template,
        array $templateValues = [],
    ): string {
        return (new self($schema, $generatorConfiguration, $template, $templateValues))->getCode();
    }

    protected function getRenderer(): Render
    {
        return RenderFactory::create(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR);
    }
}
