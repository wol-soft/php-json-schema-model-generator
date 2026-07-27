<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPMicroTemplate\Render;
use PHPMicroTemplate\RenderConfig;

/**
 * Class RenderFactory
 *
 * Single point of truth for constructing Render instances: every consumer in this library renders with
 * autoIndent enabled to get PSR-12-compliant output, so that choice lives here instead of being repeated
 * (and risking drift) at every call site. Instances are cached per base path, since a Render instance holds
 * no request-specific state and every call site with the same base path can safely share one.
 *
 * @package PHPModelGenerator\Utils
 */
class RenderFactory
{
    /** @var Render[] */
    private static array $renderers = [];

    public static function create(string $basePath): Render
    {
        return self::$renderers[$basePath] ??= new Render($basePath, new RenderConfig(true));
    }
}
