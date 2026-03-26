<?php

declare(strict_types=1);

namespace PushPull\Support\Urls;

final class EnvironmentUrlCanonicalizer
{
    private const HOME_URL_PLACEHOLDER = '{{pushpull.home_url}}';
    private const SITE_URL_PLACEHOLDER = '{{pushpull.site_url}}';

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function normalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::normalizeString($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $entry) {
                $value[$key] = self::normalizeValue($entry);
            }
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function denormalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::denormalizeString($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $entry) {
                $value[$key] = self::denormalizeValue($entry);
            }
        }

        return $value;
    }

    private static function normalizeString(string $value): string
    {
        return str_replace(
            array_keys(self::replacementsForNormalization()),
            array_values(self::replacementsForNormalization()),
            $value
        );
    }

    private static function denormalizeString(string $value): string
    {
        return str_replace(
            [self::HOME_URL_PLACEHOLDER, self::SITE_URL_PLACEHOLDER],
            [self::homeUrl(), self::siteUrl()],
            $value
        );
    }

    /**
     * @return array<string, string>
     */
    private static function replacementsForNormalization(): array
    {
        $replacements = [];
        $homeUrl = self::homeUrl();
        $siteUrl = self::siteUrl();

        if ($homeUrl !== '') {
            $replacements[$homeUrl] = self::HOME_URL_PLACEHOLDER;
        }

        if ($siteUrl !== '' && $siteUrl !== $homeUrl) {
            $replacements[$siteUrl] = self::SITE_URL_PLACEHOLDER;
        }

        uksort(
            $replacements,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left)
        );

        return $replacements;
    }

    private static function homeUrl(): string
    {
        return rtrim((string) home_url(), '/');
    }

    private static function siteUrl(): string
    {
        return rtrim((string) site_url(), '/');
    }
}
