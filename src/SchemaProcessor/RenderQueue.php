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
     * @param PostProcessor[] $postProcessors
     *
     * @throws FileSystemException
     * @throws RenderException
     */
    public function execute(GeneratorConfiguration $generatorConfiguration, array $postProcessors): void
    {
        foreach ($postProcessors as $postProcessor) {
            $postProcessor->preProcess();
        }

        // Decouple post-processing from rendering so that a post processor on schema A may
        // mutate schema B (e.g. attach a method to a nested composition-branch class) and have
        // those mutations visible when B is rendered, regardless of queue order. Without this
        // separation, a process()/render() loop interleaved per schema would render B before
        // A's process() had a chance to touch it.
        foreach ($this->jobs as $job) {
            $job->executePostProcessors($postProcessors, $generatorConfiguration);
        }

        foreach ($this->jobs as $job) {
            $job->render($generatorConfiguration);
        }

        foreach ($postProcessors as $postProcessor) {
            $postProcessor->postProcess();
        }

        $this->jobs = [];
    }
}
