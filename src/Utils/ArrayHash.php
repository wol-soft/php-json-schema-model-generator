<?php

declare(strict_types=1);

namespace PHPModelGenerator\Utils;

class ArrayHash
{
    /**
     * @param bool $sortLists Sort every list (order-insensitive comparison). Without, only the keys of maps are
     *                        sorted and lists keep their order.
     */
    public static function hash(array $array, array $relevantFields = [], bool $sortLists = true): string
    {
        if ($relevantFields) {
            foreach ($array as $key => $_) {
                if (!in_array($key, $relevantFields)) {
                    unset($array[$key]);
                }
            }
        }

        self::array_multiksort($array, $sortLists);

        return md5(json_encode($array));
    }

    private static function array_multiksort(array &$array, bool $sortLists): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::array_multiksort($value, $sortLists);
            }
        }

        if (!array_is_list($array)) {
            ksort($array);
        } elseif ($sortLists) {
            sort($array);
        }
    }
}
