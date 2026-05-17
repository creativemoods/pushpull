<?php

declare(strict_types=1);

namespace PushPull\Content\WordPress;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\ConfigManagedContentAdapterInterface;
use PushPull\Content\ConfigManagedSetInterface;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\ManagedCollectionManifest;
use PushPull\Content\ManagedContentItem;
use PushPull\Content\ManagedContentSnapshot;
use PushPull\Support\Json\CanonicalJson;
use RuntimeException;

final class GeneratePressConfigurationAdapter implements ConfigManagedContentAdapterInterface, ConfigManagedSetInterface
{
    private const MANAGED_SET_KEY = 'generatepress_configuration';
    private const CONTENT_TYPE = 'generatepress_configuration_settings';
    private const MANIFEST_TYPE = 'generatepress_configuration_manifest';
    private const PATH_PREFIX = 'generatepress/configuration';
    private const LOGICAL_KEY = 'generatepress-settings';
    private const DASHBOARD_CLASS = 'GeneratePress_Pro_Dashboard';

    public function getManagedSetKey(): string
    {
        return self::MANAGED_SET_KEY;
    }

    public function getManagedSetLabel(): string
    {
        return 'GeneratePress configuration';
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    public function isAvailable(): bool
    {
        return class_exists(self::DASHBOARD_CLASS)
            && is_callable([self::DASHBOARD_CLASS, 'get_modules'])
            && is_callable([self::DASHBOARD_CLASS, 'get_setting_keys'])
            && is_callable([self::DASHBOARD_CLASS, 'get_theme_mods']);
    }

    public function isConfigManagedSet(): bool
    {
        return true;
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
        $item = $this->buildSettingsItem();
        $files = [
            $this->getRepositoryPath($item) => $this->serialize($item),
        ];
        $manifest = new ManagedCollectionManifest(self::MANAGED_SET_KEY, self::MANIFEST_TYPE, [self::LOGICAL_KEY]);
        $files[$this->getManifestPath()] = $this->serializeManifest($manifest);
        ksort($files);

        return new ManagedContentSnapshot([$item], $manifest, $files, [self::LOGICAL_KEY]);
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
        $itemContent = $files[self::PATH_PREFIX . '/' . self::LOGICAL_KEY . '.json'] ?? null;

        if ($itemContent === null) {
            throw new ManagedContentExportException(sprintf('Manifest references unknown logical key: %s', self::LOGICAL_KEY));
        }

        $item = $this->deserialize(self::PATH_PREFIX . '/' . self::LOGICAL_KEY . '.json', $itemContent);
        $this->validateManifest($manifest, [$item]);

        return new ManagedContentSnapshot([$item], $manifest, $files, [self::LOGICAL_KEY]);
    }

    public function exportByLogicalKey(string $logicalKey): ?ManagedContentItem
    {
        return $logicalKey === self::LOGICAL_KEY ? $this->buildSettingsItem() : null;
    }

    /**
     * @param array<string, mixed> $wpRecord
     */
    public function computeLogicalKey(array $wpRecord): string
    {
        $logicalKey = (string) ($wpRecord['logicalKey'] ?? '');

        if ($logicalKey === '') {
            throw new ManagedContentExportException('GeneratePress configuration logical key is missing.');
        }

        return $logicalKey;
    }

    public function getRepositoryPath(ManagedContentItem $item): string
    {
        return sprintf('%s/%s.json', self::PATH_PREFIX, $item->logicalKey);
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

        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            (string) ($decoded['type'] ?? self::CONTENT_TYPE),
            (string) ($decoded['logicalKey'] ?? ''),
            (string) ($decoded['displayName'] ?? ''),
            (string) ($decoded['selector'] ?? ''),
            (string) ($decoded['slug'] ?? ''),
            is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [],
            (string) ($decoded['postStatus'] ?? 'publish'),
            is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [],
            is_array($decoded['derived'] ?? null) ? $decoded['derived'] : [],
            null,
            (int) ($decoded['schemaVersion'] ?? 1),
            (int) ($decoded['adapterVersion'] ?? 1)
        );
    }

    public function getManifestPath(): string
    {
        return self::PATH_PREFIX . '/manifest.json';
    }

    public function ownsRepositoryPath(string $path): bool
    {
        return $path === $this->getManifestPath()
            || $path === self::PATH_PREFIX . '/' . self::LOGICAL_KEY . '.json';
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
            throw new ManagedContentExportException('Invalid GeneratePress configuration manifest type.');
        }

        if ($manifest->orderedLogicalKeys !== [self::LOGICAL_KEY] || count($items) !== 1 || $items[0]->logicalKey !== self::LOGICAL_KEY) {
            throw new ManagedContentExportException('GeneratePress configuration manifest does not match the managed items.');
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
        return 'Commit live GeneratePress configuration';
    }

