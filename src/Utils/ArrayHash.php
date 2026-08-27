<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

class ArrayHash
{
    public static function hash(array $array, array $relevantFields = []): string
    {
        if ($relevantFields) {
            foreach (array_keys($array) as $key) {
                if (!in_array($key, $relevantFields)) {
                    unset($array[$key]);
                }
            }
        }

        self::array_multiksort($array);

        return md5(json_encode($array));
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
