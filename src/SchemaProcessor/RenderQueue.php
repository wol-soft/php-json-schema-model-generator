<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor;

use PHPModelGenerator\Exception\FileSystemException;
use PHPModelGenerator\Exception\RenderException;
use PHPModelGenerator\Model\GeneratorConfiguration;
use PHPModelGenerator\Model\RenderJob;

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
     * @param string                 $destination
     * @param GeneratorConfiguration $generatorConfiguration
     *
     * @throws FileSystemException
     * @throws RenderException
     */
    public function execute(string $destination, GeneratorConfiguration $generatorConfiguration): void
    {
        foreach ($this->jobs as $job) {
            $job->render($destination, $generatorConfiguration);
        }

        $this->jobs = [];
    }
}