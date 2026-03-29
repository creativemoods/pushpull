<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\ManagedContentSnapshot;
use PushPull\Content\ManagedSetDependencyAwareInterface;
use PushPull\Content\WordPressManagedContentAdapterInterface;
use PushPull\Support\Json\CanonicalJson;
use PushPull\Support\Urls\EnvironmentUrlCanonicalizer;
use RuntimeException;
use WP_Term;

final class WordPressMenusAdapter implements WordPressManagedContentAdapterInterface, ManagedSetDependencyAwareInterface
{
    private const MANAGED_SET_KEY = 'wordpress_menus';
    private const CONTENT_TYPE = 'wordpress_menu';
    private const MANIFEST_TYPE = 'wordpress_menus_manifest';
    private const PATH_PREFIX = 'wordpress/menus';
    private const MENU_TAXONOMY = 'nav_menu';

    public function getManagedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    public function getManagedSetLabel(): string
    {
        return 'WordPress menus';
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    /**
     * @return string[]
     */
    public function getManagedSetDependencies(): array
    {
        return ['wordpress_pages', 'wordpress_posts'];
    }

    public function isAvailable(): bool
    {
        return taxonomy_exists(self::MENU_TAXONOMY)
            && function_exists('wp_get_nav_menus')
            && function_exists('wp_get_nav_menu_items')
            && function_exists('wp_create_nav_menu')
            && function_exists('wp_update_nav_menu_item')
            && function_exists('get_theme_mod')
            && function_exists('set_theme_mod');
    }

    /**
     * @return ManagedContentItem[]
     */
    public function exportAll(): array
    {
        return $this->exportSnapshot()->items;
    }

    public function exportSnapshot(): ManagedContentSnapshot
    {
        $records = [];

        if ($this->isAvailable()) {
            foreach ($this->allMenus() as $menu) {
                $records[] = $this->buildRuntimeRecord($menu);
            }
        }

        $items = [];

        foreach ($records as $record) {
            $items[] = $this->buildItemFromRuntimeRecord($record);
        }

        usort($items, static fn (ManagedContentItem $left, ManagedContentItem $right): int => $left->logicalKey <=> $right->logicalKey);
        $manifest = $this->buildManifest($records);
        $this->validateManifest($manifest, $items);

        return new WordPressMenusSnapshot($items, $manifest, [], $manifest->orderedLogicalKeys);
    }

    /**
     * @param array<string, string> $files
     */
    public function readSnapshotFromRepositoryFiles(array $files): ManagedContentSnapshot
    {
        $manifestContent = $files[$this->getManifestPath()] ?? null;

        if ($manifestContent === null) {
            throw new ManagedContentExportException('Managed set manifest is missing from the local branch.');
        }

        $manifest = $this->parseManifest($manifestContent);
        $items = [];

        foreach ($files as $path => $content) {
            if ($path === $this->getManifestPath() || ! $this->isManagedItemPath($path)) {
                continue;
            }

            $item = $this->deserialize($path, $content);
            $items[$item->logicalKey] = $item;
        }

        ksort($items);
        $this->validateManifest($manifest, array_values($items));

        return new WordPressMenusSnapshot(array_values($items), $manifest, $files, $manifest->orderedLogicalKeys);
    }

    public function getManifestPath(): string
    {
        return self::PATH_PREFIX . '/manifest.json';
    }

    public function ownsRepositoryPath(string $path): bool
    {
        return $path === $this->getManifestPath() || $this->isManagedItemPath($path);
    }

    public function serialize(ManagedContentItem $item): string
    {
        $this->validateItem($item);

        return CanonicalJson::encode($item->toArray());
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

    public function buildCommitMessage(): string
    {
        return 'Commit live WordPress menus';
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        foreach ($this->allMenus() as $menu) {
            $item = $this->buildItemFromRuntimeRecord($this->buildRuntimeRecord($menu));

            if ($item->logicalKey === $logicalKey) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $wpRecord
     */
    public function computeLogicalKey(array $wpRecord): string
    {
        $identifier = trim((string) ($wpRecord['slug'] ?? ''));

        if ($identifier === '') {
            $identifier = trim((string) ($wpRecord['name'] ?? ''));
        }

        $logicalKey = sanitize_title($identifier);

        if ($logicalKey === '') {
            throw new ManagedContentExportException('WordPress menu logical key cannot be empty.');
        }

        return $logicalKey;
    }

    public function getRepositoryPath(ManagedContentItem $item): string
    {
        return sprintf('%s/%s.json', self::PATH_PREFIX, $item->logicalKey);
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
            is_array($payload)
                ? EnvironmentUrlCanonicalizer::denormalizeValue($payload)
                : ['raw' => EnvironmentUrlCanonicalizer::denormalizeValue($payload)],
            (string) ($decoded['postStatus'] ?? 'publish'),
            is_array($metadata) ? EnvironmentUrlCanonicalizer::denormalizeValue($metadata) : [],
            is_array($derived) ? EnvironmentUrlCanonicalizer::denormalizeValue($derived) : [],
            null,
            (int) ($decoded['schemaVersion'] ?? 1),
            (int) ($decoded['adapterVersion'] ?? 1)
        );
    }

    public function isManagedItemPath(string $path): bool
    {
        return str_starts_with($path, self::PATH_PREFIX . '/')
            && str_ends_with($path, '.json')
            && $path !== $this->getManifestPath();
    }

    public function findExistingWpObjectIdByLogicalKey(string $logicalKey): ?int
    {
        foreach ($this->allMenus() as $menu) {
            if (
                $this->computeLogicalKey([
                    'slug' => (string) ($menu->slug ?? ''),
                    'name' => (string) ($menu->name ?? ''),
                ]) === $logicalKey
            ) {
                return (int) $menu->term_id;
            }
        }

        return null;
    }

    public function postExists(int $postId): bool
    {
        foreach ($this->allMenus() as $menu) {
            if ((int) $menu->term_id === $postId) {
                return true;
            }
        }

        return false;
    }

    public function upsertItem(ManagedContentItem $item, int $menuOrder, ?int $existingId): int
    {
        $this->validateItem($item);

        if ($existingId !== null) {
            wp_update_term($existingId, self::MENU_TAXONOMY, [
                'name' => $item->displayName,
                'slug' => $item->slug,
                'description' => (string) ($item->payload['description'] ?? ''),
            ]);

            return $existingId;
        }

        $created = wp_create_nav_menu($item->displayName);
        $menuId = is_array($created) ? (int) ($created['term_id'] ?? 0) : (int) $created;

        if ($menuId <= 0) {
            throw new RuntimeException(sprintf('WordPress menu "%s" could not be created.', $item->logicalKey));
        }

        wp_update_term($menuId, self::MENU_TAXONOMY, [
            'name' => $item->displayName,
            'slug' => $item->slug,
            'description' => (string) ($item->payload['description'] ?? ''),
        ]);

        return $menuId;
    }

    public function persistItemMeta(int $postId, ManagedContentItem $item, array $snapshotFiles = []): void
    {
        $this->deleteExistingMenuItems($postId);

        $createdItemIds = [];
        $items = $item->payload['items'] ?? [];

        if (! is_array($items)) {
            $items = [];
        }

        foreach ($items as $menuOrder => $menuItem) {
            if (! is_array($menuItem)) {
                continue;
            }

            $parentItemKey = is_string($menuItem['parentItemKey'] ?? null) ? $menuItem['parentItemKey'] : null;
            $parentMenuItemId = $parentItemKey !== null ? ($createdItemIds[$parentItemKey] ?? 0) : 0;
            $menuItemId = wp_update_nav_menu_item($postId, 0, $this->navMenuItemData($menuItem, (int) $parentMenuItemId, (int) $menuOrder));

            if ((int) $menuItemId <= 0) {
                throw new RuntimeException(sprintf(
                    'Menu item "%s" could not be created for menu "%s".',
                    (string) ($menuItem['itemKey'] ?? 'unknown'),
                    $item->logicalKey
                ));
            }

            if (is_string($menuItem['itemKey'] ?? null) && $menuItem['itemKey'] !== '') {
                $createdItemIds[$menuItem['itemKey']] = (int) $menuItemId;
            }
        }

        $this->assignMenuLocations($postId, $item->payload['locations'] ?? []);
    }

    /**
     * @param array<string, true> $desiredLogicalKeys
     * @return string[]
     */
    public function deleteMissingItems(array $desiredLogicalKeys): array
    {
        $deletedLogicalKeys = [];

        foreach ($this->allMenus() as $menu) {
            $logicalKey = $this->computeLogicalKey([
                'slug' => (string) ($menu->slug ?? ''),
                'name' => (string) ($menu->name ?? ''),
            ]);

            if (isset($desiredLogicalKeys[$logicalKey])) {
                continue;
            }

            $this->clearMenuLocations((int) $menu->term_id);
            wp_delete_nav_menu((int) $menu->term_id);
            $deletedLogicalKeys[] = $logicalKey;
        }

        sort($deletedLogicalKeys);

        return $deletedLogicalKeys;
    }

    /**
     * @param ManagedContentItem[] $items
     */
    public function validateManifest(ManagedCollectionManifest $manifest, array $items): void
    {
        if ($manifest->manifestType !== self::MANIFEST_TYPE) {
            throw new ManagedContentExportException('Invalid WordPress menus manifest type.');
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

    public function validateItem(ManagedContentItem $item): void
    {
        if ($item->logicalKey === '') {
            throw new ManagedContentExportException('Managed content item logical key cannot be empty.');
        }

        if ($item->slug === '') {
            throw new ManagedContentExportException(sprintf('WordPress menu "%s" is missing its slug.', $item->logicalKey));
        }

        if (! is_array($item->payload['items'] ?? null)) {
            throw new ManagedContentExportException(sprintf('WordPress menu "%s" is missing its items.', $item->logicalKey));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function buildManifest(array $records): ManagedCollectionManifest
    {
        $orderedLogicalKeys = [];

        foreach ($records as $record) {
            $orderedLogicalKeys[] = $this->computeLogicalKey($record);
        }

        $orderedLogicalKeys = array_values(array_unique($orderedLogicalKeys));
        sort($orderedLogicalKeys);

        return new ManagedCollectionManifest(self::MANAGED_SET_KEY, self::MANIFEST_TYPE, $orderedLogicalKeys);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function buildItemFromRuntimeRecord(array $record): ManagedContentItem
    {
        $logicalKey = $this->computeLogicalKey($record);
        $payload = EnvironmentUrlCanonicalizer::normalizeValue([
            'description' => (string) ($record['description'] ?? ''),
            'locations' => $record['locations'] ?? [],
            'items' => $record['items'] ?? [],
        ]);

        $item = new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE,
            $logicalKey,
            trim((string) ($record['name'] ?? '')) !== '' ? (string) $record['name'] : $logicalKey,
            $logicalKey,
            trim((string) ($record['slug'] ?? '')) !== '' ? (string) $record['slug'] : $logicalKey,
            is_array($payload) ? $payload : ['raw' => $payload],
            'publish',
            [
                'restoration' => [
                    'taxonomy' => self::MENU_TAXONOMY,
                ],
            ],
            [],
            isset($record['wp_object_id']) ? (int) $record['wp_object_id'] : null
        );

        $this->validateItem($item);

        return $item;
    }

    /**
     * @return WP_Term[]
     */
    private function allMenus(): array
    {
        $menus = wp_get_nav_menus();

        return array_values(array_filter(
            is_array($menus) ? $menus : [],
            static fn (mixed $menu): bool => $menu instanceof WP_Term
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRuntimeRecord(WP_Term $menu): array
    {
        return [
            'wp_object_id' => (int) $menu->term_id,
            'name' => (string) $menu->name,
            'slug' => (string) $menu->slug,
            'description' => (string) ($menu->description ?? ''),
            'locations' => $this->locationsForMenu((int) $menu->term_id),
            'items' => $this->normalizeMenuItems((int) $menu->term_id),
        ];
    }

    /**
     * @return string[]
     */
    private function locationsForMenu(int $menuId): array
    {
        $locations = get_theme_mod('nav_menu_locations', []);
        $assigned = [];

        if (! is_array($locations)) {
            return [];
        }

        foreach ($locations as $location => $assignedMenuId) {
            if ((int) $assignedMenuId === $menuId && is_string($location) && $location !== '') {
                $assigned[] = $location;
            }
        }

        sort($assigned);

        return $assigned;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMenuItems(int $menuId): array
    {
        $menuItems = wp_get_nav_menu_items($menuId);

        if (! is_array($menuItems)) {
            return [];
        }

        usort(
            $menuItems,
            static fn (object $left, object $right): int => ((int) ($left->menu_order ?? 0)) <=> ((int) ($right->menu_order ?? 0))
        );

        $itemKeysById = [];
        $normalized = [];
        $usedKeys = [];

        foreach ($menuItems as $menuItem) {
            if (! is_object($menuItem)) {
                continue;
            }

            $itemKey = $this->uniqueMenuItemKey($this->baseMenuItemKey($menuItem), $usedKeys);
            $itemId = (int) ($menuItem->ID ?? 0);

            if ($itemId > 0) {
                $itemKeysById[$itemId] = $itemKey;
            }

            $normalized[] = [
                'itemId' => $itemId,
                'itemKey' => $itemKey,
                'menuOrder' => (int) ($menuItem->menu_order ?? 0),
                'parentId' => (int) ($menuItem->menu_item_parent ?? 0),
                'type' => (string) ($menuItem->type ?? 'custom'),
                'objectType' => (string) ($menuItem->object ?? ''),
                'label' => (string) ($menuItem->title ?? ''),
                'url' => (string) ($menuItem->url ?? ''),
                'target' => (string) ($menuItem->target ?? ''),
                'attrTitle' => (string) ($menuItem->attr_title ?? ''),
                'description' => (string) ($menuItem->description ?? ''),
                'classes' => array_values(array_filter(
                    array_map('strval', is_array($menuItem->classes ?? null) ? $menuItem->classes : []),
                    static fn (string $value): bool => trim($value) !== ''
                )),
                'xfn' => (string) ($menuItem->xfn ?? ''),
                'reference' => $this->menuItemReference($menuItem),
            ];
        }

        foreach ($normalized as &$entry) {
            $parentId = (int) ($entry['parentId'] ?? 0);
            $entry['parentItemKey'] = $parentId > 0 ? ($itemKeysById[$parentId] ?? null) : null;
            unset($entry['itemId'], $entry['parentId']);
        }
        unset($entry);

        return $normalized;
    }

    private function baseMenuItemKey(object $menuItem): string
    {
        $type = (string) ($menuItem->type ?? 'custom');
        $objectType = (string) ($menuItem->object ?? '');

        if ($type === 'custom') {
            $label = sanitize_title((string) ($menuItem->title ?? 'custom-link'));

            return 'custom:' . ($label !== '' ? $label : 'link');
        }

        if ($type === 'post_type') {
            $ref = $this->postObjectReference((int) ($menuItem->object_id ?? 0), $objectType);

            if ($ref !== null) {
                return $objectType . ':' . $ref['logicalKey'];
            }
        }

        if ($type === 'taxonomy') {
            $slug = $this->taxonomyTermSlug((int) ($menuItem->object_id ?? 0), $objectType);

            if ($slug !== null) {
                return $objectType . ':' . $slug;
            }
        }

        if ($type === 'post_type_archive') {
            return 'archive:' . sanitize_title($objectType !== '' ? $objectType : 'archive');
        }

        $label = sanitize_title((string) ($menuItem->title ?? 'item'));

        return ($type !== '' ? $type : 'item') . ':' . ($label !== '' ? $label : 'item');
    }

    /**
     * @param array<string, true> $usedKeys
     */
    private function uniqueMenuItemKey(string $baseKey, array &$usedKeys): string
    {
        $candidate = $baseKey !== '' ? $baseKey : 'item';
        $suffix = 2;

        while (isset($usedKeys[$candidate])) {
            $candidate = $baseKey . '-' . $suffix;
            $suffix++;
        }

        $usedKeys[$candidate] = true;

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function menuItemReference(object $menuItem): array
    {
        $type = (string) ($menuItem->type ?? 'custom');
        $objectType = (string) ($menuItem->object ?? '');
        $objectId = (int) ($menuItem->object_id ?? 0);

        if ($type === 'post_type') {
            $ref = $this->postObjectReference($objectId, $objectType);

            if ($ref !== null) {
                return $ref;
            }
        }

        if ($type === 'taxonomy') {
            $slug = $this->taxonomyTermSlug($objectId, $objectType);

            if ($slug !== null) {
                return [
                    'taxonomyRef' => [
                        'taxonomy' => $objectType,
                        'slug' => $slug,
                    ],
                ];
            }
        }

        if ($type === 'post_type_archive') {
            return [
                'archiveRef' => [
                    'postType' => $objectType,
                ],
            ];
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function postObjectReference(int $objectId, string $objectType): ?array
    {
        if ($objectId <= 0) {
            return null;
        }

        if ($objectType === 'page') {
            $post = $this->findPostById($objectId, 'page');

            if ($post === null) {
                return null;
            }

            return [
                'objectRef' => [
                    'managedSetKey' => 'wordpress_pages',
                    'contentType' => 'wordpress_page',
                    'logicalKey' => sanitize_title($post->post_name !== '' ? $post->post_name : $post->post_title),
                    'postType' => 'page',
                ],
            ];
        }

        if ($objectType === 'post') {
            $post = $this->findPostById($objectId, 'post');

            if ($post === null) {
                return null;
            }

            return [
                'objectRef' => [
                    'managedSetKey' => 'wordpress_posts',
                    'contentType' => 'wordpress_post',
                    'logicalKey' => sanitize_title($post->post_name !== '' ? $post->post_name : $post->post_title),
                    'postType' => 'post',
                ],
            ];
        }

        return null;
    }

    private function findPostById(int $postId, string $postType): ?object
    {
        foreach (
            get_posts([
                'post_type' => $postType,
                'numberposts' => -1,
                'post_status' => 'any',
            ]) as $post
        ) {
            if ((int) ($post->ID ?? 0) === $postId) {
                return $post;
            }
        }

        return null;
    }

    private function taxonomyTermSlug(int $termId, string $taxonomy): ?string
    {
        if ($termId <= 0 || $taxonomy === '') {
            return null;
        }

        $term = get_term($termId, $taxonomy);

        if (! $term instanceof WP_Term || $term->slug === '') {
            return null;
        }

        return $term->slug;
    }

    /**
     * @param array<string, mixed> $menuItem
     * @return array<string, mixed>
     */
    private function navMenuItemData(array $menuItem, int $parentMenuItemId, int $menuOrder): array
    {
        $data = [
            'menu-item-title' => (string) ($menuItem['label'] ?? ''),
            'menu-item-position' => $menuOrder + 1,
            'menu-item-parent-id' => $parentMenuItemId,
            'menu-item-classes' => implode(' ', array_map('strval', is_array($menuItem['classes'] ?? null) ? $menuItem['classes'] : [])),
            'menu-item-target' => (string) ($menuItem['target'] ?? ''),
            'menu-item-attr-title' => (string) ($menuItem['attrTitle'] ?? ''),
            'menu-item-description' => (string) ($menuItem['description'] ?? ''),
            'menu-item-xfn' => (string) ($menuItem['xfn'] ?? ''),
            'menu-item-status' => 'publish',
        ];

        $type = (string) ($menuItem['type'] ?? 'custom');

        if ($type === 'post_type') {
            $ref = $menuItem['reference']['objectRef'] ?? null;

            if (! is_array($ref) || ! is_string($ref['logicalKey'] ?? null) || ! is_string($ref['postType'] ?? null)) {
                throw new RuntimeException('WordPress menu item is missing its post object reference.');
            }

            $objectId = $this->resolvePostObjectReference($ref);

            $data['menu-item-type'] = 'post_type';
            $data['menu-item-object'] = $ref['postType'];
            $data['menu-item-object-id'] = $objectId;

            return $data;
        }

        if ($type === 'taxonomy') {
            $ref = $menuItem['reference']['taxonomyRef'] ?? null;

            if (! is_array($ref) || ! is_string($ref['taxonomy'] ?? null) || ! is_string($ref['slug'] ?? null)) {
                throw new RuntimeException('WordPress menu item is missing its taxonomy reference.');
            }

            $term = term_exists($ref['slug'], $ref['taxonomy']);

            if (! is_array($term) || ! isset($term['term_id'])) {
                throw new RuntimeException(sprintf(
                    'WordPress menu item references missing taxonomy term "%s" in "%s".',
                    $ref['slug'],
                    $ref['taxonomy']
                ));
            }

            $data['menu-item-type'] = 'taxonomy';
            $data['menu-item-object'] = $ref['taxonomy'];
            $data['menu-item-object-id'] = (int) $term['term_id'];

            return $data;
        }

        if ($type === 'post_type_archive') {
            $ref = $menuItem['reference']['archiveRef'] ?? null;

            if (! is_array($ref) || ! is_string($ref['postType'] ?? null) || $ref['postType'] === '') {
                throw new RuntimeException('WordPress menu item is missing its archive reference.');
            }

            $data['menu-item-type'] = 'post_type_archive';
            $data['menu-item-object'] = $ref['postType'];

            return $data;
        }

        $data['menu-item-type'] = 'custom';
        $data['menu-item-url'] = (string) ($menuItem['url'] ?? '');

        return $data;
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function resolvePostObjectReference(array $ref): int
    {
        $logicalKey = (string) ($ref['logicalKey'] ?? '');
        $postType = (string) ($ref['postType'] ?? '');

        if ($logicalKey === '' || $postType === '') {
            throw new RuntimeException('WordPress menu item post reference is incomplete.');
        }

        $adapter = $postType === 'page' ? new WordPressPagesAdapter() : ($postType === 'post' ? new WordPressPostsAdapter() : null);

        if ($adapter === null) {
            throw new RuntimeException(sprintf('Unsupported WordPress menu post type reference "%s".', $postType));
        }

        $objectId = $adapter->findExistingWpObjectIdByLogicalKey($logicalKey);

        if ($objectId === null) {
            throw new RuntimeException(sprintf(
                'WordPress menu item references missing %s "%s".',
                $postType,
                $logicalKey
            ));
        }

        return $objectId;
    }

    private function deleteExistingMenuItems(int $menuId): void
    {
        $menuItems = wp_get_nav_menu_items($menuId);

        if (! is_array($menuItems)) {
            return;
        }

        foreach ($menuItems as $menuItem) {
            if (! is_object($menuItem) || ! isset($menuItem->ID)) {
                continue;
            }

            wp_delete_post((int) $menuItem->ID, true);
        }
    }

    /**
     * @param mixed $locations
     */
    private function assignMenuLocations(int $menuId, mixed $locations): void
    {
        $current = get_theme_mod('nav_menu_locations', []);
        $normalized = is_array($current) ? $current : [];

        foreach ($normalized as $location => $assignedMenuId) {
            if ((int) $assignedMenuId === $menuId) {
                $normalized[$location] = 0;
            }
        }

        if (is_array($locations)) {
            foreach ($locations as $location) {
                if (! is_string($location) || $location === '') {
                    continue;
                }

                $normalized[$location] = $menuId;
            }
        }

        set_theme_mod('nav_menu_locations', $normalized);
    }

    private function clearMenuLocations(int $menuId): void
    {
        $current = get_theme_mod('nav_menu_locations', []);

        if (! is_array($current)) {
            return;
        }

        foreach ($current as $location => $assignedMenuId) {
            if ((int) $assignedMenuId === $menuId) {
                $current[$location] = 0;
            }
        }

        set_theme_mod('nav_menu_locations', $current);
    }
}
