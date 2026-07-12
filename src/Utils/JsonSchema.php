<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Attributes\JsonPointer;
use PHPModelGenerator\Model\Property\PropertyInterface;

/**
 * JSON Schema pointer helpers. Groups the RFC 6901 encode/decode primitives and the
 * per-property primary-pointer lookup so callers do not reach into
 * Model\SchemaDefinition\JsonSchema (which represents a resolved schema location, not a
 * pointer utility) or duplicate the primary-pointer resolution across post processors.
 */
final class JsonSchema
{
    /**
     * Escape RFC 6901 reserved characters inside a single pointer segment. `~` becomes `~0`
     * and must be escaped before `/`, otherwise a raw `/` in the input would collapse into
     * `~01` after both replacements and decode back to `/` instead of `~1`.
     */
    public static function encodePointer(string | int $pointer): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], (string) $pointer);
    }

    /**
     * Inverse of encodePointer. Order matters symmetrically: unescape `~1` before `~0` so a
     * segment like `~01` (originally `/`) does not decode to `~/`.
     */
    public static function decodePointer(string | int $pointer): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], (string) $pointer);
    }

    /**
     * Resolve the single JSON pointer that identifies a property's declaration site.
     *
     * A property merged from multiple composition branches carries one #[JsonPointer]
     * attribute per defining location, synthesized by PropertyAttributeSynthesizer. Reading
     * the first attribute preserves the same single-source-of-truth used for the generated
     * #[JsonPointer] attributes rather than independently recomputing it from
     * $property->getJsonSchema()->getPointer(), which would silently diverge if
     * PropertyMerger's choice of JsonSchema changed.
     */
    public static function resolvePrimaryJsonPointer(PropertyInterface $property): string
    {
        foreach ($property->getAttributes() as $attribute) {
            if ($attribute->getFqcn() === JsonPointer::class) {
                return (string) $attribute->getArguments()[0];
            }
        }

        return $property->getJsonSchema()->getPointer();
    }
}
