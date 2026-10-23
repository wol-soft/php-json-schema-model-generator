<?php

declare(strict_types=1);

namespace PHPModelGenerator\Exception;

/**
 * A schema is valid JSON Schema, but uses a combination this generator does not support yet -
 * distinguishable from a genuinely invalid/contradictory schema (a plain SchemaException), which
 * a caller wants to handle differently. Existing code catching SchemaException still catches
 * this unchanged.
 */
class UnsupportedSchemaFeatureException extends SchemaException
{
}
