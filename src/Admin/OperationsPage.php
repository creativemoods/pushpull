<?php

declare(strict_types=1);

namespace PushPull\Admin;

use PushPull\Persistence\Operations\OperationLogRepository;
use PushPull\Persistence\Operations\OperationRecord;
use PushPull\Support\Capabilities;
use PushPull\Support\Operations\BranchAsyncOperationCoordinator;
use RuntimeException;

final class OperationsPage
{
    public const MENU_SLUG = 'pushpull-audit-log';
    private const HEADER_LOGO_PATH = 'plugin-assets/images/pushpull_logo_transp.png';
    private const CANCEL_ACTION = 'pushpull_cancel_operation';

    public function __construct(
        private readonly OperationLogRepository $operationLogRepository,
        private readonly BranchAsyncOperationCoordinator $branchAsyncOperationCoordinator
    ) {
    }

    public function register(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Audit Log', 'pushpull'),
            __('Audit Log', 'pushpull'),
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
            PUSHPULL_PLUGIN_URL . 'plugin-assets/css/admin.css',
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
        $view = $this->currentView();

        echo '<div class="wrap pushpull-admin">';
        echo '<div class="pushpull-page-header">';
        echo '<div class="pushpull-page-header__content">';
        echo '<h1>' . esc_html__('PushPull Audit Log', 'pushpull') . '</h1>';
        echo '<p class="pushpull-intro">' . esc_html__('Review repository operations recorded in wp_pushpull_repo_operations, inspect payloads and normalized outcomes, and cancel resumable async branch actions before their next chunk runs.', 'pushpull') . '</p>';
        echo '</div>';
        printf(
            '<div class="pushpull-page-logo"><img src="%s" alt="%s" /></div>',
            esc_url(PUSHPULL_PLUGIN_URL . self::HEADER_LOGO_PATH),
            esc_attr__('PushPull', 'pushpull')
        );
        echo '</div>';
        $this->renderPrimaryNavigation();
        $this->renderSecondaryNavigation($view);
        $this->renderNotice();

        if ($view === 'repository_operations') {
            $this->renderRepositoryOperations($records);
        } else {
            $this->renderHistory($records);
        }

        echo '</div>';
        $this->renderToggleScript();
    }

    public function handleCancelOperation(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull operations.', 'pushpull'));
        }

