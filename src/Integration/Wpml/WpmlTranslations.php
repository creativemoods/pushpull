<?php

declare(strict_types=1);

namespace PushPull\Integration\Wpml;

final class WpmlTranslations
{
    public static function postLanguage(string $postType, int $postId): string
    {
        $row = self::findTranslationRow(self::postElementType($postType), $postId);

        return $row !== null ? (string) ($row['language_code'] ?? '') : '';
    }

    public static function persistPostLanguage(string $postType, int $postId, string $language): void
    {
        $language = trim($language);

        if ($postId <= 0 || $language === '') {
            return;
        }

        $elementType = self::postElementType($postType);
        $existingRow = self::findTranslationRow($elementType, $postId);
        $row = [
            'translation_id' => (int) ($existingRow['translation_id'] ?? self::nextTranslationId()),
            'element_type' => $elementType,
            'element_id' => $postId,
            'trid' => (int) ($existingRow['trid'] ?? self::nextTrid()),
            'language_code' => $language,
            'source_language_code' => $existingRow['source_language_code'] ?? null,
        ];

        self::persistTranslationRow($row);
    }

    private static function postElementType(string $postType): string
    {
        return 'post_' . $postType;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findTranslationRow(string $elementType, int $elementId): ?array
    {
        foreach (self::translationRows() as $row) {
            if ((string) ($row['element_type'] ?? '') === $elementType && (int) ($row['element_id'] ?? 0) === $elementId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function translationRows(): array
    {
        if (isset($GLOBALS['pushpull_test_wpml_translations']) && is_array($GLOBALS['pushpull_test_wpml_translations'])) {
            return array_values($GLOBALS['pushpull_test_wpml_translations']);
        }

        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return [];
        }

        $table = $wpdb->prefix . 'icl_translations';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal WPML table name derived from the trusted wpdb prefix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Small helper used during deterministic export/apply.
        $rows = $wpdb->get_results("SELECT translation_id, element_type, element_id, trid, language_code, source_language_code FROM {$table}", ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function persistTranslationRow(array $row): void
    {
        if (isset($GLOBALS['pushpull_test_wpml_translations']) && is_array($GLOBALS['pushpull_test_wpml_translations'])) {
            $replaced = false;

            foreach ($GLOBALS['pushpull_test_wpml_translations'] as $index => $existingRow) {
                if ((int) ($existingRow['translation_id'] ?? 0) !== (int) $row['translation_id']) {
                    continue;
                }

                $GLOBALS['pushpull_test_wpml_translations'][$index] = $row;
                $replaced = true;
                break;
            }

            if (! $replaced) {
                $GLOBALS['pushpull_test_wpml_translations'][] = $row;
            }

            return;
        }

        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return;
        }

        $table = $wpdb->prefix . 'icl_translations';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing WPML-managed translation rows is intentional here.
        $wpdb->replace($table, $row, ['%d', '%s', '%d', '%d', '%s', '%s']);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    private static function nextTrid(): int
    {
        $max = 0;

        foreach (self::translationRows() as $row) {
            $max = max($max, (int) ($row['trid'] ?? 0));
        }

        return $max + 1;
    }

    private static function nextTranslationId(): int
    {
        $max = 0;

        foreach (self::translationRows() as $row) {
            $max = max($max, (int) ($row['translation_id'] ?? 0));
        }

        return $max + 1;
    }
}
