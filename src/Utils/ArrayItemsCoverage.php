<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Draft\Draft;

/**
 * Reads which array indices the positional keywords of one schema node claim, for one draft.
 *
 * The tuple keyword is `prefixItems` only where the draft registers it, and `items` otherwise.
 * Do NOT read `prefixItems` first unconditionally: before Draft 2020-12 it is an unknown keyword
 * the spec requires to be ignored, so reading it as a tuple credits indices that nothing
 * evaluated and accepts an array `unevaluatedItems` must reject. The tail keyword follows from the
 * tuple keyword: Draft 2020-12 reuses `items` for the entries behind a `prefixItems` tuple, which
 * is the role `additionalItems` fills behind a tuple `items` in earlier drafts.
 *
 * Every place that decides what an array node claims — the sibling `unevaluatedItems` validators,
 * the composition branches, the post processor's down-propagation guard — asks this class, so the
 * same JSON means the same thing at all of them.
 */
final class ArrayItemsCoverage
{
    /**
     * The keyword that constrains the entries a tuple does not cover, per tuple keyword.
     */
    public const array TAIL_KEYWORD = [
        'items' => 'additionalItems',
        'prefixItems' => 'items',
    ];

    private function __construct(
        private readonly string $tupleKeyword,
        private readonly int $tupleLength,
        private readonly bool $tailIsClaimed,
    ) {}

    /**
     * Asks the draft rather than testing for the key alone: the same factory instances serve every
     * draft, so only the registry knows whether `prefixItems` means anything here.
     */
    public static function registersPrefixItems(Draft $draft): bool
    {
        return $draft->getTypesForKeyword('prefixItems') !== [];
    }

    /**
     * The keywords of an array schema node that evaluate (claim) array indices by position, in the
     * given draft. `contains` is not part of this list: it claims the indices it matched, which
     * only the instance can tell.
     *
     * @return string[]
     */
    public static function positionalKeywords(Draft $draft): array
    {
        return self::registersPrefixItems($draft)
            ? ['items', 'prefixItems', 'additionalItems']
            : ['items', 'additionalItems'];
    }

    /**
     * @param array<string, mixed> $json The schema node as written
     */
    public static function fromJson(array $json, Draft $draft): self
    {
        $tupleKeyword = self::registersPrefixItems($draft) && array_key_exists('prefixItems', $json)
            ? 'prefixItems'
            : 'items';
        $tailKeyword = self::TAIL_KEYWORD[$tupleKeyword];
        $tuple = $json[$tupleKeyword] ?? null;

        if (!is_array($tuple) || $tuple === [] || !array_is_list($tuple)) {
            return new self($tupleKeyword, 0, false);
        }

        return new self(
            $tupleKeyword,
            count($tuple),
            array_key_exists($tailKeyword, $json) && $json[$tailKeyword] !== false,
        );
    }

    public function getTupleKeyword(): string
    {
        return $this->tupleKeyword;
    }

    public function getTailKeyword(): string
    {
        return self::TAIL_KEYWORD[$this->tupleKeyword];
    }

    /**
     * Number of leading indices the tuple evaluates; 0 when the node carries no tuple. An empty
     * PHP array counts as no tuple: `json_decode(..., true)` maps `[]` and `{}` to the same value,
     * and the empty schema `{}` is the only reading of it with a legal spelling.
     */
    public function getTupleLength(): int
    {
        return $this->tupleLength;
    }

    /**
     * Whether the tail keyword evaluates every index behind the tuple: it must sit behind a tuple
     * and carry a value other than `false`, which rejects the tail instead of evaluating it. A
     * lone tail keyword without a tuple claims nothing here — `additionalItems` has no effect
     * without a tuple `items` beside it, and a lone `items` is the schema form that claims every
     * index by itself, not a tail.
     */
    public function tailIsClaimed(): bool
    {
        return $this->tailIsClaimed;
    }
}
