<?php

declare(strict_types=1);

namespace PushPull\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use PushPull\Content\ManagedSetRegistry;
use PushPull\Domain\Diff\RepositoryRelationship;
use PushPull\Domain\Sync\SyncServiceInterface;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\Capabilities;
use Throwable;
use WP_Admin_Bar;

final class AdminBarStatus
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly ManagedSetRegistry $managedSetRegistry,
        private readonly SyncServiceInterface $syncService
    ) {
    }

    public function register(WP_Admin_Bar $adminBar): void
    {
        if (! is_admin_bar_showing() || ! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            return;
        }

        $settings = $this->settingsRepository->get();
        $enabledManagedSetKeys = $this->enabledManagedSetKeys($settings);

        if ($enabledManagedSetKeys === []) {
            return;
        }

        $summary = $this->buildSummary($enabledManagedSetKeys);

        $adminBar->add_node([
            'id' => 'pushpull-status',
            'title' => sprintf(
                'PushPull %s',
                esc_html($this->topLabel($summary['liveLocalChangedSets'], $summary['localRemoteChangedSets'], $summary['unavailableSets']))
            ),
            'href' => admin_url('admin.php?page=' . ManagedContentPage::MENU_SLUG),
            'meta' => [
                'title' => __('Open PushPull Managed Content', 'pushpull'),
            ],
        ]);

        $adminBar->add_node([
            'id' => 'pushpull-status-live-local',
            'parent' => 'pushpull-status',
            'title' => esc_html(sprintf(
                'Live vs local: %d changed set(s)',
                $summary['liveLocalChangedSets']
            )),
            'href' => admin_url('admin.php?page=' . ManagedContentPage::MENU_SLUG),
        ]);

        $adminBar->add_node([
            'id' => 'pushpull-status-local-remote',
            'parent' => 'pushpull-status',
            'title' => esc_html(sprintf(
                'Local vs remote: %d changed set(s)',
                $summary['localRemoteChangedSets']
            )),
            'href' => admin_url('admin.php?page=' . ManagedContentPage::MENU_SLUG),
        ]);

        $adminBar->add_node([
            'id' => 'pushpull-status-branch',
            'parent' => 'pushpull-status',
            'title' => esc_html(sprintf(
                'Branch: %s',
                $settings->branch
            )),
            'href' => admin_url('admin.php?page=' . ManagedContentPage::MENU_SLUG),
        ]);

        $adminBar->add_node([
            'id' => 'pushpull-status-enabled',
            'parent' => 'pushpull-status',
            'title' => esc_html(sprintf(
                'Enabled domains: %d',
                count($enabledManagedSetKeys)
            )),
            'href' => admin_url('admin.php?page=' . ManagedContentPage::MENU_SLUG),
        ]);

        if ($summary['divergedSets'] > 0) {
            $adminBar->add_node([
                'id' => 'pushpull-status-conflicts',
                'parent' => 'pushpull-status',
                'title' => esc_html(sprintf(
                    'Diverged sets: %d',
                    $summary['divergedSets']
                )),
                'href' => admin_url('admin.php?page=' . ManagedContentPage::MENU_SLUG),
            ]);
        }

        if ($summary['unavailableSets'] > 0) {
            $adminBar->add_node([
                'id' => 'pushpull-status-unavailable',
                'parent' => 'pushpull-status',
                'title' => esc_html(sprintf(
                    'Unavailable status: %d set(s)',
                    $summary['unavailableSets']
                )),
                'href' => admin_url('admin.php?page=' . ManagedContentPage::MENU_SLUG),
            ]);
        }

        $adminBar->add_node([
            'id' => 'pushpull-status-managed-content',
            'parent' => 'pushpull-status',
            'title' => esc_html__('Managed Content', 'pushpull'),
            'href' => admin_url('admin.php?page=' . ManagedContentPage::MENU_SLUG),
        ]);

        $adminBar->add_node([
            'id' => 'pushpull-status-audit-log',
            'parent' => 'pushpull-status',
            'title' => esc_html__('Audit Log', 'pushpull'),
            'href' => admin_url('admin.php?page=' . OperationsPage::MENU_SLUG),
        ]);
    }

    private function topLabel(int $liveLocalChangedSets, int $localRemoteChangedSets, int $unavailableSets): string
    {
        if ($unavailableSets > 0) {
            // translators: 1: live vs local changed set count, 2: local vs remote changed set count, 3: unavailable set count.
            $label = __('%1$d local / %2$d remote / %3$d unavailable', 'pushpull');

            return sprintf(
                $label,
                $liveLocalChangedSets,
                $localRemoteChangedSets,
                $unavailableSets
            );
        }

        if ($liveLocalChangedSets === 0 && $localRemoteChangedSets === 0) {
            return __('clean', 'pushpull');
        }

        // translators: 1: live vs local changed set count, 2: local vs remote changed set count.
        $label = __('%1$d local / %2$d remote', 'pushpull');

        return sprintf(
            $label,
            $liveLocalChangedSets,
            $localRemoteChangedSets
        );
    }

    /**
     * @param string[] $enabledManagedSetKeys
     * @return array{liveLocalChangedSets: int, localRemoteChangedSets: int, divergedSets: int, unavailableSets: int}
     */
    private function buildSummary(array $enabledManagedSetKeys): array
    {
        $summary = [
            'liveLocalChangedSets' => 0,
            'localRemoteChangedSets' => 0,
            'divergedSets' => 0,
            'unavailableSets' => 0,
        ];

        foreach ($enabledManagedSetKeys as $managedSetKey) {
            try {
                $diffResult = $this->syncService->diff($managedSetKey);
            } catch (Throwable) {
                $summary['unavailableSets']++;
                continue;
            }

            if ($diffResult->liveToLocal->hasChanges()) {
                $summary['liveLocalChangedSets']++;
            }

            if ($diffResult->localToRemote->hasChanges()) {
                $summary['localRemoteChangedSets']++;
            }

            if ($diffResult->repositoryRelationship->status === RepositoryRelationship::DIVERGED) {
                $summary['divergedSets']++;
            }
        }

        return $summary;
    }

    /**
     * @return string[]
     */
    private function enabledManagedSetKeys(\PushPull\Settings\PushPullSettings $settings): array
    {
        $enabledManagedSetKeys = [];

        foreach ($this->managedSetRegistry->allInDependencyOrder() as $managedSetKey => $_adapter) {
            if ($settings->isManagedSetEnabled($managedSetKey)) {
                $enabledManagedSetKeys[] = $managedSetKey;
            }
        }

        return $enabledManagedSetKeys;
    }
}
