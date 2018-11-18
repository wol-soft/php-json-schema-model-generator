<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\RenderJob;

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
     * @param string $destination
     * @param GeneratorConfiguration $generatorConfiguration
     */
    public function execute(string $destination, GeneratorConfiguration $generatorConfiguration): void
    {
        foreach ($this->jobs as $job) {
            $job->render($destination, $generatorConfiguration);
        }

        $this->jobs = [];
    }
}