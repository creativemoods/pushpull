<?php

declare(strict_types=1);

namespace PushPull\Cli;

use PushPull\Content\ManagedSetRegistry;
use PushPull\Settings\PushPullSettings;
use PushPull\Settings\SettingsRepository;
use RuntimeException;
use WP_CLI;
use WP_CLI_Command;

final class PushPullConfigCliCommand extends WP_CLI_Command
{
    /** @var string[] */
    private const ALLOWED_FIELDS = [
        'provider_key',
        'owner_or_workspace',
        'repository',
        'branch',
        'api_token',
        'base_url',
        'fetch_availability_check_interval_minutes',
        'author_name',
        'author_email',
        'auto_apply_enabled',
        'diagnostics_enabled',
    ];

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly ManagedSetRegistry $managedSetRegistry
    ) {
    }

    /**
     * List the current PushPull configuration.
     *
     * @subcommand list
     */
    public function listConfig(array $args, array $assocArgs): void
    {
        $settings = $this->settingsRepository->get();
        $rows = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            $rows[] = [
                'field' => $field,
                'value' => $this->stringValue($field, $settings),
            ];
        }

        $rows[] = [
            'field' => 'enabled_managed_sets',
            'value' => implode(', ', $settings->enabledManagedSets),
        ];

        $this->renderRows($rows, ['field', 'value']);
    }

    /**
     * Get one PushPull configuration value.
     *
     * ## OPTIONS
     *
     * <field>
     * : Configuration field name.
     */
    public function get(array $args, array $assocArgs): void
    {
        $field = $this->requiredField($args);
        $settings = $this->settingsRepository->get();

        if ($field === 'enabled_managed_sets') {
            WP_CLI::line(implode(',', $settings->enabledManagedSets));
            return;
        }

        $this->guardKnownField($field);
        WP_CLI::line($this->stringValue($field, $settings));
    }

    /**
     * Set one PushPull configuration value.
     *
     * ## OPTIONS
     *
     * <field>
     * : Configuration field name.
     *
     * <value>
     * : New configuration value.
     */
    public function set(array $args, array $assocArgs): void
    {
        if (count($args) < 2) {
            WP_CLI::error('Usage: wp pushpull config set <field> <value>');
        }

        $field = sanitize_key((string) $args[0]);
        $value = (string) $args[1];

        $this->guardKnownField($field);

        $settings = $this->settingsRepository->get();
        $input = $settings->toArray();
        $input[$field] = $this->normalizeValue($field, $value);
        $this->settingsRepository->save($this->settingsRepository->sanitize($input));

        WP_CLI::success(sprintf('Updated %s.', $field));
    }

    /**
     * Enable one managed domain.
     *
     * @subcommand enable-domain
     *
     * ## OPTIONS
     *
     * <managed-set>
     * : Managed set key to enable.
     */
    public function enableDomain(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->requiredManagedSetKey($args);

        if (! $this->managedSetRegistry->has($managedSetKey)) {
            WP_CLI::error(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        $settings = $this->settingsRepository->get();
        $enabledManagedSets = $settings->enabledManagedSets;

        if (! in_array($managedSetKey, $enabledManagedSets, true)) {
            $enabledManagedSets[] = $managedSetKey;
        }

        $this->saveEnabledManagedSets($settings, $enabledManagedSets);
        WP_CLI::success(sprintf('Enabled managed set %s.', $managedSetKey));
    }

    /**
     * Disable one managed domain.
     *
     * @subcommand disable-domain
     *
     * ## OPTIONS
     *
     * <managed-set>
     * : Managed set key to disable.
     */
    public function disableDomain(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->requiredManagedSetKey($args);
        $settings = $this->settingsRepository->get();
        $enabledManagedSets = array_values(array_filter(
            $settings->enabledManagedSets,
            static fn (string $candidate): bool => $candidate !== $managedSetKey
        ));

        $this->saveEnabledManagedSets($settings, $enabledManagedSets);
        WP_CLI::success(sprintf('Disabled managed set %s.', $managedSetKey));
    }

    private function requiredField(array $args): string
    {
        $field = sanitize_key((string) ($args[0] ?? ''));

        if ($field === '') {
            WP_CLI::error('A configuration field is required.');
        }

        return $field;
    }

    private function requiredManagedSetKey(array $args): string
    {
        $managedSetKey = sanitize_key((string) ($args[0] ?? ''));

        if ($managedSetKey === '') {
            WP_CLI::error('A managed set key is required.');
        }

        return $managedSetKey;
    }

    private function guardKnownField(string $field): void
    {
        if (! in_array($field, self::ALLOWED_FIELDS, true)) {
            WP_CLI::error(sprintf('Unknown configuration field "%s".', $field));
        }
    }

    private function normalizeValue(string $field, string $value): string|int|bool
    {
        return match ($field) {
            'fetch_availability_check_interval_minutes' => max(1, (int) $value),
            'auto_apply_enabled', 'diagnostics_enabled' => $this->parseBoolean($value),
            default => $value,
        };
    }

    private function parseBoolean(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
            default => throw new RuntimeException(sprintf('Boolean value "%s" must be one of: true, false, yes, no, 1, 0, on, off.', $value)),
        };
    }

    private function stringValue(string $field, PushPullSettings $settings): string
    {
        return match ($field) {
            'api_token' => $settings->maskedApiToken(),
            'fetch_availability_check_interval_minutes' => (string) $settings->fetchAvailabilityCheckIntervalMinutes,
            'auto_apply_enabled' => $settings->autoApplyEnabled ? 'true' : 'false',
            'diagnostics_enabled' => $settings->diagnosticsEnabled ? 'true' : 'false',
            default => (string) ($settings->toArray()[$field] ?? ''),
        };
    }

    /**
     * @param string[] $enabledManagedSets
     */
    private function saveEnabledManagedSets(PushPullSettings $settings, array $enabledManagedSets): void
    {
        $this->settingsRepository->save($this->settingsRepository->sanitize([
            'provider_key' => $settings->providerKey,
            'owner_or_workspace' => $settings->ownerOrWorkspace,
            'repository' => $settings->repository,
            'branch' => $settings->branch,
            'api_token' => $settings->apiToken,
            'base_url' => $settings->baseUrl,
            'fetch_availability_check_interval_minutes' => $settings->fetchAvailabilityCheckIntervalMinutes,
            'enabled_managed_sets' => $enabledManagedSets,
            'auto_apply_enabled' => $settings->autoApplyEnabled,
            'diagnostics_enabled' => $settings->diagnosticsEnabled,
            'author_name' => $settings->authorName,
            'author_email' => $settings->authorEmail,
        ]));
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @param string[] $columns
     */
    private function renderRows(array $rows, array $columns): void
    {
        if (function_exists('\WP_CLI\Utils\format_items')) {
            call_user_func('\WP_CLI\Utils\format_items', 'table', $rows, $columns);
            return;
        }

        $widths = [];

        foreach ($columns as $column) {
            $widths[$column] = $this->displayWidth($column);
        }

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $widths[$column] = max($widths[$column], $this->displayWidth((string) ($row[$column] ?? '')));
            }
        }

        WP_CLI::line($this->formatTableRow(array_combine($columns, $columns) ?: [], $columns, $widths));

        foreach ($rows as $row) {
            WP_CLI::line($this->formatTableRow($row, $columns, $widths));
        }
    }

    /**
     * @param array<string, string> $row
     * @param string[] $columns
     * @param array<string, int> $widths
     */
    private function formatTableRow(array $row, array $columns, array $widths): string
    {
        $values = [];

        foreach ($columns as $column) {
            $value = (string) ($row[$column] ?? '');
            $values[] = $value . str_repeat(' ', max(0, $widths[$column] - $this->displayWidth($value)));
        }

        return rtrim(implode('  ', $values));
    }

    private function displayWidth(string $value): int
    {
        if (function_exists('mb_strwidth')) {
            return mb_strwidth($value, 'UTF-8');
        }

        return strlen(utf8_decode($value));
    }
}
