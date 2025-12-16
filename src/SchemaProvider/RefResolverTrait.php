<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProvider;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

trait RefResolverTrait
{
    public function getRef(string $currentFile, ?string $id, string $ref): JsonSchema
    {
        $jsonSchemaFilePath = $this->getFullRefURL($id ?? $currentFile, $ref)
            ?: $this->getLocalRefPath($currentFile, $ref);

        if ($jsonSchemaFilePath === null || !($jsonSchema = file_get_contents($jsonSchemaFilePath))) {
            throw new SchemaException("Reference to non existing JSON-Schema file $ref");
        }

        if (!($decodedJsonSchema = json_decode($jsonSchema, true))) {
            throw new SchemaException("Invalid JSON-Schema file $jsonSchemaFilePath");
        }

        return new JsonSchema($jsonSchemaFilePath, $decodedJsonSchema);
    }

    /**
     * Try to build a full URL to fetch the schema from utilizing the $id field of the schema
     */
    private function getFullRefURL(string $id, string $ref): ?string
    {
        if (filter_var($ref, FILTER_VALIDATE_URL)) {
            return $ref;
        }

        if (!filter_var($id, FILTER_VALIDATE_URL) || ($idURL = parse_url($id)) === false) {
            return null;
        }

        $baseURL = $idURL['scheme'] . '://' . $idURL['host'] . (isset($idURL['port']) ? ':' . $idURL['port'] : '');

        // root relative $ref
        if (str_starts_with($ref, '/')) {
            return $baseURL . $ref;
        }

        // relative $ref against the path of $id
        $segments = explode('/', rtrim(dirname($idURL['path'] ?? '/'), '/') . '/' . $ref);
        $output = [];

        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($output);
                continue;
            }
            $output[] = $seg;
        }

        return $baseURL . '/' . implode('/', $output);
    }

    private function getLocalRefPath(string $currentFile, string $ref): ?string
    {
        $currentDir = dirname($currentFile);
        // windows compatibility
        $jsonSchemaFile = str_replace('\\', '/', $ref);

        // relative paths to the current location
        if (!str_starts_with($jsonSchemaFile, '/')) {
            $candidate = $currentDir . '/' . $jsonSchemaFile;

            return file_exists($candidate) ? $candidate : null;
        }

        // absolute paths: traverse up to find the context root directory
        $relative = ltrim($jsonSchemaFile, '/');

        $dir = $currentDir;
        while (true) {
            $candidate = $dir . '/' . $relative;
            if (file_exists($candidate)) {
                return $candidate;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }
}