<?php

declare(strict_types=1);

namespace PushPull\Content\Translation;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\ManagedSetDependencyAwareInterface;
use PushPull\Content\ManifestManagedContentAdapterInterface;
use PushPull\Content\OverlayManagedContentAdapterInterface;
use PushPull\Content\OverlayManagedSetInterface;
use PushPull\Content\WordPress\WordPressMenusAdapter;
use PushPull\Integration\Wpml\WpmlConfigurationApplier;
use PushPull\Support\Json\CanonicalJson;
use PushPull\Support\Urls\EnvironmentUrlCanonicalizer;
use PushPull\Settings\SettingsRepository;
use RuntimeException;
use WP_Post;
use WP_Term;

final class WpmlTranslationManagementAdapter implements OverlayManagedContentAdapterInterface, ManagedSetDependencyAwareInterface, OverlayManagedSetInterface
{
    private const MANAGED_SET_KEY = 'translation_management';
    private const CONTENT_TYPE = 'translation_group';
    private const MANIFEST_TYPE = 'translation_management_manifest';
    private const PATH_PREFIX = 'translations/management';
    private const WPML_SETTINGS_OPTION = 'icl_sitepress_settings';
    private const CACHE_GROUP = 'pushpull_wpml';
    private const CACHE_KEY_TRANSLATION_ROWS = 'translation_rows';
    private const CACHE_KEY_HAS_TRANSLATION_STORAGE = 'has_translation_storage';
    /**
     * @var array<string, array{entityType: string, postType?: string, taxonomy?: string, contentType: string}>
     */
    private const SUPPORTED_DOMAINS = [
        'wordpress_pages' => ['entityType' => 'post', 'postType' => 'page', 'contentType' => 'wordpress_page'],
        'wordpress_posts' => ['entityType' => 'post', 'postType' => 'post', 'contentType' => 'wordpress_post'],
        'wordpress_block_patterns' => ['entityType' => 'post', 'postType' => 'wp_block', 'contentType' => 'wordpress_block_pattern'],
        'generatepress_elements' => ['entityType' => 'post', 'postType' => 'gp_elements', 'contentType' => 'generatepress_element'],
        'wordpress_menus' => ['entityType' => 'taxonomy', 'taxonomy' => 'nav_menu', 'contentType' => 'wordpress_menu'],
    ];

    public function __construct(private readonly SettingsRepository $settingsRepository)
    {
    }

    public function getManagedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    public function getManagedSetLabel(): string
    {
        return 'Translation management';
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    public function isOverlayManagedSet(): bool
    {
        return true;
    }

    /**
     * @return string[]
     */
    public function getManagedSetDependencies(): array
    {
        return array_merge(['wpml_configuration'], array_keys(self::SUPPORTED_DOMAINS));
    }

    public function isAvailable(): bool
    {
        return (new WpmlConfigurationApplier())->isAvailable();
    }

    public function exportAll(): array
    {
        return $this->exportSnapshot()->items;
    }

    public function exportSnapshot(): WpmlTranslationManagementSnapshot
    {
        $items = [];
        $files = [];
        $orderedLogicalKeys = [];

        foreach ($this->translationGroupsInScope() as $group) {
            $item = $this->buildItemFromGroup($group);
            $items[] = $item;
            $orderedLogicalKeys[] = $item->logicalKey;
            $files[$this->getRepositoryPath($item)] = $this->serialize($item);
        }

        sort($orderedLogicalKeys);
        usort($items, static fn (ManagedContentItem $left, ManagedContentItem $right): int => $left->logicalKey <=> $right->logicalKey);
        $manifest = new ManagedCollectionManifest(self::MANAGED_SET_KEY, self::MANIFEST_TYPE, $orderedLogicalKeys);
        $files[$this->getManifestPath()] = $this->serializeManifest($manifest);
        ksort($files);

        return new WpmlTranslationManagementSnapshot($items, $manifest, $files, $orderedLogicalKeys);
    }

    /**
     * @param array<string, string> $files
     */
    public function readSnapshotFromRepositoryFiles(array $files): WpmlTranslationManagementSnapshot
    {
        $manifestContent = $files[$this->getManifestPath()] ?? null;

        if ($manifestContent === null) {
            throw new ManagedContentExportException('Managed set manifest is missing from the local branch.');
        }

        $manifest = $this->parseManifest($manifestContent);
        $items = [];

        foreach ($files as $path => $content) {
            if ($path === $this->getManifestPath() || ! $this->ownsRepositoryPath($path) || ! str_ends_with($path, '.json')) {
                continue;
            }

            $item = $this->deserialize($path, $content);
            $items[$item->logicalKey] = $item;
        }

        ksort($items);
        $this->validateManifest($manifest, array_values($items));

        return new WpmlTranslationManagementSnapshot(array_values($items), $manifest, $files, $manifest->orderedLogicalKeys);
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        foreach ($this->translationGroupsInScope() as $group) {
            $item = $this->buildItemFromGroup($group);

            if ($item->logicalKey === $logicalKey) {
                return $item;
            }
        }

        return null;
    }

    public function computeLogicalKey(array $wpRecord): string
    {
        $contentDomain = (string) ($wpRecord['contentDomain'] ?? '');
        $groupKey = (string) ($wpRecord['groupKey'] ?? '');

        if ($contentDomain === '' || $groupKey === '') {
            throw new ManagedContentExportException('Translation group logical key requires contentDomain and groupKey.');
        }

        return $contentDomain . ':' . $groupKey;
    }

    public function getRepositoryPath(ManagedContentItem $item): string
    {
        return sprintf('%s/%s.json', self::PATH_PREFIX, $item->logicalKey);
    }

    public function getManifestPath(): string
    {
        return self::PATH_PREFIX . '/manifest.json';
    }

    public function ownsRepositoryPath(string $path): bool
    {
        return $path === $this->getManifestPath()
            || (str_starts_with($path, self::PATH_PREFIX . '/') && str_ends_with($path, '.json'));
    }

    public function serialize(ManagedContentItem $item): string
    {
        $this->validateItem($item);

        return CanonicalJson::encode($item->toArray());
    }

    public function deserialize(string $path, string $content): ManagedContentItem
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new ManagedContentExportException(sprintf('Invalid managed content JSON at %s.', $path));
        }

