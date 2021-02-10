<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\RenderJob;
use PHPModelGenerator\SchemaProcessor\PostProcessor\PostProcessor;

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
     * Render all collected jobs of the RenderQueue and clear the queue
     *
     * @param GeneratorConfiguration   $generatorConfiguration
     * @param PostProcessor[] $postProcessors
     *
     * @throws FileSystemException
     * @throws RenderException
     */
    public function execute(GeneratorConfiguration $generatorConfiguration, array $postProcessors): void {
        foreach ($postProcessors as $postProcessor) {
            $postProcessor->preProcess();
        }

        foreach ($this->jobs as $job) {
            $job->postProcess($postProcessors, $generatorConfiguration);
            $job->render($generatorConfiguration);
        }

        foreach ($postProcessors as $postProcessor) {
            $postProcessor->postProcess();
        }

        $this->jobs = [];
    }
}