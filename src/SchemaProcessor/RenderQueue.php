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
    /** @var array<string, true> Tracks target filenames already in the queue */
    protected array $addedTargets = [];

    /**
     * Add a render job, deduplicating by target filename.
     *
     * WHY DEDUP BY FILENAME RATHER THAN BY OBJECT IDENTITY:
     *   generateModel() caches Schema objects by content+position.  When the same schema
     *   is requested again (e.g. a $def referenced from two places), the same Schema object
     *   is returned and generateClassFile() is called with it again.  Without dedup, the
     *   same file would be rendered twice, causing a file_exists error in RenderJob::render().
     *   Deduping here prevents that before it reaches the file system — this is the correct
     *   place to handle it, not in render() with a class_exists guard.
     *
     * WHY THIS IS NOT A PROBLEM FOR DUPLICATE $id VALUES:
     *   Two different schemas with the same $id produce DIFFERENT Schema objects but the
     *   SAME target filename.  The SECOND addRenderJob call silently skips the duplicate,
     *   using the first schema's rendered output.  This is an intentional design choice:
     *   the second $id is ignored rather than causing an exception, which matches JSON
     *   Schema's $id uniqueness model (later definitions shadow earlier ones).
     *   The file_exists() safety net in RenderJob::render() remains for any case where
     *   addRenderJob dedup fails, but it should never fire.
     *
     * @return $this
     */
    public function addRenderJob(RenderJob $renderJob): self
    {
        $target = $renderJob->getSchema()->getTargetFileName();
        if (!isset($this->addedTargets[$target])) {
            $this->addedTargets[$target] = true;
            $this->jobs[] = $renderJob;
        }

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

        foreach ($this->jobs as $job) {
            $job->executePostProcessors($postProcessors, $generatorConfiguration);
            $job->render($generatorConfiguration);
        }

        foreach ($postProcessors as $postProcessor) {
            $postProcessor->postProcess();
        }

        $this->jobs = [];
    }
}
