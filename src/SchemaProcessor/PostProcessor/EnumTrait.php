<?php

declare(strict_types=1);

namespace PHPModelGenerator\SchemaProcessor\PostProcessor;

use PHPModelGenerator\Exception\SchemaException;
use PHPModelGenerator\Model\Property\PropertyInterface;
use PHPModelGenerator\Model\SchemaDefinition\JsonSchema;
use PHPModelGenerator\Utils\NormalizedName;

/**
 * Share common enum code for enum generation in different languages
 */
trait EnumTrait
{
    /**
     * @throws SchemaException
     */
    private function validateEnum(PropertyInterface $property, bool $skipNonMappedEnums = false): bool
    {
        $throw = function (string $message) use ($property): void {
            throw new SchemaException(
                sprintf(
                    $message,
                    $property->getName(),
                    $property->getJsonSchema()->getFile(),
                )
            );
        };

        $json = $property->getJsonSchema()->getJson();

        $types = $this->getArrayTypes($json['enum']);

        // the enum must contain either only string values or provide a value map to resolve the values
        if ($types !== ['string'] && !isset($json['enum-map'])) {
            if ($skipNonMappedEnums) {
                return false;
            }

            $throw('Unmapped enum %s in file %s');
        }

        if (isset($json['enum-map'])) {
            asort($json['enum']);
            if (is_array($json['enum-map'])) {
                asort($json['enum-map']);
            }

            if (!is_array($json['enum-map'])
                || $this->getArrayTypes(array_keys($json['enum-map'])) !== ['string']
                || count(array_uintersect(
                    $json['enum-map'],
                    $json['enum'],
                    fn($a, $b): int => $a === $b ? 0 : 1,
                )) !== count($json['enum'])
            ) {
                $throw('invalid enum map %s in file %s');
            }
        }

        return true;
    }

    private function getArrayTypes(array $array): array
    {
        return array_unique(array_map(
            static fn($item): string => gettype($item),
            $array,
        ));
    }

    protected function getCaseName(mixed $value, ?array $map, JsonSchema $jsonSchema): string
    {
        $caseName = ucfirst(NormalizedName::from($map ? array_search($value, $map, true) : $value, $jsonSchema));

        if (preg_match('/^\d/', $caseName) === 1) {
            $caseName = "_$caseName";
        }

        return $caseName;
    }
}
