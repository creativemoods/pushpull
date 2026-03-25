<?php

declare(strict_types=1);

namespace PushPull\Support\Json;

final class CanonicalJson
{
    /**
     * @param mixed $value
     */
    public static function encode(mixed $value): string
    {
        $normalized = self::normalize($value);
        $encoder = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';
        $json = $encoder(
            $normalized,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return is_string($json) ? $json . "\n" : "null\n";
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'normalize'], $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
