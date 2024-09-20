<?php

declare(strict_types = 1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPMicroTemplate\Exception\FileSystemException;
use PHPMicroTemplate\Exception\SyntaxErrorException;
use PHPMicroTemplate\Exception\UndefinedSymbolException;
use PHPMicroTemplate\Render;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\MethodInterface;
use PHPModelGenerator\Model\Schema;
use PHPModelGenerator\Utils\RenderHelper;

/**
 * Class RenderedMethod
 *
 * @package PHPModelGenerator\SchemaProcessor\PostProcessor
 */
class RenderedMethod implements MethodInterface
{
    static private ?Render $renderer = null;

    public function __construct(
        private Schema $schema,
        private GeneratorConfiguration $generatorConfiguration,
        private string $template,
        private array $templateValues = [],
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

    protected function getRenderer(): Render
    {
        if (!self::$renderer) {
            self::$renderer = new Render(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR);
        }

        return self::$renderer;
    }
}
