<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPMicroTemplate\Render;
use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\Schema;

/**
 * Provides shared companion-class generation infrastructure for post-processors that
 * write a typed companion file alongside the main generated model class.
 */
trait CompanionGeneratorTrait
{
    private array $pendingCompanions = [];

    public function preProcess(): void
    {
        $this->pendingCompanions = [];
    }

    public function postProcess(): void
    {
        parent::postProcess();

        foreach ($this->pendingCompanions as $entry) {
            $this->renderCompanionFromEntry($entry);
        }
    }

    abstract protected function renderCompanionFromEntry(array $entry): void;

    protected function resolveCompanionNamespace(
        Schema $schema,
        GeneratorConfiguration $generatorConfiguration,
    ): string {
        return trim(
            join('\\', [$generatorConfiguration->getNamespacePrefix(), $schema->getClassPath()]),
            '\\',
        );
    }

    /**
     * @throws FileSystemException
     */
    protected function writeAndRequireCompanionFile(
        Schema $schema,
        string $companionClassName,
        string $namespace,
        string $templatePath,
        array $templateVars,
    ): void {
        $filename = str_replace(
            $schema->getClassName() . '.php',
            $companionClassName . '.php',
            $schema->getTargetFileName(),
        );

        $result = file_put_contents(
            $filename,
            (new Render(__DIR__ . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR))
                ->renderTemplate($templatePath, $templateVars),
        );

        if ($result === false) {
            // @codeCoverageIgnoreStart
            throw new FileSystemException("Can't write companion class $namespace\\$companionClassName");
            // @codeCoverageIgnoreEnd
        }

        require $filename;
    }
}
