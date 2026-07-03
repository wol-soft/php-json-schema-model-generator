<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;

class ClassNameGenerator implements ClassNameGeneratorInterface
{
    /**
     * @inheritDoc
     */
    public function getClassName(
        string $propertyName,
        JsonSchema $schema,
        bool $isMergeClass,
        string $currentClassName = '',
    ): string {
        $innerSchema = $isMergeClass && isset($schema->getJson()['propertySchema'])
            ? $schema->getJson()['propertySchema']
            : $schema;

        $json               = $innerSchema->getJson();
        $pointer            = $innerSchema->getPointer();
        $definitionName     = $this->extractDefinitionName($pointer);
        $inlinePropertyName = $this->extractInlinePropertyName($pointer);
        $arrayItemBaseName  = $this->extractArrayItemBaseName($pointer);

        $className = sprintf(
            $isMergeClass ? '%s_Merged_%s' : '%s_%s',
            $currentClassName,
            ucfirst((string) match (true) {
                isset($json['title'])        => $json['title'],
                isset($json['$id'])          => basename((string) $json['$id']),
                isset($json['$anchor'])      => $json['$anchor'],
                $definitionName !== null     => $definitionName,
                $inlinePropertyName !== null => $inlinePropertyName,
                $arrayItemBaseName !== null  => $arrayItemBaseName . 'Item',
                default                      => ($propertyName . ($currentClassName ? md5(json_encode($json)) : '')),
            }),
        );

        return ucfirst((string) preg_replace('/\W/', '', ucwords(trim($className, '_'), '_-. ')));
    }

    /**
     * Extract a class name from a JSON pointer when the schema lives at a named definition slot
     * (i.e. the pointer's second-to-last segment is "definitions" or "$defs"). Returns null for
     * every other path — inline schemas, array indices, and composition branch positions — so the
     * caller falls through to subsequent naming levels.
     */
    private function extractDefinitionName(string $pointer): ?string
    {
        $segments = array_values(array_filter(explode('/', $pointer)));
        $count    = count($segments);

        if (
            $count >= 2 &&
            in_array($segments[$count - 2], ['definitions', '$defs'], true) &&
            !is_numeric($segments[$count - 1])
        ) {
            return $segments[$count - 1];
        }

        return null;
    }

    /**
     * Extract a class name from a JSON pointer when the schema is an inline object inside a
     * "properties" slot (i.e. the pointer's second-to-last segment is "properties"). Returns the
     * property key without appending a content hash: within a single object, property keys are
     * unique, so the combination of parent class name and property key is already collision-free.
     * Returns null for any other pointer shape.
     */
    private function extractInlinePropertyName(string $pointer): ?string
    {
        $segments = array_values(array_filter(explode('/', $pointer)));
        $count    = count($segments);

        if (
            $count >= 2 &&
            $segments[$count - 2] === 'properties' &&
            !is_numeric($segments[$count - 1])
        ) {
            return $segments[$count - 1];
        }

        return null;
    }

    /**
     * Extract the base name for an array-items schema from its JSON pointer. When the last pointer
     * segment is "items" and the preceding segment is a non-numeric identifier (i.e. the array
     * lives in a named property or definition, not inside a composition branch index), returns that
     * identifier so the caller can append "Item". Returns null for tuple items (numeric predecessor)
     * and any other pointer shape that does not end in "items".
     */
    private function extractArrayItemBaseName(string $pointer): ?string
    {
        $segments = array_values(array_filter(explode('/', $pointer)));
        $count    = count($segments);

        if (
            $count >= 2 &&
            $segments[$count - 1] === 'items' &&
            !is_numeric($segments[$count - 2])
        ) {
            return $segments[$count - 2];
        }

        return null;
    }
}
