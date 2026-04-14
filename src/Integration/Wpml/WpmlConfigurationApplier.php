<?php

declare(strict_types=1);

namespace PushPull\Integration\Wpml;

use RuntimeException;

final class WpmlConfigurationApplier
{
    public const SETTINGS_OPTION = 'icl_sitepress_settings';
    private const CACHE_GROUP = 'pushpull_wpml_configuration';
    public const POST_TYPE_MODE_TRANSLATABLE_ONLY = 'translatable_only';
    public const POST_TYPE_MODE_TRANSLATABLE_FALLBACK = 'translatable_fallback';
    public const POST_TYPE_MODE_NOT_TRANSLATABLE = 'not_translatable';
    public const URL_FORMAT_DIRECTORY = 'directory';
    public const URL_FORMAT_DOMAIN = 'domain';
    public const URL_FORMAT_PARAMETER = 'parameter';
    /**
     * @return array<string, mixed>
     */
    public function exportConfiguration(): array
    {
        $settings = $this->currentSettings();
        $defaultLanguage = (string) ($settings['default_language'] ?? '');
        $activeLanguages = $this->normalizeActiveLanguages($settings['active_languages'] ?? [], $defaultLanguage);

        return [
            'defaultLanguage' => $defaultLanguage,
            'activeLanguages' => $activeLanguages,
            'urlFormat' => $this->urlFormatAsString((int) ($settings['language_negotiation_type'] ?? 1)),
            'postTypeTranslationModes' => $this->exportPostTypeTranslationModes($settings['custom_posts_sync_option'] ?? []),
            'postTypeSlugTranslations' => $this->exportPostTypeSlugTranslations($settings['posts_slug_translation'] ?? []),
            'setupFinished' => (bool) ($settings['setup_complete'] ?? false),
        ];
    }

