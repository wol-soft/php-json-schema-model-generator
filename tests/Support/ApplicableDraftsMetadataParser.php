<?php

declare(strict_types=1);

namespace PHPModelGenerator\Tests\Support;

use PHPModelGenerator\Tests\AbstractPHPModelGeneratorTestCase;
use PHPUnit\Metadata\DataProvider as DataProviderMetadata;
use PHPUnit\Metadata\DataProviderClosure as DataProviderClosureMetadata;
use PHPUnit\Metadata\Metadata;
use PHPUnit\Metadata\MetadataCollection;
use PHPUnit\Metadata\Parser\Parser;

/**
 * Wraps PHPUnit's default metadata parser and intercepts forMethod() for test classes that carry
 * an #[ApplicableDrafts] attribute. Synthetic TestWith or DataProviderClosure metadata is injected
 * so that PHPUnit creates one genuine test entry per applicable draft (or per draft × data-set
 * when the method already has a data provider). The applicable JsonSchemaDraft enum case is stored
 * in DraftRunContext keyed by $this->dataName() so generateClass() can apply it automatically.
 *
 * Quick mode (default): one draft per test — the latest applicable draft.
 * Full mode (PHPUNIT_FULL_DRAFT_COVERAGE=1): all drafts in the declared range.
 */
final class ApplicableDraftsMetadataParser implements Parser
{
    /** @var array<string, MetadataCollection> */
    private array $methodCache = [];

    public function __construct(private readonly Parser $delegate)
    {
    }

    public function forClass(string $className): MetadataCollection
    {
        return $this->delegate->forClass($className);
    }

    public function forMethod(string $className, string $methodName): MetadataCollection
    {
        $cacheKey = $className . '::' . $methodName;
        if (isset($this->methodCache[$cacheKey])) {
            return $this->methodCache[$cacheKey];
        }

        $original = $this->delegate->forMethod($className, $methodName);

        if (!is_subclass_of($className, AbstractPHPModelGeneratorTestCase::class)) {
            return $this->methodCache[$cacheKey] = $original;
        }

        $attribute = ApplicableDrafts::forMethod($className, $methodName);
        if ($attribute === null) {
            return $this->methodCache[$cacheKey] = $original;
        }

        $fullMode = getenv('PHPUNIT_FULL_DRAFT_COVERAGE') === '1';
        $drafts   = $fullMode ? $attribute->draftsInRange() : [$attribute->latestApplicable()];

        $hasDataProvider = $original->isDataProvider()->isNotEmpty()
            || $original->isDataProviderClosure()->isNotEmpty();

        $result = $hasDataProvider
            ? $this->injectDraftProductEntries($original, $className, $methodName, $drafts)
            : $this->injectDraftEntries($original, $className, $methodName, $drafts);

        return $this->methodCache[$cacheKey] = $result;
    }

    public function forClassAndMethod(string $className, string $methodName): MetadataCollection
    {
        return $this->delegate->forClass($className)->mergeWith($this->forMethod($className, $methodName));
    }

    /**
     * For tests without a data provider: inject one TestWith entry per draft.
     * Each entry carries an empty argument list (the test method takes no data-provider parameters)
     * and uses the draft label as the data-set name, which becomes $this->dataName() at runtime.
     *
     * @param JsonSchemaDraft[] $drafts
     */
    private function injectDraftEntries(
        MetadataCollection $original,
        string $className,
        string $methodName,
        array $drafts,
    ): MetadataCollection {
        $otherMetadata = array_values(array_filter(
            $original->asArray(),
            fn(Metadata $metadata): bool => !$metadata->isTestWith(),
        ));

        $testWithEntries = [];
        foreach ($drafts as $draft) {
            $draftLabel = $draft->label();
            DraftRunContext::registerDraftForDataName($className, $methodName, $draftLabel, $draft);
            $testWithEntries[] = Metadata::testWith([], $draftLabel);
        }

        return MetadataCollection::fromArray(array_merge($otherMetadata, $testWithEntries));
    }

    /**
     * For tests that already have a data provider: replace all data-provider metadata with a single
     * DataProviderClosure that returns the Cartesian product of drafts × original data sets.
     * Each composite key becomes $this->dataName() at runtime and maps to a DraftRunContext entry.
     *
     * @param JsonSchemaDraft[] $drafts
     */
    private function injectDraftProductEntries(
        MetadataCollection $original,
        string $className,
        string $methodName,
        array $drafts,
    ): MetadataCollection {
        $originalDataProviders = array_values(array_filter(
            $original->asArray(),
            fn(Metadata $metadata): bool => $metadata->isDataProvider(),
        ));
        $originalClosures = array_values(array_filter(
            $original->asArray(),
            fn(Metadata $metadata): bool => $metadata->isDataProviderClosure(),
        ));

        $otherMetadata = array_values(array_filter(
            $original->asArray(),
            fn(Metadata $metadata): bool =>
                !$metadata->isDataProvider() &&
                !$metadata->isDataProviderClosure() &&
                !$metadata->isTestWith(),
        ));

        $closure = static function () use (
            $className,
            $methodName,
            $drafts,
            $originalDataProviders,
            $originalClosures,
        ): array {
            $originalDatasets = [];

            foreach ($originalDataProviders as $providerMetadata) {
                assert($providerMetadata instanceof DataProviderMetadata);
                $providerClass  = $providerMetadata->className();
                $providerMethod = $providerMetadata->methodName();
                foreach ($providerClass::$providerMethod() as $key => $value) {
                    $originalDatasets[] = [$key, $value];
                }
            }

            foreach ($originalClosures as $closureMetadata) {
                assert($closureMetadata instanceof DataProviderClosureMetadata);
                $providerClosure = $closureMetadata->closure();
                foreach ($providerClosure() as $key => $value) {
                    $originalDatasets[] = [$key, $value];
                }
            }

            $result = [];
            foreach ($drafts as $draft) {
                $draftLabel = $draft->label();

                foreach ($originalDatasets as [$originalKey, $originalValue]) {
                    $compositeKey = is_string($originalKey)
                        ? "{$draftLabel} / {$originalKey}"
                        : "{$draftLabel} / #{$originalKey}";
                    DraftRunContext::registerDraftForDataName($className, $methodName, $compositeKey, $draft);
                    $result[$compositeKey] = $originalValue;
                }
            }

            return $result;
        };

        return MetadataCollection::fromArray(array_merge(
            $otherMetadata,
            [Metadata::dataProviderClosure($closure, false)],
        ));
    }
}
