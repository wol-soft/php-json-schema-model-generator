<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\RenderJob;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessorInterface;

/**
 * Class RenderQueue
 *
 * @package PHPModelGenerator\SchemaProcessor
 */
class RenderQueue
{
    /** @var RenderJob[] */
    protected $jobs = [];

    /**
     * @param RenderJob $renderJob
     *
     * @return $this
     */
    public function addRenderJob(RenderJob $renderJob): self
    {
        $this->jobs[] = $renderJob;

        return $this;
    }

    /**
     * Render all collected jobs of the RenderProxy and clear the queue
     *
     * @param string                   $destination
     * @param GeneratorConfiguration   $generatorConfiguration
     * @param PostProcessorInterface[] $postProcessors
     *
     * @throws FileSystemException
     * @throws RenderException
     */
    public function execute(
        string $destination,
        GeneratorConfiguration $generatorConfiguration,
        array $postProcessors
    ): void {
        foreach ($this->jobs as $job) {
            $job->postProcess($postProcessors);
            $job->render($destination, $generatorConfiguration);
        }

        $this->jobs = [];
    }
}