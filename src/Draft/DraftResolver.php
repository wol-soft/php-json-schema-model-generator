<?php

declare(strict_types=1);

namespace PHPModelGenerator\Draft;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

/**
 * Owns the built (immutable) Draft registry cache. Kept separate from GeneratorConfiguration,
 * which is part of the public library interface, so this cache and its resolution logic don't
 * live directly on that public surface - GeneratorConfiguration keeps the configured draft (or
 * draft factory) itself and passes it in on each call, since the resolver has no way to be
 * notified of a later setDraft() call otherwise.
 */
class DraftResolver
{
    /** @var Draft[] Built (immutable) Draft registries, keyed by draft class name */
    private array $builtDraftCache = [];

    /**
     * Resolve the active draft for the given schema (via DraftFactoryInterface::getDraftForSchema
     * when a factory is configured) and build its immutable Draft registry, caching the result by
     * draft class so repeated calls for the same draft don't rebuild it.
     */
    public function getBuiltDraft(DraftInterface | DraftFactoryInterface $draft, JsonSchema $propertySchema): Draft
    {
        $draftInterface = $draft instanceof DraftFactoryInterface
            ? $draft->getDraftForSchema($propertySchema)
            : $draft;

        return $this->builtDraftCache[$draftInterface::class] ??= $draftInterface->getDefinition()->build();
    }
}