    public function isAvailable(): bool
    {
        return isset($GLOBALS['sitepress']) && is_object($GLOBALS['sitepress']) && class_exists('WPML_Installation');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function applyConfiguration(array $payload): void
    {
        $sitepress = $this->requireSitePress();
        $installation = $this->createInstallation($sitepress);
        $currentSettings = $this->currentSettings();
        $defaultLanguage = trim((string) ($payload['defaultLanguage'] ?? ''));

        if ($defaultLanguage === '') {
            throw new RuntimeException('WPML configuration is missing a default language.');
        }

        $activeLanguages = $this->normalizeActiveLanguages($payload['activeLanguages'] ?? [], $defaultLanguage);
        $urlFormat = $this->normalizeUrlFormat((string) ($payload['urlFormat'] ?? self::URL_FORMAT_DIRECTORY));
        $postTypeTranslationModes = $this->normalizePostTypeTranslationModes($payload['postTypeTranslationModes'] ?? []);
        $postTypeSlugTranslations = $this->normalizePostTypeSlugTranslations($payload['postTypeSlugTranslations'] ?? []);
        $setupFinished = (bool) ($payload['setupFinished'] ?? false);
        $currentlySetupFinished = (bool) ($currentSettings['setup_complete'] ?? false);

        if ($currentlySetupFinished && ! $setupFinished) {
            throw new RuntimeException('Turning off completed WPML setup is not supported.');
        }

        if (! $currentlySetupFinished) {
            $installation->finish_step1($defaultLanguage);
            $installation->finish_step2($activeLanguages);
        } else {
            $currentDefaultLanguage = (string) ($currentSettings['default_language'] ?? '');
            $currentActiveLanguages = $this->normalizeActiveLanguages($currentSettings['active_languages'] ?? [], $currentDefaultLanguage);

            if ($currentDefaultLanguage !== $defaultLanguage) {
                if (! method_exists($sitepress, 'set_default_language')) {
                    throw new RuntimeException('WPML SitePress instance does not expose set_default_language().');
                }

                $sitepress->set_default_language($defaultLanguage);
            }

            if ($currentActiveLanguages !== $activeLanguages) {
                $installation->set_active_languages($activeLanguages);
            }
        }

        $this->applyPostTypeTranslationModes($postTypeTranslationModes, $sitepress);
        $this->applyPostTypeSlugTranslations($postTypeSlugTranslations, $defaultLanguage, $sitepress);
        $this->saveUrlFormat($urlFormat, $sitepress);

        if ($setupFinished && ! $currentlySetupFinished) {
            $installation->finish_step3();
            $installation->finish_installation();
        }
    }

    /**
     * @param mixed $rawModes
     * @return array<string, string>
     */
    private function exportPostTypeTranslationModes(mixed $rawModes): array
    {
        if (! is_array($rawModes)) {
            return [];
        }

        $normalized = [];

        foreach ($rawModes as $postType => $mode) {
            $postType = sanitize_key((string) $postType);

            if ($postType === '') {
                continue;
            }

            $normalized[$postType] = $this->postTypeModeAsString((int) $mode);
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param mixed $rawSlugSettings
     * @return array<string, array{enabled: bool, values: array<string, string>}>
     */
    private function exportPostTypeSlugTranslations(mixed $rawSlugSettings): array
    {
        $slugSettings = is_array($rawSlugSettings) ? $rawSlugSettings : [];
        $configuredTypes = is_array($slugSettings['types'] ?? null) ? $slugSettings['types'] : [];
        $postTypes = [];

        foreach ($configuredTypes as $postType => $enabled) {
            $postType = sanitize_key((string) $postType);

            if ($postType !== '') {
                $postTypes[$postType] = true;
            }
        }

        foreach ($this->registeredSlugTranslationRecordPostTypes() as $postType) {
            $postTypes[$postType] = true;
        }

        $translations = [];

        foreach (array_keys($postTypes) as $postType) {
            $enabled = $this->isPostTypeSlugTranslationEnabled($postType, $slugSettings);
            $values = $this->exportPostTypeSlugValues($postType);

            if (! $enabled && $values === []) {
                continue;
            }

            $translations[$postType] = [
                'enabled' => $enabled,
                'values' => $values,
            ];
        }

        ksort($translations);

        return $translations;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSettings(): array
    {
        $settings = maybe_unserialize(get_option(self::SETTINGS_OPTION, []));

        return is_array($settings) ? $settings : [];
    }

    /**
     * @param mixed $rawTranslations
     * @return array<string, array{enabled: bool, values: array<string, string>}>
     */
    private function normalizePostTypeSlugTranslations(mixed $rawTranslations): array
    {
        if (! is_array($rawTranslations)) {
            return [];
        }

        $normalized = [];

        foreach ($rawTranslations as $postType => $definition) {
            $postType = sanitize_key((string) $postType);

            if ($postType === '' || ! is_array($definition)) {
                continue;
            }

            $values = [];

            foreach (($definition['values'] ?? []) as $language => $value) {
                $language = trim((string) $language);
                $value = trim((string) $value);

                if ($language === '' || $value === '') {
                    continue;
                }

                $values[$language] = $value;
            }

            ksort($values);

            $normalized[$postType] = [
                'enabled' => (bool) ($definition['enabled'] ?? false),
                'values' => $values,
            ];
        }

        ksort($normalized);

        return $normalized;
    }

    private function hasRegisteredSlugTranslationRecord(string $postType): bool
    {
        $cacheKey = 'wpml_slug_translation_record_' . $postType;
        $cached = wp_cache_get($cacheKey, 'pushpull');

        if ($cached !== false) {
            return (bool) $cached;
        }
        $hasRecord = $this->readSlugTranslationRecord($postType) !== null;
        wp_cache_set($cacheKey, $hasRecord, 'pushpull');

        return $hasRecord;
    }

    /**
     * @return string[]
     */
    private function registeredSlugTranslationRecordPostTypes(): array
    {
        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return [];
        }

        $rows = wp_cache_get('registered_slug_translation_record_post_types', self::CACHE_GROUP);

        if ($rows === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML slug records live in plugin tables and are cached at the method boundary.
            $rows = $wpdb->get_results(
                "SELECT name, context
                 FROM {$wpdb->prefix}icl_strings",
                ARRAY_A
            );
            wp_cache_set('registered_slug_translation_record_post_types', is_array($rows) ? $rows : [], self::CACHE_GROUP);
        }

        if (! is_array($rows)) {
            return [];
        }

        $postTypes = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $context = (string) ($row['context'] ?? '');
            $name = (string) ($row['name'] ?? '');

            if (! in_array($context, ['WordPress', 'default'], true)) {
                continue;
            }

            if (! str_starts_with($name, 'URL slug: ')) {
                continue;
            }

            $postType = sanitize_key(substr($name, strlen('URL slug: ')));

            if ($postType !== '') {
                $postTypes[$postType] = true;
            }
        }

        $postTypes = array_keys($postTypes);
        sort($postTypes);

        return $postTypes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSlugTranslationRecord(string $postType): ?array
    {
        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return null;
        }

        $stringName = sprintf('URL slug: %s', $postType);
        $contexts = ['WordPress', 'default'];

        foreach ($contexts as $context) {
            $cacheKey = 'slug_translation_record_' . md5($postType . '|' . $context);
            $record = wp_cache_get($cacheKey, self::CACHE_GROUP);

            if ($record === false) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML slug records live in plugin tables and are cached at the method boundary.
                $record = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, value, language, context, name
                     FROM {$wpdb->prefix}icl_strings
                     WHERE name = %s
                       AND context = %s
                     LIMIT 1",
                    $stringName,
                    $context
                ), ARRAY_A);
                wp_cache_set($cacheKey, is_array($record) ? $record : null, self::CACHE_GROUP);
            }

            if (is_array($record) && isset($record['id'])) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readSlugTranslationRows(int $stringId): array
    {
        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb || $stringId <= 0) {
            return [];
        }

        $cacheKey = 'slug_translation_rows_' . $stringId;
        $rows = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if ($rows === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML slug translation rows live in plugin tables and are cached at the method boundary.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT language, value, status
                 FROM {$wpdb->prefix}icl_string_translations
                 WHERE string_id = %d",
                $stringId
            ), ARRAY_A);
            wp_cache_set($cacheKey, is_array($rows) ? $rows : [], self::CACHE_GROUP);
        }

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $slugSettings
     */
    private function isPostTypeSlugTranslationEnabled(string $postType, array $slugSettings): bool
    {
        if (
            isset($GLOBALS['sitepress'])
            && is_object($GLOBALS['sitepress'])
            && is_callable([$GLOBALS['sitepress'], 'cpt_slug_translation_turned_on'])
        ) {
            /** @var mixed $enabled */
            $enabled = $GLOBALS['sitepress']->cpt_slug_translation_turned_on($postType);

            return (bool) $enabled;
        }

        $configuredTypes = is_array($slugSettings['types'] ?? null) ? $slugSettings['types'] : [];
        $globallyEnabled = (bool) get_option('wpml_base_slug_translation', false)
            || ! empty($slugSettings['on']);

        if (! empty($configuredTypes[$postType]) && $globallyEnabled) {
            return true;
        }

        return $this->hasRegisteredSlugTranslationRecord($postType);
    }

    /**
     * @return array<string, string>
     */
    private function exportPostTypeSlugValues(string $postType): array
    {
        $record = $this->readSlugTranslationRecord($postType);

        if ($record === null) {
            return [];
        }

        $values = [];
        $originalLanguage = (string) ($record['language'] ?? '');
        $originalValue = (string) ($record['value'] ?? '');

        if ($originalLanguage !== '' && $originalValue !== '') {
            $values[$originalLanguage] = $originalValue;
        }

        foreach ($this->readSlugTranslationRows((int) $record['id']) as $row) {
            $language = (string) ($row['language'] ?? '');
            $value = (string) ($row['value'] ?? '');

            if ($language === '' || $value === '') {
                continue;
            }

            $values[$language] = $value;
        }

        return $values;
    }

    /**
     * @param mixed $sitepress
     */
    private function createInstallation(object $sitepress): object
    {
        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            throw new RuntimeException('wpdb is not available for WPML configuration apply.');
        }

        return new \WPML_Installation($wpdb, $sitepress);
    }

    private function requireSitePress(): object
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('WPML configuration is not available on this site.');
        }

        return $GLOBALS['sitepress'];
    }

    /**
     * @param mixed $activeLanguages
     * @return string[]
     */
    private function normalizeActiveLanguages(mixed $activeLanguages, string $defaultLanguage): array
    {
        if (! is_array($activeLanguages)) {
            $activeLanguages = [];
        }

        $normalized = [];

        foreach ($activeLanguages as $languageCode) {
            $languageCode = trim((string) $languageCode);

            if ($languageCode !== '') {
                $normalized[$languageCode] = true;
            }
        }

        if ($defaultLanguage !== '') {
            $normalized[$defaultLanguage] = true;
        }

        $languageCodes = array_keys($normalized);
        sort($languageCodes);

        if ($defaultLanguage !== '' && in_array($defaultLanguage, $languageCodes, true)) {
            $languageCodes = array_values(array_diff($languageCodes, [$defaultLanguage]));
            array_unshift($languageCodes, $defaultLanguage);
        }

        return $languageCodes;
    }

    private function normalizeUrlFormat(string $urlFormat): string
    {
        return match ($urlFormat) {
            self::URL_FORMAT_DIRECTORY,
            self::URL_FORMAT_DOMAIN,
            self::URL_FORMAT_PARAMETER => $urlFormat,
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
            default => throw new RuntimeException(sprintf('Unsupported WPML URL format "%s".', $urlFormat)),
        };
    }

    /**
     * @param mixed $rawModes
     * @return array<string, string>
     */
    private function normalizePostTypeTranslationModes(mixed $rawModes): array
    {
        if (! is_array($rawModes)) {
            return [];
        }

        $normalized = [];

        foreach ($rawModes as $postType => $mode) {
            $postType = sanitize_key((string) $postType);

            if ($postType === '') {
                continue;
            }

            $mode = (string) $mode;
            $normalized[$postType] = match ($mode) {
                self::POST_TYPE_MODE_TRANSLATABLE_ONLY,
                self::POST_TYPE_MODE_TRANSLATABLE_FALLBACK,
                self::POST_TYPE_MODE_NOT_TRANSLATABLE => $mode,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
                default => throw new RuntimeException(sprintf('Unsupported WPML post type translation mode "%s" for "%s".', $mode, $postType)),
            };
        }

        ksort($normalized);

        return $normalized;
    }

    private function urlFormatAsString(int $mode): string
    {
        return match ($mode) {
            2 => self::URL_FORMAT_DOMAIN,
            3 => self::URL_FORMAT_PARAMETER,
            default => self::URL_FORMAT_DIRECTORY,
        };
    }

    private function postTypeModeAsString(int $mode): string
    {
        return match ($mode) {
            1 => self::POST_TYPE_MODE_TRANSLATABLE_ONLY,
            2 => self::POST_TYPE_MODE_TRANSLATABLE_FALLBACK,
            default => self::POST_TYPE_MODE_NOT_TRANSLATABLE,
        };
    }

    /**
     * @param mixed $sitepress
     */
    private function saveUrlFormat(string $urlFormat, object $sitepress): void
    {
        if (class_exists('\WPML\Core\LanguageNegotiation') && is_callable(['\WPML\Core\LanguageNegotiation', 'saveMode'])) {
            \WPML\Core\LanguageNegotiation::saveMode($urlFormat);

            return;
        }

        if (! method_exists($sitepress, 'set_setting')) {
            throw new RuntimeException('WPML SitePress instance does not expose set_setting() for URL format changes.');
        }

        $mode = match ($urlFormat) {
            self::URL_FORMAT_DIRECTORY => 1,
            self::URL_FORMAT_DOMAIN => 2,
            self::URL_FORMAT_PARAMETER => 3,
        };

        $sitepress->set_setting('language_negotiation_type', $mode, true);
    }

    /**
     * @param array<string, string> $postTypeTranslationModes
     * @param mixed $sitepress
     */
    private function applyPostTypeTranslationModes(array $postTypeTranslationModes, object $sitepress): void
    {
        if (! method_exists($sitepress, 'set_setting')) {
            throw new RuntimeException('WPML SitePress instance does not expose set_setting() for post type translation modes.');
        }

        $currentSettings = $this->currentSettings();
        $syncOptions = is_array($currentSettings['custom_posts_sync_option'] ?? null)
            ? $currentSettings['custom_posts_sync_option']
            : [];

        foreach ($postTypeTranslationModes as $postType => $mode) {
            $wpmlMode = match ($mode) {
                self::POST_TYPE_MODE_TRANSLATABLE_ONLY => 1,
                self::POST_TYPE_MODE_TRANSLATABLE_FALLBACK => 2,
                self::POST_TYPE_MODE_NOT_TRANSLATABLE => 0,
            };

            if ($wpmlMode === 0) {
                unset($syncOptions[$postType]);
            } else {
                $syncOptions[$postType] = $wpmlMode;
            }

            if (method_exists($sitepress, 'verify_post_translations')) {
                $sitepress->verify_post_translations($postType);
            }
        }

        $sitepress->set_setting('custom_posts_sync_option', $syncOptions, true);
    }

    /**
     * @param mixed $sitepress
     */
    /**
     * @param array<string, array{enabled: bool, values: array<string, string>}> $postTypeSlugTranslations
     * @param mixed $sitepress
     */
    private function applyPostTypeSlugTranslations(array $postTypeSlugTranslations, string $defaultLanguage, object $sitepress): void
    {
        if (! method_exists($sitepress, 'set_setting')) {
            throw new RuntimeException('WPML SitePress instance does not expose set_setting() for slug translation.');
        }

        $currentSettings = $this->currentSettings();
        $slugSettings = is_array($currentSettings['posts_slug_translation'] ?? null)
            ? $currentSettings['posts_slug_translation']
            : [];
        $slugSettings['types'] = is_array($slugSettings['types'] ?? null) ? $slugSettings['types'] : [];
        $hasEnabledTypes = false;

        foreach ($postTypeSlugTranslations as $postType => $definition) {
            if ($definition['enabled']) {
                $slugSettings['types'][$postType] = 1;
                $hasEnabledTypes = true;
            } else {
                unset($slugSettings['types'][$postType]);
            }

            $this->applyPostTypeSlugValues($postType, $definition['enabled'], $definition['values'], $defaultLanguage);
        }

        if (($slugSettings['types'] ?? []) !== []) {
            $hasEnabledTypes = true;
        }

        if ($hasEnabledTypes) {
            $slugSettings['on'] = 1;
            update_option('wpml_base_slug_translation', 1, false);
        } else {
            unset($slugSettings['on']);
            update_option('wpml_base_slug_translation', 0, false);
        }

        $sitepress->set_setting('posts_slug_translation', $slugSettings, true);
    }

    /**
     * @param array<string, string> $postTypeSlugValues
     */
    private function applyPostTypeSlugValues(string $postType, bool $enabled, array $postTypeSlugValues, string $defaultLanguage): void
    {
        if (! $enabled || $postTypeSlugValues === []) {
            return;
        }

        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            throw new RuntimeException('wpdb is not available for WPML slug translation apply.');
        }

        $originalLanguage = isset($postTypeSlugValues[$defaultLanguage])
            ? $defaultLanguage
            : (string) array_key_first($postTypeSlugValues);

        $originalValue = $postTypeSlugValues[$originalLanguage] ?? '';

        if ($originalLanguage === '' || $originalValue === '') {
            return;
        }

        $record = $this->readSlugTranslationRecord($postType);

        if ($record === null) {
            $record = $this->insertSlugTranslationRecord($postType, $originalLanguage, $originalValue);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating the authoritative WPML string record requires touching WPML plugin tables directly and the affected caches are invalidated immediately after the write.
            $wpdb->update(
                $wpdb->prefix . 'icl_strings',
                [
                    'language' => $originalLanguage,
                    'value' => $originalValue,
                    'status' => defined('ICL_TM_COMPLETE') ? constant('ICL_TM_COMPLETE') : 10,
                ],
                ['id' => (int) $record['id']]
            );
            $this->flushSlugTranslationCaches($postType, (int) $record['id']);
            $record = $this->readSlugTranslationRecord($postType);

            if ($record === null) {
                $record = $this->insertSlugTranslationRecord($postType, $originalLanguage, $originalValue);
            }
        }

        if ($record === null) {
            return;
        }

        $stringId = (int) $record['id'];
        $completeStatus = defined('ICL_TM_COMPLETE') ? constant('ICL_TM_COMPLETE') : 10;

        foreach ($postTypeSlugValues as $language => $value) {
            if ($language === $originalLanguage || $value === '') {
                continue;
            }

            $existing = $this->readSlugTranslationRowByLanguage($stringId, $language);

            if (is_array($existing) && isset($existing['id'])) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating WPML translation rows requires touching WPML plugin tables directly and the affected caches are invalidated immediately after the write.
                $wpdb->update(
                    $wpdb->prefix . 'icl_string_translations',
                    [
                        'value' => $value,
                        'status' => $completeStatus,
                    ],
                    ['id' => (int) $existing['id']]
                );
                $this->flushSlugTranslationCaches($postType, $stringId, $language);
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserting WPML translation rows requires touching WPML plugin tables directly.
            $wpdb->insert($wpdb->prefix . 'icl_string_translations', [
                'string_id' => $stringId,
                'language' => $language,
                'value' => $value,
                'status' => $completeStatus,
            ]);
            $this->flushSlugTranslationCaches($postType, $stringId, $language);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function insertSlugTranslationRecord(string $postType, string $language, string $value): ?array
    {
        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writing the authoritative WPML string record requires touching WPML plugin tables directly.
        $wpdb->insert($wpdb->prefix . 'icl_strings', [
            'language' => $language,
            'context' => 'WordPress',
            'name' => sprintf('URL slug: %s', $postType),
            'value' => $value,
            'status' => defined('ICL_TM_COMPLETE') ? constant('ICL_TM_COMPLETE') : 10,
        ]);
        $this->flushSlugTranslationCaches($postType);

        return $this->readSlugTranslationRecord($postType);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSlugTranslationRowByLanguage(int $stringId, string $language): ?array
    {
        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb || $stringId <= 0 || $language === '') {
            return null;
        }

        $cacheKey = 'slug_translation_row_' . $stringId . '_' . $language;
        $row = wp_cache_get($cacheKey, self::CACHE_GROUP);

        if ($row === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML slug translation rows live in plugin tables and are cached at the method boundary.
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, string_id, language, value, status
                 FROM {$wpdb->prefix}icl_string_translations
                 WHERE string_id = %d
                   AND language = %s
                 LIMIT 1",
                $stringId,
                $language
            ), ARRAY_A);
            wp_cache_set($cacheKey, is_array($row) ? $row : null, self::CACHE_GROUP);
        }

        return is_array($row) ? $row : null;
    }

    private function flushSlugTranslationCaches(string $postType, ?int $stringId = null, ?string $language = null): void
    {
        wp_cache_delete('wpml_slug_translation_record_' . $postType, self::CACHE_GROUP);
        wp_cache_delete('registered_slug_translation_record_post_types', self::CACHE_GROUP);

        foreach (['WordPress', 'default'] as $context) {
            wp_cache_delete('slug_translation_record_' . md5($postType . '|' . $context), self::CACHE_GROUP);
        }

        if ($stringId !== null) {
            wp_cache_delete('slug_translation_rows_' . $stringId, self::CACHE_GROUP);
        }

        if ($stringId !== null && $language !== null) {
            wp_cache_delete('slug_translation_row_' . $stringId . '_' . $language, self::CACHE_GROUP);
        }
    }
}