    public function applyItem(ManagedContentItem $item): void
    {
        $this->validateItem($item);
        $payload = $item->payload;
        $moduleStates = is_array($payload['moduleStates'] ?? null) ? $payload['moduleStates'] : [];
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $themeMods = is_array($payload['themeMods'] ?? null) ? $payload['themeMods'] : [];

        foreach ($moduleStates as $moduleOptionKey => $state) {
            if (! is_string($moduleOptionKey) || $moduleOptionKey === '' || ! is_array($state)) {
                continue;
            }

            update_option($moduleOptionKey, ! empty($state['active']) ? 'activated' : 'deactivated');
        }

        foreach ($this->managedOptionKeys($payload) as $optionKey) {
            if (array_key_exists($optionKey, $options)) {
                delete_option($optionKey);
                update_option($optionKey, $options[$optionKey]);
                continue;
            }

            delete_option($optionKey);
        }

        foreach ($this->managedThemeModKeys($payload) as $themeModKey) {
            if (array_key_exists($themeModKey, $themeMods)) {
                set_theme_mod($themeModKey, $themeMods[$themeModKey]);
                continue;
            }

            if (function_exists('remove_theme_mod')) {
                remove_theme_mod($themeModKey);
            }
        }

        delete_option('generate_dynamic_css_output');
        delete_option('generate_dynamic_css_cached_version');
        $dynamicCssData = get_option('generatepress_dynamic_css_data', []);

        if (is_array($dynamicCssData) && array_key_exists('updated_time', $dynamicCssData)) {
            unset($dynamicCssData['updated_time']);
            update_option('generatepress_dynamic_css_data', $dynamicCssData);
        }
    }

    private function buildSettingsItem(): ManagedContentItem
    {
        $payload = [
            'moduleStates' => $this->exportModuleStates(),
            'options' => $this->exportOptions(),
            'themeMods' => $this->exportThemeMods(),
        ];

        return new ManagedContentItem(
            self::MANAGED_SET_KEY,
            self::CONTENT_TYPE,
            self::LOGICAL_KEY,
            'GeneratePress settings',
            self::LOGICAL_KEY,
            self::LOGICAL_KEY,
            $payload,
            'publish',
            [
                'restoration' => [
                    'optionNames' => array_values(array_keys($payload['options'] + $payload['moduleStates'])),
                    'themeModNames' => array_values(array_keys($payload['themeMods'])),
                ],
            ]
        );
    }

    /**
     * @return array<string, array{active: bool, exportable: bool, settingsKey: string}>
     */
    private function exportModuleStates(): array
    {
        $modules = $this->dashboardModules();
        $states = [];

        foreach ($modules as $module) {
            $moduleOptionKey = isset($module['key']) ? (string) $module['key'] : '';

            if ($moduleOptionKey === '') {
                continue;
            }

            $states[$moduleOptionKey] = [
                'active' => ! empty($module['isActive']),
                'exportable' => ! empty($module['exportable']),
                'settingsKey' => isset($module['settings']) ? (string) $module['settings'] : '',
            ];
        }

        ksort($states);

        return $states;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportOptions(): array
    {
        $options = [];

        foreach ($this->managedOptionKeys() as $optionKey) {
            $options[$optionKey] = get_option($optionKey, []);
        }

        ksort($options);

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportThemeMods(): array
    {
        $themeMods = [];

        foreach ($this->managedThemeModKeys() as $themeModKey) {
            $themeMods[$themeModKey] = get_theme_mod($themeModKey, null);
        }

        ksort($themeMods);

        return $themeMods;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return string[]
     */
    private function managedOptionKeys(?array $payload = null): array
    {
        $runtimeKeys = $this->dashboardSettingKeys();
        $payloadKeys = [];

        if (is_array($payload)) {
            $payloadKeys = array_keys(is_array($payload['options'] ?? null) ? $payload['options'] : []);
        }

        $keys = array_values(array_unique(array_filter(array_map('strval', array_merge($runtimeKeys, $payloadKeys)))));
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return string[]
     */
    private function managedThemeModKeys(?array $payload = null): array
    {
        $runtimeKeys = $this->dashboardThemeMods();
        $payloadKeys = [];

        if (is_array($payload)) {
            $payloadKeys = array_keys(is_array($payload['themeMods'] ?? null) ? $payload['themeMods'] : []);
        }

        $keys = array_values(array_unique(array_filter(array_map('strval', array_merge($runtimeKeys, $payloadKeys)))));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dashboardModules(): array
    {
        if (! is_callable([self::DASHBOARD_CLASS, 'get_modules'])) {
            return [];
        }

        $modules = call_user_func([self::DASHBOARD_CLASS, 'get_modules']);

        return is_array($modules) ? array_values(array_filter($modules, 'is_array')) : [];
    }

    /**
     * @return string[]
     */
    private function dashboardSettingKeys(): array
    {
        if (! is_callable([self::DASHBOARD_CLASS, 'get_setting_keys'])) {
            return [];
        }

        $keys = call_user_func([self::DASHBOARD_CLASS, 'get_setting_keys']);

        return is_array($keys) ? array_values(array_filter(array_map('strval', $keys), static fn (string $key): bool => $key !== '')) : [];
    }

    /**
     * @return string[]
     */
    private function dashboardThemeMods(): array
    {
        if (! is_callable([self::DASHBOARD_CLASS, 'get_theme_mods'])) {
            return [];
        }

        $keys = call_user_func([self::DASHBOARD_CLASS, 'get_theme_mods']);

        return is_array($keys) ? array_values(array_filter(array_map('strval', $keys), static fn (string $key): bool => $key !== '')) : [];
    }

    private function validateItem(ManagedContentItem $item): void
    {
        if ($item->managedSetKey !== self::MANAGED_SET_KEY) {
            throw new ManagedContentExportException('Managed content item belongs to an unexpected managed set.');
        }

        if ($item->contentType !== self::CONTENT_TYPE) {
            throw new ManagedContentExportException(sprintf('Unsupported GeneratePress configuration content type "%s".', $item->contentType));
        }

        if ($item->logicalKey !== self::LOGICAL_KEY) {
            throw new RuntimeException(sprintf('Unsupported GeneratePress configuration logical key "%s".', $item->logicalKey));
        }
    }
}
