<?php

declare(strict_types=1);

namespace PushPull\Integration\Wpml;

final class WpmlRuntimeLanguage
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function runInDefaultLanguage(callable $callback): mixed
    {
        $defaultLanguage = self::defaultLanguage();

        if ($defaultLanguage === '') {
            return $callback();
        }

        $originalLanguage = self::currentLanguage();
        self::switchTo($defaultLanguage);

        try {
            return $callback();
        } finally {
            self::switchTo($originalLanguage);
        }
    }

    public static function defaultLanguage(): string
    {
        if (isset($GLOBALS['sitepress']) && is_object($GLOBALS['sitepress']) && is_callable([$GLOBALS['sitepress'], 'get_default_language'])) {
            return (string) $GLOBALS['sitepress']->get_default_language();
        }

        $settings = get_option(WpmlConfigurationApplier::SETTINGS_OPTION, []);

        return is_array($settings) ? (string) ($settings['default_language'] ?? '') : '';
    }

    public static function currentLanguage(): string
    {
        if (isset($GLOBALS['pushpull_test_wpml_current_language']) && is_string($GLOBALS['pushpull_test_wpml_current_language'])) {
            return $GLOBALS['pushpull_test_wpml_current_language'];
        }

        return '';
    }

    private static function switchTo(string $language): void
    {
        $GLOBALS['pushpull_test_wpml_current_language'] = $language;

        if (isset($GLOBALS['sitepress']) && is_object($GLOBALS['sitepress']) && is_callable([$GLOBALS['sitepress'], 'switch_lang'])) {
            $GLOBALS['sitepress']->switch_lang($language);

            return;
        }

        if (function_exists('do_action')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is WPML's documented hook name.
            do_action('wpml_switch_language', $language);
        }
    }
}