        $operationId = isset($_POST['operation_id']) ? absint(wp_unslash((string) $_POST['operation_id'])) : 0;
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_POST['_wpnonce'])) : '';

        if ($operationId <= 0 || wp_verify_nonce($nonce, 'pushpull_cancel_operation_' . $operationId) !== 1) {
            $this->redirectWithNotice('cancel_failed');
        }

        try {
            $record = $this->branchAsyncOperationCoordinator->cancel($operationId);
        } catch (RuntimeException $exception) {
            $this->redirectWithNotice('cancel_failed');
        }

        if ($record->status === OperationLogRepository::STATUS_CANCELLED) {
            $this->redirectWithNotice('cancelled');
        }

        $this->redirectWithNotice('cancel_not_running');
    }

    private function renderPrimaryNavigation(): void
    {
        echo '<nav class="nav-tab-wrapper wp-clearfix pushpull-page-nav">';
        printf(
            '<a href="%s" class="nav-tab">%s</a>',
            esc_url(admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)),
            esc_html__('Settings', 'pushpull')
        );
        printf(
            '<a href="%s" class="nav-tab">%s</a>',
            esc_url(admin_url('admin.php?page=' . DomainsPage::MENU_SLUG)),
            esc_html__('Domains', 'pushpull')
        );
        printf(
            '<a href="%s" class="nav-tab">%s</a>',
            esc_url(admin_url('admin.php?page=' . ManagedContentPage::MENU_SLUG)),
            esc_html__('Managed Content', 'pushpull')
        );
        printf(
            '<a href="%s" class="nav-tab">%s</a>',
            esc_url(admin_url('admin.php?page=' . LocalRepositoryPage::MENU_SLUG)),
            esc_html__('Sync Status', 'pushpull')
        );
        printf(
            '<a href="%s" class="nav-tab nav-tab-active">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)),
            esc_html__('Audit Log', 'pushpull')
        );
        echo '</nav>';
    }

    private function renderSecondaryNavigation(string $view): void
    {
        echo '<nav class="pushpull-subtabs" aria-label="' . esc_attr__('Audit log views', 'pushpull') . '">';
        printf(
            '<a href="%s" class="%s">%s</a>',
            esc_url($this->viewUrl('history')),
            esc_attr($view === 'history' ? 'pushpull-subtab pushpull-subtab-active' : 'pushpull-subtab'),
            esc_html__('History', 'pushpull')
        );
        printf(
            '<a href="%s" class="%s">%s</a>',
            esc_url($this->viewUrl('repository_operations')),
            esc_attr($view === 'repository_operations' ? 'pushpull-subtab pushpull-subtab-active' : 'pushpull-subtab'),
            esc_html__('Repository Operations', 'pushpull')
        );
        echo '</nav>';
    }

    /**
     * @param OperationRecord[] $records
     */
    private function renderHistory(array $records): void
    {
        if ($records === []) {
            echo '<div class="pushpull-panel">';
            echo '<p>' . esc_html__('No audit log entries have been recorded yet.', 'pushpull') . '</p>';
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
            $this->renderHistoryRow($record);
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    /**
     * @param OperationRecord[] $records
     */
    private function renderRepositoryOperations(array $records): void
    {
        $running = array_values(array_filter(
            $records,
            static fn (OperationRecord $record): bool => $record->status === OperationLogRepository::STATUS_RUNNING
        ));

        echo '<div class="pushpull-status-grid">';
        $this->statusCard(__('Running', 'pushpull'), (string) count($running));
        $this->statusCard(__('Succeeded', 'pushpull'), (string) $this->countByStatus($records, OperationLogRepository::STATUS_SUCCEEDED));
        $this->statusCard(__('Failed', 'pushpull'), (string) $this->countByStatus($records, OperationLogRepository::STATUS_FAILED));
        $this->statusCard(__('Cancelled', 'pushpull'), (string) $this->countByStatus($records, OperationLogRepository::STATUS_CANCELLED));
        echo '</div>';

        echo '<div class="pushpull-panel">';
        echo '<h2>' . esc_html__('Repository Operations', 'pushpull') . '</h2>';
        echo '<p class="description">' . esc_html__('Cancel stops a resumable async branch operation before its next chunk. It does not interrupt PHP work already executing in the current request.', 'pushpull') . '</p>';

        if ($records === []) {
            echo '<p>' . esc_html__('No repository operations have been recorded yet.', 'pushpull') . '</p>';
            echo '</div>';

            return;
        }

        echo '<table class="widefat fixed striped pushpull-operations-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Type', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Managed Set', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Status', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Progress', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Started', 'pushpull') . '</th>';
        echo '<th>' . esc_html__('Action', 'pushpull') . '</th>';
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
            printf('<td>%s</td>', wp_kses_post($this->progressMarkup($record)));
            printf('<td>%s</td>', esc_html($record->createdAt));
            echo '<td>';
            if ($this->isCancellable($record)) {
                $this->renderCancelForm($record);
            } else {
                echo '<span class="pushpull-operation-action-muted">' . esc_html__('Unavailable', 'pushpull') . '</span>';
            }
            echo '</td>';
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
    }

    private function renderHistoryRow(OperationRecord $record): void
    {
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

    private function renderCancelForm(OperationRecord $record): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pushpull-inline-form" onsubmit="return window.confirm(\'Cancel this async repository operation? Any chunk already running in the current request will finish, but no further chunks will run.\');">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::CANCEL_ACTION) . '" />';
        echo '<input type="hidden" name="operation_id" value="' . esc_attr((string) $record->id) . '" />';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('pushpull_cancel_operation_' . $record->id)) . '" />';
        echo '<button type="submit" class="button button-secondary">' . esc_html__('Cancel', 'pushpull') . '</button>';
        echo '</form>';
    }

    private function renderNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice parameter from the redirect target.
        $notice = isset($_GET['pushpull_notice']) ? sanitize_key(wp_unslash((string) $_GET['pushpull_notice'])) : '';

        if ($notice === '') {
            return;
        }

        $message = match ($notice) {
            'cancelled' => __('The repository operation was cancelled before the next async chunk ran.', 'pushpull'),
            'cancel_not_running' => __('That repository operation had already finished and could not be cancelled.', 'pushpull'),
            'cancel_failed' => __('The repository operation could not be cancelled.', 'pushpull'),
            default => '',
        };

        if ($message === '') {
            return;
        }

        $class = $notice === 'cancel_failed' ? 'notice notice-error inline' : 'notice notice-success inline';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function statusBadgeClass(OperationRecord $record): string
    {
        return match ($record->status) {
            OperationLogRepository::STATUS_SUCCEEDED => 'added',
            OperationLogRepository::STATUS_FAILED => 'deleted',
            OperationLogRepository::STATUS_CANCELLED => 'modified',
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

    private function currentView(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter for page navigation.
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : 'history';

        return in_array($view, ['history', 'repository_operations'], true) ? $view : 'history';
    }

    private function viewUrl(string $view): string
    {
        return add_query_arg([
            'page' => self::MENU_SLUG,
            'view' => $view,
        ], admin_url('admin.php'));
    }

    private function redirectWithNotice(string $notice): never
    {
        wp_safe_redirect(add_query_arg([
            'page' => self::MENU_SLUG,
            'view' => 'repository_operations',
            'pushpull_notice' => $notice,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * @param OperationRecord[] $records
     */
    private function countByStatus(array $records, string $status): int
    {
        return count(array_filter(
            $records,
            static fn (OperationRecord $record): bool => $record->status === $status
        ));
    }

    private function isCancellable(OperationRecord $record): bool
    {
        return $record->status === OperationLogRepository::STATUS_RUNNING
            && is_string($record->result['lockToken'] ?? null)
            && (string) $record->result['lockToken'] !== '';
    }

    private function progressMarkup(OperationRecord $record): string
    {
        $message = (string) ($record->result['progressMessage'] ?? $record->result['summaryMessage'] ?? '');
        $mode = (string) ($record->result['progressMode'] ?? '');
        $current = (int) ($record->result['progressCurrent'] ?? 0);
        $total = (int) ($record->result['progressTotal'] ?? 0);

        if ($mode === 'determinate' && $total > 0) {
            return sprintf(
                '<strong>%s</strong><br /><span class="pushpull-operation-meta">%d / %d</span>',
                esc_html($message !== '' ? $message : __('Running', 'pushpull')),
                $current,
                $total
            );
        }

        if ($message !== '') {
            return sprintf(
                '<strong>%s</strong><br /><span class="pushpull-operation-meta">%s</span>',
                esc_html($message),
                esc_html($mode !== '' ? ucfirst($mode) : ucfirst($record->status))
            );
        }

        return sprintf(
            '<span class="pushpull-operation-meta">%s</span>',
            esc_html(ucfirst($record->status))
        );
    }

    private function statusCard(string $label, string $value): void
    {
        echo '<div class="pushpull-status-card">';
        echo '<h2>' . esc_html($label) . '</h2>';
        echo '<p class="pushpull-status-card__value">' . esc_html($value) . '</p>';
        echo '</div>';
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