        $payload = $decoded['payload'] ?? [];
        $metadata = $decoded['metadata'] ?? null;
        $derived = $decoded['derived'] ?? null;

        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            (string) ($decoded['type'] ?? self::CONTENT_TYPE),
            (string) ($decoded['logicalKey'] ?? ''),
            (string) ($decoded['displayName'] ?? ''),
            (string) ($decoded['selector'] ?? ''),
            (string) ($decoded['slug'] ?? ''),
            is_array($payload) ? EnvironmentUrlCanonicalizer::denormalizeValue($payload) : [],
            (string) ($decoded['postStatus'] ?? 'publish'),
            is_array($metadata) ? EnvironmentUrlCanonicalizer::denormalizeValue($metadata) : [],
            is_array($derived) ? EnvironmentUrlCanonicalizer::denormalizeValue($derived) : [],
            null,
            (int) ($decoded['schemaVersion'] ?? 1),
            (int) ($decoded['adapterVersion'] ?? 1)
        );
    }

    public function serializeManifest(ManagedCollectionManifest $manifest): string
    {
        return CanonicalJson::encode($manifest->toArray());
    }

    public function hashItem(ManagedContentItem $item): string
    {
        return sha1($this->serialize($item));
    }

    public function hashManifest(ManagedCollectionManifest $manifest): string
    {
        return sha1($this->serializeManifest($manifest));
    }

    /**
     * @param ManagedContentItem[] $items
     */
    public function validateManifest(ManagedCollectionManifest $manifest, array $items): void
    {
        if ($manifest->manifestType !== self::MANIFEST_TYPE) {
            throw new ManagedContentExportException('Invalid translation management manifest type.');
        }

        $knownKeys = [];

        foreach ($items as $item) {
            $knownKeys[$item->logicalKey] = true;
        }

        foreach ($manifest->orderedLogicalKeys as $logicalKey) {
            if (! isset($knownKeys[$logicalKey])) {
                throw new ManagedContentExportException(sprintf('Manifest references unknown logical key: %s', $logicalKey));
            }
        }
    }

    public function parseManifest(string $content): ManagedCollectionManifest
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded) || ! is_array($decoded['orderedLogicalKeys'] ?? null)) {
            throw new ManagedContentExportException('Managed set manifest is invalid.');
        }

        return new ManagedCollectionManifest(
            self::MANAGED_SET_KEY,
            (string) ($decoded['type'] ?? self::MANIFEST_TYPE),
            $decoded['orderedLogicalKeys'],
            (int) ($decoded['schemaVersion'] ?? 1)
        );
    }

    public function buildCommitMessage(): string
    {
        return 'Commit live translation management';
    }

    public function validateItem(ManagedContentItem $item): void
    {
        if ($item->logicalKey === '') {
            throw new ManagedContentExportException('Translation group logical key cannot be empty.');
        }

        if (! isset($item->payload['contentDomain'], $item->payload['translations']) || ! is_array($item->payload['translations'])) {
            throw new ManagedContentExportException(sprintf('Translation group "%s" is missing its translations payload.', $item->logicalKey));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function currentTranslationGroups(): array
    {
        return $this->translationGroupsInScope();
    }

    public function applyGroup(ManagedContentItem $item): bool
    {
        $this->validateItem($item);
        $existingGroups = [];

        foreach ($this->translationGroupsInScope() as $group) {
            $existingGroups[$group['logicalKey']] = $group;
        }

        $existingGroup = $existingGroups[$item->logicalKey] ?? null;
        $trid = $existingGroup !== null ? (int) $existingGroup['trid'] : $this->nextTrid();
        $translations = $this->normalizeTranslations($item->payload['translations'] ?? []);
        $activeLanguages = $this->activeLanguages();

        foreach ($translations as $translation) {
            if ($activeLanguages !== [] && ! isset($activeLanguages[$translation['language']])) {
                throw new RuntimeException(sprintf(
                    'Translation group references inactive WPML language "%s".',
                    $translation['language']
                ));
            }

            $scope = self::SUPPORTED_DOMAINS[$translation['contentDomain']] ?? null;

            if ($scope === null) {
                throw new RuntimeException(sprintf('Unsupported translation content domain "%s".', $translation['contentDomain']));
            }

            if (($scope['entityType'] ?? '') === 'taxonomy') {
                $taxonomy = (string) ($scope['taxonomy'] ?? '');
                $targetTerm = $this->findTermByLogicalKey($taxonomy, $translation['contentLogicalKey']);

                if (! $targetTerm instanceof WP_Term) {
                    throw new RuntimeException(sprintf(
                        'Translation group references missing %s "%s".',
                        $taxonomy,
                        $translation['contentLogicalKey']
                    ));
                }

                $elementType = 'tax_' . $taxonomy;
                $elementId = (int) $targetTerm->term_id;
            } else {
                $postType = (string) ($scope['postType'] ?? '');
                $targetPost = $this->findPostByLogicalKey($postType, $translation['contentLogicalKey']);

                if (! $targetPost instanceof WP_Post) {
                    throw new RuntimeException(sprintf(
                        'Translation group references missing %s "%s".',
                        $postType,
                        $translation['contentLogicalKey']
                    ));
                }

                $elementType = 'post_' . $postType;
                $elementId = (int) $targetPost->ID;
            }

            $existingRow = $this->findTranslationRow($elementType, $elementId);
            $row = [
                'translation_id' => (int) ($existingRow['translation_id'] ?? $this->nextTranslationId()),
                'element_type' => $elementType,
                'element_id' => $elementId,
                'trid' => $trid,
                'language_code' => $translation['language'],
                'source_language_code' => $translation['sourceLanguage'],
            ];
            $this->persistTranslationRow($row);
        }

        return $existingGroup === null;
    }

    /**
     * @param array<string, true> $desiredLogicalKeys
     * @return string[]
     */
    public function deleteMissingGroups(array $desiredLogicalKeys): array
    {
        return [];
    }

    public function applyOverlayItem(ManagedContentItem $item): bool
    {
        return $this->applyGroup($item);
    }

    public function deleteMissingOverlayItems(array $desiredLogicalKeys): array
    {
        return $this->deleteMissingGroups($desiredLogicalKeys);
    }

    /**
     * @param array<string, mixed> $group
     */
    private function buildItemFromGroup(array $group): ManagedContentItem
    {
        $logicalKey = (string) $group['logicalKey'];
        $contentDomain = (string) $group['contentDomain'];
        $contentType = (string) $group['contentType'];
        $groupKey = (string) $group['groupKey'];

        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE,
            $logicalKey,
            sprintf('Translations: %s', $groupKey),
            $logicalKey,
            $logicalKey,
            [
                'contentDomain' => $contentDomain,
                'contentType' => $contentType,
                'groupKey' => $groupKey,
                'defaultLanguage' => (string) $group['defaultLanguage'],
                'translations' => $this->normalizeTranslations($group['translations']),
            ],
            'publish',
            [
                'backend' => [
                    'provider' => 'wpml',
                ],
            ],
            []
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function translationGroupsInScope(): array
    {
        $scopeMap = $this->scopeMap();
        $rowsByTrid = [];

        foreach ($this->translationRows() as $row) {
            $elementType = (string) ($row['element_type'] ?? '');
            $elementId = (int) ($row['element_id'] ?? 0);
            $scope = $scopeMap[$elementType][$elementId] ?? null;

            if (! is_array($scope)) {
                continue;
            }

            $trid = (int) ($row['trid'] ?? 0);

            if ($trid <= 0) {
                continue;
            }

            $row['contentDomain'] = $scope['managedSetKey'];
            $row['contentType'] = $scope['contentType'];
            $row['contentLogicalKey'] = $scope['logicalKey'];
            $rowsByTrid[$trid][] = $row;
        }

        $groups = [];

        foreach ($rowsByTrid as $trid => $rows) {
            usort(
                $rows,
                static fn (array $left, array $right): int => [(string) $left['language_code'], (string) $left['contentLogicalKey']] <=> [(string) $right['language_code'], (string) $right['contentLogicalKey']]
            );
            $sourceRow = null;

            foreach ($rows as $row) {
                if (($row['source_language_code'] ?? null) === null || $row['source_language_code'] === '') {
                    $sourceRow = $row;
                    break;
                }
            }

            $sourceRow ??= $rows[0];
            $groupKey = (string) $sourceRow['contentLogicalKey'];
            $contentDomain = (string) $sourceRow['contentDomain'];
            $contentType = (string) $sourceRow['contentType'];

            $groups[] = [
                'trid' => $trid,
                'logicalKey' => $this->computeLogicalKey([
                    'contentDomain' => $contentDomain,
                    'groupKey' => $groupKey,
                ]),
                'contentDomain' => $contentDomain,
                'contentType' => $contentType,
                'groupKey' => $groupKey,
                'defaultLanguage' => (string) ($sourceRow['language_code'] ?? ''),
                'translations' => array_map(
                    static fn (array $row): array => [
                        'language' => (string) ($row['language_code'] ?? ''),
                        'contentDomain' => (string) ($row['contentDomain'] ?? ''),
                        'contentType' => (string) ($row['contentType'] ?? ''),
                        'contentLogicalKey' => (string) ($row['contentLogicalKey'] ?? ''),
                        'sourceLanguage' => ($row['source_language_code'] ?? null) !== '' ? ($row['source_language_code'] ?? null) : null,
                    ],
                    $rows
                ),
                'rows' => $rows,
            ];
        }

        usort($groups, static fn (array $left, array $right): int => $left['logicalKey'] <=> $right['logicalKey']);

        return $groups;
    }

    /**
     * @return array<string, array<int, array{managedSetKey: string, contentType: string, logicalKey: string}>>
     */
    private function scopeMap(): array
    {
        $settings = $this->settingsRepository->get();
        $translatablePostTypes = $this->wpmlTranslatablePostTypes();
        $translatableTaxonomies = $this->wpmlTranslatableTaxonomies();
        $scopeMap = [];

        foreach (self::SUPPORTED_DOMAINS as $managedSetKey => $scope) {
            if (! $settings->isManagedSetEnabled($managedSetKey)) {
                continue;
            }

            if (($scope['entityType'] ?? '') === 'taxonomy') {
                $taxonomy = (string) ($scope['taxonomy'] ?? '');

                if (! isset($translatableTaxonomies[$taxonomy]) && ! $this->hasTranslationElementType('tax_' . $taxonomy)) {
                    continue;
                }

                $elementType = 'tax_' . $taxonomy;
                $scopeMap[$elementType] = [];

                foreach ($this->termsByTaxonomy($taxonomy) as $term) {
                    $scopeMap[$elementType][$term->term_id] = [
                        'managedSetKey' => $managedSetKey,
                        'contentType' => (string) $scope['contentType'],
                        'logicalKey' => $this->computeTermLogicalKey($term),
                    ];
                }

                continue;
            }

            $postType = (string) ($scope['postType'] ?? '');

            if (! isset($translatablePostTypes[$postType])) {
                continue;
            }

            $elementType = 'post_' . $postType;
            $scopeMap[$elementType] = [];

            foreach ($this->postsByType($postType) as $post) {
                $scopeMap[$elementType][$post->ID] = [
                    'managedSetKey' => $managedSetKey,
                    'contentType' => (string) $scope['contentType'],
                    'logicalKey' => $this->computePostLogicalKey($post),
                ];
            }
        }

        return $scopeMap;
    }

    /**
     * @return array<string, true>
     */
    private function wpmlTranslatablePostTypes(): array
    {
        $settings = $this->wpmlSettings();
        $modes = $settings['custom_posts_sync_option'] ?? [];

        if (! is_array($modes)) {
            return [];
        }

        $enabled = [];

        foreach ($modes as $postType => $mode) {
            if ((string) $mode !== '0') {
                $enabled[(string) $postType] = true;
            }
        }

        return $enabled;
    }

    /**
     * @return array<string, true>
     */
    private function wpmlTranslatableTaxonomies(): array
    {
        $settings = $this->wpmlSettings();
        $modes = $settings['taxonomies_sync_option'] ?? [];

        if (! is_array($modes)) {
            return [];
        }

        $enabled = [];

        foreach ($modes as $taxonomy => $mode) {
            if ((string) $mode !== '0') {
                $enabled[(string) $taxonomy] = true;
            }
        }

        return $enabled;
    }

    /**
     * @return array<string, true>
     */
    private function activeLanguages(): array
    {
        $settings = $this->wpmlSettings();
        $languages = $settings['active_languages'] ?? [];

        if (! is_array($languages)) {
            return [];
        }

        $active = [];

        foreach ($languages as $languageCode) {
            $languageCode = (string) $languageCode;

            if ($languageCode !== '') {
                $active[$languageCode] = true;
            }
        }

        return $active;
    }

    /**
     * @return array<string, mixed>
     */
    private function wpmlSettings(): array
    {
        $settings = maybe_unserialize(get_option(self::WPML_SETTINGS_OPTION, []));

        return is_array($settings) ? $settings : [];
    }

    private function hasTranslationStorage(): bool
    {
        if (isset($GLOBALS['pushpull_test_wpml_translations']) && is_array($GLOBALS['pushpull_test_wpml_translations'])) {
            return true;
        }

        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return false;
        }

        $table = $wpdb->prefix . 'icl_translations';

        $cached = wp_cache_get(self::CACHE_KEY_HAS_TRANSLATION_STORAGE, self::CACHE_GROUP);

        if (is_bool($cached)) {
            return $cached;
        }

        if (method_exists($wpdb, 'get_var')) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal WPML table name derived from the trusted wpdb prefix.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached via wp_cache_get()/wp_cache_set() around the table existence probe.
            $result = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

            $hasStorage = is_string($result) && $result === $table;
            wp_cache_set(self::CACHE_KEY_HAS_TRANSLATION_STORAGE, $hasStorage, self::CACHE_GROUP);

            return $hasStorage;
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function translationRows(): array
    {
        if (isset($GLOBALS['pushpull_test_wpml_translations']) && is_array($GLOBALS['pushpull_test_wpml_translations'])) {
            return array_values($GLOBALS['pushpull_test_wpml_translations']);
        }

        global $wpdb;

        if (! isset($wpdb) || ! $wpdb instanceof \wpdb) {
            return [];
        }

        $cachedRows = wp_cache_get(self::CACHE_KEY_TRANSLATION_ROWS, self::CACHE_GROUP);

        if (is_array($cachedRows)) {
            return $cachedRows;
        }

        $table = $wpdb->prefix . 'icl_translations';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal WPML table name derived from the trusted wpdb prefix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached via wp_cache_get()/wp_cache_set() above.
        $rows = $wpdb->get_results("SELECT translation_id, element_type, element_id, trid, language_code, source_language_code FROM {$table}", ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

        $rows = is_array($rows) ? $rows : [];
        wp_cache_set(self::CACHE_KEY_TRANSLATION_ROWS, $rows, self::CACHE_GROUP);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function persistTranslationRow(array $row): void
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
        $table = $wpdb->prefix . 'icl_translations';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writing WPML-managed translation rows is intentional here.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache invalidated immediately after the write.
        $wpdb->replace($table, $row, ['%d', '%s', '%d', '%d', '%s', '%s']);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
        wp_cache_delete(self::CACHE_KEY_TRANSLATION_ROWS, self::CACHE_GROUP);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTranslationRow(string $elementType, int $elementId): ?array
    {
        foreach ($this->translationRows() as $row) {
            if ((string) ($row['element_type'] ?? '') === $elementType && (int) ($row['element_id'] ?? 0) === $elementId) {
                return $row;
            }
        }

        return null;
    }

    private function nextTrid(): int
    {
        $max = 0;

        foreach ($this->translationRows() as $row) {
            $max = max($max, (int) ($row['trid'] ?? 0));
        }

        return $max + 1;
    }

    private function nextTranslationId(): int
    {
        $max = 0;

        foreach ($this->translationRows() as $row) {
            $max = max($max, (int) ($row['translation_id'] ?? 0));
        }

        return $max + 1;
    }

    private function hasTranslationElementType(string $elementType): bool
    {
        foreach ($this->translationRows() as $row) {
            if ((string) ($row['element_type'] ?? '') === $elementType) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $translations
     * @return array<int, array{language: string, contentDomain: string, contentType: string, contentLogicalKey: string, sourceLanguage: string|null}>
     */
    private function normalizeTranslations(mixed $translations): array
    {
        if (! is_array($translations)) {
            return [];
        }

        $normalized = [];

        foreach ($translations as $translation) {
            if (! is_array($translation)) {
                continue;
            }

            $language = (string) ($translation['language'] ?? '');
            $contentDomain = (string) ($translation['contentDomain'] ?? '');
            $contentType = (string) ($translation['contentType'] ?? '');
            $contentLogicalKey = (string) ($translation['contentLogicalKey'] ?? '');

            if ($language === '' || $contentDomain === '' || $contentType === '' || $contentLogicalKey === '') {
                continue;
            }

            $normalized[] = [
                'language' => $language,
                'contentDomain' => $contentDomain,
                'contentType' => $contentType,
                'contentLogicalKey' => $contentLogicalKey,
                'sourceLanguage' => isset($translation['sourceLanguage']) && $translation['sourceLanguage'] !== '' ? (string) $translation['sourceLanguage'] : null,
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => [$left['language'], $left['contentLogicalKey']] <=> [$right['language'], $right['contentLogicalKey']]);

        return $normalized;
    }

    /**
     * @return WP_Post[]
     */
    private function postsByType(string $postType): array
    {
        $posts = get_posts([
            'post_type' => $postType,
            'numberposts' => -1,
            'post_status' => 'any',
        ]);

        return array_values(array_filter($posts, static fn (mixed $post): bool => $post instanceof WP_Post));
    }

    private function computePostLogicalKey(WP_Post $post): string
    {
        $identifier = trim($post->post_name !== '' ? $post->post_name : $post->post_title);
        $logicalKey = sanitize_title($identifier);

        if ($logicalKey === '') {
            throw new ManagedContentExportException(sprintf('Translated post %d has an empty logical key.', $post->ID));
        }

        return $logicalKey;
    }

    private function computeTermLogicalKey(WP_Term $term): string
    {
        $identifier = trim($term->slug !== '' ? $term->slug : $term->name);
        $logicalKey = sanitize_title($identifier);

        if ($logicalKey === '') {
            throw new ManagedContentExportException(sprintf('Translated term %d has an empty logical key.', $term->term_id));
        }

        return $logicalKey;
    }

    private function findPostByLogicalKey(string $postType, string $logicalKey): ?WP_Post
    {
        foreach ($this->postsByType($postType) as $post) {
            if ($this->computePostLogicalKey($post) === $logicalKey) {
                return $post;
            }
        }

        return null;
    }

    /**
     * @return WP_Term[]
     */
    private function termsByTaxonomy(string $taxonomy): array
    {
        if ($taxonomy === 'nav_menu') {
            return array_values(array_filter(
                wp_get_nav_menus(),
                static fn (mixed $term): bool => $term instanceof WP_Term
            ));
        }

        return [];
    }

    private function findTermByLogicalKey(string $taxonomy, string $logicalKey): ?WP_Term
    {
        if ($taxonomy === 'nav_menu') {
            $adapter = new WordPressMenusAdapter();
            $termId = $adapter->findExistingWpObjectIdByLogicalKey($logicalKey);

            if ($termId === null) {
                return null;
            }

            $term = get_term($termId, $taxonomy);

            return $term instanceof WP_Term ? $term : null;
        }

        foreach ($this->termsByTaxonomy($taxonomy) as $term) {
            if ($this->computeTermLogicalKey($term) === $logicalKey) {
                return $term;
            }
        }

        return null;
    }
}
