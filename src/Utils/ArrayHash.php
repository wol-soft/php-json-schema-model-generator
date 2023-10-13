<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

class ArrayHash
{
    public static function hash(array $array, array $relevantFields = []): string
    {
        return md5(json_encode(self::filter($array, $relevantFields)));
    }

    private static function filter(array $array, array $relevantFields): array
    {
        if ($relevantFields) {
            foreach ($array as $key => $_) {
                if (!in_array($key, $relevantFields)) {
                    unset($array[$key]);
                }
            }
        }

        self::array_multiksort($array);

        return $array;
    }

    private static function array_multiksort(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::array_multiksort($value);
            }
        }

        array_is_list($array) ? sort($array) : ksort($array);
    }
}
