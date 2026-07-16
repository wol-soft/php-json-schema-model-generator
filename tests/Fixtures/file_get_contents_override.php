<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProvider;

use PHPModelGenerator\Tests\Fixtures\FileGetContentsFailureSimulator;

/**
 * Test-only override of the built-in file_get_contents() for this namespace. PHP resolves an
 * unqualified function call by first looking for a function of the same name declared in the
 * calling code's own namespace before falling back to the global one - so every unqualified
 * file_get_contents() call made by code in the PHPModelGenerator\SchemaProvider namespace
 * (RefResolverTrait, RecursiveDirectoryProvider, OpenAPIv3Provider, SingleFileProvider) resolves
 * to this function once this file is loaded, in every test in the suite - not only the tests that
 * use FileGetContentsFailureSimulator.
 *
 * It is a transparent passthrough to the real function unless FileGetContentsFailureSimulator has
 * armed the exact path being read, which lets a single test deterministically reproduce a "path
 * resolved but content could not be read" failure on every platform, including ones where no real
 * filesystem trick for that condition exists (see FileGetContentsFailureSimulator for why).
 */
function file_get_contents(
    string $filename,
    bool $use_include_path = false,
    $context = null,
    int $offset = 0,
    ?int $length = null,
): string|false {
    $armedMessage = FileGetContentsFailureSimulator::messageFor($filename);

    if ($armedMessage !== null) {
        trigger_error($armedMessage, E_USER_WARNING);

        return false;
    }

    return \file_get_contents($filename, $use_include_path, $context, $offset, $length);
}
