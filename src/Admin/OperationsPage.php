<?php

declare(strict_types=1);

namespace PushPull\Admin;

use PushPull\Persistence\Operations\OperationLogRepository;
use PushPull\Persistence\Operations\OperationRecord;
use PushPull\Support\Capabilities;

final class OperationsPage
{
    public const MENU_SLUG = 'pushpull-operations';

    public function __construct(private readonly OperationLogRepository $operationLogRepository)
    {
    }

    public function register(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Operations', 'pushpull'),
            __('Operations', 'pushpull'),
            Capabilities::MANAGE_PLUGIN,
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'pushpull_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'pushpull-admin',
            PUSHPULL_PLUGIN_URL . 'assets/css/admin.css',
            [],
            PUSHPULL_VERSION
        );
    }

    public function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        $records = $this->operationLogRepository->recent(100);

        echo '<div class="wrap pushpull-admin">';
        echo '<h1>' . esc_html__('PushPull Operations', 'pushpull') . '</h1>';
        echo '<p class="pushpull-intro">' . esc_html__('Recent PushPull sync and repository operations are listed here with their inputs, normalized outcomes, and failure details.', 'pushpull') . '</p>';

        if ($records === []) {
            echo '<div class="pushpull-panel">';
            echo '<p>' . esc_html__('No operations have been recorded yet.', 'pushpull') . '</p>';
            echo '</div>';
            echo '</div>';

            return;
        }

        echo '<div class="pushpull-panel">';
        echo '<table class="widefat fixed striped pushpull-operations-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Type', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Managed Set', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Status', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Started', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Finished', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('User', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Details', 'pushpull') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($records as $record) {
            echo '<tr>';
            printf('<td>%s</td>', esc_html((string) $record->id));
            printf('<td><code>%s</code></td>', esc_html($record->operationType));
            printf('<td><code>%s</code></td>', esc_html($record->managedSetKey !== '' ? $record->managedSetKey : 'repository'));
            printf(
                '<td><span class="pushpull-diff-badge pushpull-diff-badge-%s">%s</span></td>',
                esc_attr($this->statusBadgeClass($record)),
                esc_html(ucfirst($record->status))
            );
            printf('<td>%s</td>', esc_html($record->createdAt));
            printf('<td>%s</td>', esc_html($record->finishedAt ?? 'Running'));
            printf('<td>%s</td>', esc_html($record->createdBy !== null ? (string) $record->createdBy : 'n/a'));
            printf(
                '<td><button type="button" class="button button-secondary pushpull-operation-toggle" data-target="%s" aria-expanded="false">%s</button></td>',
                esc_attr($this->detailsRowId($record)),
                esc_html__('View details', 'pushpull')
            );
            echo '</tr>';
            printf(
                '<tr id="%1$s" class="pushpull-operation-detail-row" hidden><td colspan="8">',
                esc_attr($this->detailsRowId($record))
            );
            $this->renderDetails($record);
            echo '</td></tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
        $this->renderToggleScript();
    }

    private function renderDetails(OperationRecord $record): void
    {
        echo '<div class="pushpull-operation-details">';
        echo '<div class="pushpull-operation-grid">';
        echo '<div>';
        echo '<h3>' . esc_html__('Payload', 'pushpull') . '</h3>';
        echo '<pre>' . esc_html($this->encodePrettyJson($record->payload)) . '</pre>';
        echo '</div>';
        echo '<div>';
        echo '<h3>' . esc_html__('Result', 'pushpull') . '</h3>';
        echo '<pre>' . esc_html($this->encodePrettyJson($record->result)) . '</pre>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    private function statusBadgeClass(OperationRecord $record): string
    {
        return match ($record->status) {
            OperationLogRepository::STATUS_SUCCEEDED => 'added',
            OperationLogRepository::STATUS_FAILED => 'deleted',
            default => 'modified',
        };
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encodePrettyJson(array $value): string
    {
        if ($value === []) {
            return '{}';
        }

        $encoded = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function detailsRowId(OperationRecord $record): string
    {
        return 'pushpull-operation-' . $record->id;
    }

    private function renderToggleScript(): void
    {
        ?>
        <script>
        document.addEventListener('click', function (event) {
            const button = event.target.closest('.pushpull-operation-toggle');
            if (!button) {
                return;
            }

            const targetId = button.getAttribute('data-target');
            if (!targetId) {
                return;
            }

            const row = document.getElementById(targetId);
            if (!row) {
                return;
            }

            const willExpand = row.hasAttribute('hidden');
            row.toggleAttribute('hidden');
            button.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
            button.textContent = willExpand ? 'Hide details' : 'View details';
        });
        </script>
        <?php
    }
}
