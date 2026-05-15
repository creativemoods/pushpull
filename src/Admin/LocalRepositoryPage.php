<?php

declare(strict_types=1);

namespace PushPull\Admin;

use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Settings\PushPullSettings;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\Capabilities;

final class LocalRepositoryPage
{
    public const MENU_SLUG = 'pushpull-local-repository';
    private const HEADER_LOGO_PATH = 'plugin-assets/images/pushpull_logo_transp.png';
    private const BRANCH_COMMIT_STACK_LIMIT = 12;

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly LocalRepositoryInterface $localRepository,
        private readonly ManagedContentPage $managedContentPage
    ) {
    }

    public function register(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Sync Status', 'pushpull'),
            __('Sync Status', 'pushpull'),
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

        wp_enqueue_script(
            'pushpull-managed-content',
            PUSHPULL_PLUGIN_URL . 'plugin-assets/js/managed-content.js',
            [],
            PUSHPULL_VERSION,
            true
        );
        wp_localize_script('pushpull-managed-content', 'pushpullManagedContent', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'ajaxNonce' => wp_create_nonce('pushpull_async_branch_action'),
            'pageSlug' => self::MENU_SLUG,
            'strings' => [
                'working' => __('Working…', 'pushpull'),
                'close' => __('Close', 'pushpull'),
                'failed' => __('The PushPull operation could not be completed.', 'pushpull'),
                'checkingStatus' => __('Connection interrupted while checking PushPull progress. Retrying operation status…', 'pushpull'),
                'commitMessageTitle' => __('Commit Message', 'pushpull'),
                'commitMessageHelp' => __('Review or replace the commit message for this bulk branch commit.', 'pushpull'),
                'commitMessageLabel' => __('Commit message', 'pushpull'),
                'commitMessageConfirm' => __('Continue', 'pushpull'),
                'cancel' => __('Cancel', 'pushpull'),
                /* translators: %d: completion percentage. */
                'progressPercent' => __('%d% complete', 'pushpull'),
            ],
        ]);
    }

    public function render(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        $settings = $this->settingsRepository->get();
        $commitState = $this->branchCommitVisibilityState($settings);
        $branchActionState = $this->managedContentPage->branchActionState($settings);
        $graphState = $this->branchGraphState($commitState);

        echo '<div class="wrap pushpull-admin">';
        echo '<div class="pushpull-page-header">';
        echo '<div class="pushpull-page-header__content">';
        echo '<h1>' . esc_html__('Sync Status', 'pushpull') . '</h1>';
        echo '<p class="pushpull-intro">' . esc_html__('Review the local branch, the fetched remote-tracking branch, and the commit stacks that are waiting to be pushed or pulled before you run branch actions.', 'pushpull') . '</p>';
        echo '</div>';
        printf(
            '<div class="pushpull-page-logo"><img src="%s" alt="%s" /></div>',
            esc_url(PUSHPULL_PLUGIN_URL . self::HEADER_LOGO_PATH),
            esc_attr__('PushPull', 'pushpull')
        );
        echo '</div>';

        $this->renderPrimaryNavigation($settings);
        $this->renderBranchActionSummary($settings, $commitState, $branchActionState);
        $this->renderBranchGraphPanel($graphState, $branchActionState, $commitState);
        $this->managedContentPage->renderAsyncOperationModal();

        echo '</div>';
    }

    /**
     * @param array{
     *   localHead: ?\PushPull\Domain\Repository\Commit,
     *   remoteHead: ?\PushPull\Domain\Repository\Commit,
     *   outgoing: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int},
     *   incoming: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int}
     * } $commitState
     * @param array{
     *   managedSetKey: ?string,
     *   relationship: ?string,
     *   hasLiveToLocalChanges: bool,
     *   hasLocalToRemoteChanges: bool,
     *   hasAvailableManagedSet: bool,
     *   hasAvailableDiff: bool,
     *   commitPushAll: array{enabled: bool, reason: ?string},
     *   pullApplyAll: array{enabled: bool, reason: ?string},
     *   fetch: array{enabled: bool, reason: ?string},
     *   pull: array{enabled: bool, reason: ?string},
     *   merge: array{enabled: bool, reason: ?string},
     *   push: array{enabled: bool, reason: ?string}
     * } $branchActionState
     */
    private function renderBranchActionSummary(PushPullSettings $settings, array $commitState, array $branchActionState): void
    {
        echo '<div class="pushpull-status-grid">';
        $this->statusCard(__('Branch', 'pushpull'), $settings->branch);
        $this->statusCard(__('Ahead / behind', 'pushpull'), $this->relationshipLabel($branchActionState['relationship'], $branchActionState['hasAvailableDiff']));
        $this->statusCard(__('Outgoing commits', 'pushpull'), (string) $commitState['outgoing']['total']);
        $this->statusCard(__('Incoming commits', 'pushpull'), (string) $commitState['incoming']['total']);
        $this->statusCard(__('Live changes pending', 'pushpull'), $branchActionState['hasLiveToLocalChanges'] ? __('Yes', 'pushpull') : __('No', 'pushpull'));
        $this->statusCard(__('Remote push pending', 'pushpull'), $branchActionState['hasLocalToRemoteChanges'] ? __('Yes', 'pushpull') : __('No', 'pushpull'));
        echo '</div>';
    }

    /**
     * @param array{
     *   localOnly: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int},
     *   remoteOnly: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int},
     *   shared: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int},
     *   commonAncestor: ?\PushPull\Domain\Repository\Commit,
     *   hasCommonAncestor: bool
     * } $graphState
     * @param array{
     *   managedSetKey: ?string,
     *   relationship: ?string,
     *   hasLiveToLocalChanges: bool,
     *   hasLocalToRemoteChanges: bool,
     *   hasAvailableManagedSet: bool,
     *   hasAvailableDiff: bool,
     *   commitPushAll: array{enabled: bool, reason: ?string},
     *   pullApplyAll: array{enabled: bool, reason: ?string},
     *   fetch: array{enabled: bool, reason: ?string},
     *   pull: array{enabled: bool, reason: ?string},
     *   merge: array{enabled: bool, reason: ?string},
     *   push: array{enabled: bool, reason: ?string}
     * } $branchActionState
     * @param array{
     *   localHead: ?\PushPull\Domain\Repository\Commit,
     *   remoteHead: ?\PushPull\Domain\Repository\Commit,
     *   outgoing: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int},
     *   incoming: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int}
     * } $commitState
     */
    private function renderBranchGraphPanel(array $graphState, array $branchActionState, array $commitState): void
    {
        $localHeadHash = $commitState['localHead']?->hash;
        $remoteHeadHash = $commitState['remoteHead']?->hash;
        $commonAncestorHash = $graphState['commonAncestor']?->hash;
        $outgoingTotal = $commitState['outgoing']['total'];
        $incomingTotal = $commitState['incoming']['total'];

        echo '<div class="pushpull-panel">';
        echo '<h2>' . esc_html__('Branch Graph', 'pushpull') . '</h2>';
        echo '<p class="description">' . esc_html__('This simplified branch view shows the shared first-parent history, where local and remote-tracking split, and which commits are next to push or pull.', 'pushpull') . '</p>';

        echo '<div class="pushpull-status-grid">';
        $this->renderFlowCard(
            __('Outgoing', 'pushpull'),
            $outgoingTotal > 0
                ? sprintf(
                    /* translators: %d: number of outgoing commits not yet pushed. */
                    __('%d commit(s) ready to push', 'pushpull'),
                    $outgoingTotal
                )
                : __('Nothing to push', 'pushpull'),
            $this->latestCommitSummary(
                $commitState['outgoing']['commits'],
                __('Local branch already matches remote-tracking.', 'pushpull')
            )
        );
        $this->renderFlowCard(
            __('Incoming', 'pushpull'),
            $incomingTotal > 0
                ? sprintf(
                    /* translators: %d: number of fetched incoming commits ready to pull. */
                    __('%d fetched commit(s) ready to pull', 'pushpull'),
                    $incomingTotal
                )
                : __('Nothing to pull', 'pushpull'),
            $this->latestCommitSummary(
                $commitState['incoming']['commits'],
                __('Local branch already includes fetched remote-tracking commits.', 'pushpull')
            )
        );
        $this->renderFlowCard(
            __('Topology', 'pushpull'),
            $graphState['hasCommonAncestor']
                ? __('Local and remote-tracking share history.', 'pushpull')
                : __('Local and remote-tracking do not share a common ancestor.', 'pushpull'),
            $this->topologyDetail($graphState, $branchActionState)
        );
        echo '</div>';

        if (! $graphState['hasCommonAncestor'] && $commitState['localHead'] !== null && $commitState['remoteHead'] !== null) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Local and remote-tracking histories do not share a common ancestor. Pull will not be able to fast-forward and may require a reset or manual reconciliation.', 'pushpull') . '</p></div>';
        }

        echo '<div class="pushpull-branch-graph">';
        echo '<div class="pushpull-branch-graph__headers">';
        echo '<div class="pushpull-branch-graph__header">' . esc_html__('Local', 'pushpull') . '</div>';
        echo '<div class="pushpull-branch-graph__header">' . esc_html__('Shared History', 'pushpull') . '</div>';
        echo '<div class="pushpull-branch-graph__header">' . esc_html__('Remote Tracking', 'pushpull') . '</div>';
        echo '</div>';

        if ($graphState['hasCommonAncestor']) {
            echo '<div class="pushpull-branch-graph__lanes pushpull-branch-graph__lanes--top">';
            $this->renderGraphLane(
                'local',
                $graphState['localOnly'],
                $localHeadHash,
                $remoteHeadHash,
                $commonAncestorHash,
                $outgoingTotal > 0 ? __('Will push these', 'pushpull') : null
            );
            echo '<div class="pushpull-branch-graph__split-note">';
            echo '<div class="pushpull-branch-graph__split-label">' . esc_html__('Branch split', 'pushpull') . '</div>';
            echo '<p class="pushpull-branch-graph__split-text">' . esc_html__('Local-only and remote-only commits branch from the common ancestor shown below.', 'pushpull') . '</p>';
            echo '</div>';
            $this->renderGraphLane(
                'remote',
                $graphState['remoteOnly'],
                $localHeadHash,
                $remoteHeadHash,
                $commonAncestorHash,
                $incomingTotal > 0 ? __('Will pull these', 'pushpull') : null
            );
            echo '</div>';

            echo '<div class="pushpull-branch-graph__junction">';
            echo '<div class="pushpull-branch-graph__junction-arm' . ($graphState['localOnly']['total'] > 0 ? ' is-active' : '') . '"></div>';
            echo '<div class="pushpull-branch-graph__junction-center">' . esc_html__('Common ancestor', 'pushpull') . '</div>';
            echo '<div class="pushpull-branch-graph__junction-arm' . ($graphState['remoteOnly']['total'] > 0 ? ' is-active' : '') . '"></div>';
            echo '</div>';

            echo '<div class="pushpull-branch-graph__lanes pushpull-branch-graph__lanes--bottom">';
            echo '<div class="pushpull-branch-graph__spacer" aria-hidden="true"></div>';
            $this->renderGraphLane(
                'shared',
                $graphState['shared'],
                $localHeadHash,
                $remoteHeadHash,
                $commonAncestorHash,
                null
            );
            echo '<div class="pushpull-branch-graph__spacer" aria-hidden="true"></div>';
            echo '</div>';
        } else {
            echo '<div class="pushpull-branch-graph__lanes">';
            $this->renderGraphLane(
                'local',
                $graphState['localOnly'],
                $localHeadHash,
                $remoteHeadHash,
                $commonAncestorHash,
                $outgoingTotal > 0 ? __('Will push these', 'pushpull') : null
            );
            $this->renderGraphLane(
                'shared',
                $graphState['shared'],
                $localHeadHash,
                $remoteHeadHash,
                $commonAncestorHash,
                null
            );
            $this->renderGraphLane(
                'remote',
                $graphState['remoteOnly'],
                $localHeadHash,
                $remoteHeadHash,
                $commonAncestorHash,
                $incomingTotal > 0 ? __('Will pull these', 'pushpull') : null
            );
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }

    private function statusCard(string $label, string $value): void
    {
        echo '<div class="pushpull-status-card">';
        echo '<p class="pushpull-status-card__label">' . esc_html($label) . '</p>';
        echo '<p class="pushpull-status-card__value">' . esc_html($value) . '</p>';
        echo '</div>';
    }

    private function renderFlowCard(string $label, string $value, string $detail): void
    {
        echo '<div class="pushpull-status-card">';
        echo '<p class="pushpull-status-card__label">' . esc_html($label) . '</p>';
        echo '<p class="pushpull-status-card__value pushpull-status-card__value--compact">' . esc_html($value) . '</p>';
        echo '<p class="pushpull-status-card__detail">' . esc_html($detail) . '</p>';
        echo '</div>';
    }

    /**
     * @param array<int, \PushPull\Domain\Repository\Commit> $commits
     */
    private function latestCommitSummary(array $commits, string $fallback): string
    {
        if ($commits === []) {
            return $fallback;
        }

        $commit = $commits[count($commits) - 1];

        return $commit->message !== '' ? $commit->message : __('No commit message', 'pushpull');
    }

    private function relationshipLabel(?string $relationship, bool $hasAvailableDiff): string
    {
        if (! $hasAvailableDiff) {
            return __('Unavailable', 'pushpull');
        }

        return match ($relationship) {
            'ahead' => __('Ahead', 'pushpull'),
            'behind' => __('Behind', 'pushpull'),
            'diverged' => __('Diverged', 'pushpull'),
            'remote_only' => __('Remote only', 'pushpull'),
            'in_sync' => __('In sync', 'pushpull'),
            default => __('Unavailable', 'pushpull'),
        };
    }

    /**
     * @param array{
     *   localOnly: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int},
     *   remoteOnly: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int},
     *   shared: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int},
     *   commonAncestor: ?\PushPull\Domain\Repository\Commit,
     *   hasCommonAncestor: bool
     * } $graphState
     * @param array{
     *   managedSetKey: ?string,
     *   relationship: ?string,
     *   hasLiveToLocalChanges: bool,
     *   hasLocalToRemoteChanges: bool,
     *   hasAvailableManagedSet: bool,
     *   hasAvailableDiff: bool,
     *   commitPushAll: array{enabled: bool, reason: ?string},
     *   pullApplyAll: array{enabled: bool, reason: ?string},
     *   fetch: array{enabled: bool, reason: ?string},
     *   pull: array{enabled: bool, reason: ?string},
     *   merge: array{enabled: bool, reason: ?string},
     *   push: array{enabled: bool, reason: ?string}
     * } $branchActionState
     */
    private function topologyDetail(array $graphState, array $branchActionState): string
    {
        if (! $graphState['hasCommonAncestor']) {
            return __('The two histories are separate.', 'pushpull');
        }

        if ($graphState['commonAncestor'] === null) {
            return __('No commits have been fetched yet.', 'pushpull');
        }

        return match ($branchActionState['relationship']) {
            'ahead' => __('Local is ahead of remote-tracking from the shared ancestor.', 'pushpull'),
            'behind' => __('Remote-tracking is ahead of local from the shared ancestor.', 'pushpull'),
            'diverged' => __('Both branches moved forward after the shared ancestor.', 'pushpull'),
            'remote_only' => __('Only the remote-tracking side has commits beyond the shared history.', 'pushpull'),
            default => __('Both refs currently point into the same shared history.', 'pushpull'),
        };
    }

    /**
     * @param array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int} $lane
     */
    private function renderGraphLane(string $type, array $lane, ?string $localHeadHash, ?string $remoteHeadHash, ?string $commonAncestorHash, ?string $tipLabel): void
    {
        $emptyText = match ($type) {
            'local' => __('No local-only commits', 'pushpull'),
            'remote' => __('No remote-only commits', 'pushpull'),
            default => __('No shared commits', 'pushpull'),
        };

        echo '<div class="pushpull-branch-graph__lane pushpull-branch-graph__lane--' . esc_attr($type) . '">';

        if ($tipLabel !== null && $lane['total'] > 0) {
            echo '<div class="pushpull-branch-graph__tip">' . esc_html($tipLabel) . '</div>';
        }

        if ($lane['commits'] === []) {
            echo '<div class="pushpull-branch-graph__empty">' . esc_html($emptyText) . '</div>';
            echo '</div>';

            return;
        }

        if ($lane['hidden'] > 0) {
            printf(
                '<div class="pushpull-branch-graph__truncation">%s</div>',
                /* translators: 1: number of visible commits, 2: total commits in the lane. */
                esc_html(sprintf(__('Showing the newest %1$d of %2$d', 'pushpull'), count($lane['commits']), $lane['total']))
            );
        }

        foreach ($lane['commits'] as $commit) {
            $this->renderGraphCommitNode($commit, $localHeadHash, $remoteHeadHash, $commonAncestorHash);
        }

        echo '</div>';
    }

    private function renderGraphCommitNode(\PushPull\Domain\Repository\Commit $commit, ?string $localHeadHash, ?string $remoteHeadHash, ?string $commonAncestorHash): void
    {
        $summary = $commit->message !== '' ? $commit->message : __('No commit message', 'pushpull');
        $meta = $commit->authorName !== ''
            ? sprintf('%s, %s', $commit->authorName, $commit->committedAt)
            : $commit->committedAt;

        echo '<div class="pushpull-branch-graph__node">';
        echo '<div class="pushpull-branch-graph__node-line"></div>';
        echo '<div class="pushpull-branch-graph__node-card">';
        echo '<div class="pushpull-branch-graph__node-badges">';

        if ($commit->hash === $localHeadHash) {
            echo '<span class="pushpull-branch-graph__badge pushpull-branch-graph__badge--local">' . esc_html__('LOCAL HEAD', 'pushpull') . '</span>';
        }

        if ($commit->hash === $remoteHeadHash) {
            echo '<span class="pushpull-branch-graph__badge pushpull-branch-graph__badge--remote">' . esc_html__('REMOTE HEAD', 'pushpull') . '</span>';
        }

        if ($commit->hash === $commonAncestorHash) {
            echo '<span class="pushpull-branch-graph__badge pushpull-branch-graph__badge--shared">' . esc_html__('COMMON ANCESTOR', 'pushpull') . '</span>';
        }

        if ($commit->secondParentHash !== null) {
            echo '<span class="pushpull-branch-graph__badge pushpull-branch-graph__badge--merge">' . esc_html__('MERGE', 'pushpull') . '</span>';
        }

        echo '</div>';
        echo '<strong class="pushpull-branch-graph__node-title">' . esc_html($summary) . '</strong>';
        printf('<div class="pushpull-branch-graph__node-hash"><code>%s</code></div>', esc_html(substr($commit->hash, 0, 12)));
        echo '<div class="pushpull-branch-graph__node-meta">' . esc_html($meta) . '</div>';
        echo '</div>';
        echo '</div>';
    }

    private function renderPrimaryNavigation(PushPullSettings $settings): void
    {
        echo '<div class="pushpull-page-nav-row">';
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
            '<a href="%s" class="nav-tab nav-tab-active">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)),
            esc_html__('Sync Status', 'pushpull')
        );
        printf(
            '<a href="%s" class="nav-tab">%s</a>',
            esc_url(admin_url('admin.php?page=' . OperationsPage::MENU_SLUG)),
            esc_html__('Audit Log', 'pushpull')
        );
        echo '</nav>';
        $this->managedContentPage->renderBranchActionToolbar($settings);
        echo '</div>';
        echo '<p class="description pushpull-top-actions-note">' . esc_html__('Branch actions operate on the whole branch. Pull runs Fetch + Merge, and Merge brings fetched remote-tracking changes into the local branch.', 'pushpull') . '</p>';
    }

    /**
     * @return array{
     *   localHead: ?\PushPull\Domain\Repository\Commit,
     *   remoteHead: ?\PushPull\Domain\Repository\Commit,
     *   outgoing: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int},
     *   incoming: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int}
     * }
     */
    private function branchCommitVisibilityState(PushPullSettings $settings): array
    {
        $localHead = $this->localRepository->getHeadCommit($settings->branch);
        $remoteHeadHash = $this->localRepository->getRef('refs/remotes/origin/' . $settings->branch)?->commitHash;
        $remoteHead = is_string($remoteHeadHash) && $remoteHeadHash !== ''
            ? $this->localRepository->getCommit($remoteHeadHash)
            : null;
        $localAncestors = $this->ancestorHashSet($localHead?->hash);
        $remoteAncestors = $this->ancestorHashSet($remoteHead?->hash);

        return [
            'localHead' => $localHead,
            'remoteHead' => $remoteHead,
            'outgoing' => $this->firstParentCommitWindow(
                $localHead?->hash,
                $remoteAncestors,
                self::BRANCH_COMMIT_STACK_LIMIT
            ),
            'incoming' => $this->firstParentCommitWindow(
                $remoteHead?->hash,
                $localAncestors,
                self::BRANCH_COMMIT_STACK_LIMIT
            ),
        ];
    }

    /**
     * @param array{
     *   localHead: ?\PushPull\Domain\Repository\Commit,
     *   remoteHead: ?\PushPull\Domain\Repository\Commit,
     *   outgoing: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int},
     *   incoming: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int}
     * } $commitState
     * @return array{
     *   localOnly: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int},
     *   remoteOnly: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int},
     *   shared: array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int},
     *   commonAncestor: ?\PushPull\Domain\Repository\Commit,
     *   hasCommonAncestor: bool
     * }
     */
    private function branchGraphState(array $commitState): array
    {
        $localHeadHash = $commitState['localHead']?->hash;
        $remoteHeadHash = $commitState['remoteHead']?->hash;
        $localAncestors = $this->ancestorHashSet($localHeadHash);
        $remoteAncestors = $this->ancestorHashSet($remoteHeadHash);
        $commonAncestorHash = null;

        if ($localHeadHash !== null && isset($remoteAncestors[$localHeadHash])) {
            $commonAncestorHash = $localHeadHash;
        } elseif ($remoteHeadHash !== null && isset($localAncestors[$remoteHeadHash])) {
            $commonAncestorHash = $remoteHeadHash;
        } else {
            $commonAncestorHash = $this->nearestCommonAncestorHash($localHeadHash, $remoteHeadHash);
        }

        return [
            'localOnly' => $this->graphCommitSliceUntilAncestorSet($localHeadHash, $remoteAncestors, 6),
            'remoteOnly' => $this->graphCommitSliceUntilAncestorSet($remoteHeadHash, $localAncestors, 6),
            'shared' => $commonAncestorHash !== null
                ? $this->graphCommitSliceFrom($commonAncestorHash, 6)
                : ['commits' => [], 'total' => 0, 'hidden' => 0],
            'commonAncestor' => $commonAncestorHash !== null ? $this->localRepository->getCommit($commonAncestorHash) : null,
            'hasCommonAncestor' => $commonAncestorHash !== null,
        ];
    }

    private function nearestCommonAncestorHash(?string $localHeadHash, ?string $remoteHeadHash): ?string
    {
        if (! is_string($localHeadHash) || $localHeadHash === '' || ! is_string($remoteHeadHash) || $remoteHeadHash === '') {
            return null;
        }

        $localHashes = $this->ancestorHashSet($localHeadHash);
        $currentHash = $remoteHeadHash;
        $seen = [];

        while (is_string($currentHash) && $currentHash !== '' && ! isset($seen[$currentHash])) {
            if (isset($localHashes[$currentHash])) {
                return $currentHash;
            }

            $seen[$currentHash] = true;
            $commit = $this->localRepository->getCommit($currentHash);

            if (! $commit instanceof \PushPull\Domain\Repository\Commit) {
                break;
            }

            $currentHash = $commit->parentHash;
        }

        return null;
    }

    /**
     * @return array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int}
     */
    private function graphCommitSliceUntilAncestorSet(?string $headHash, array $stopHashes, int $limit): array
    {
        $commits = [];
        $total = 0;
        $currentHash = $headHash;
        $seen = [];

        while (
            is_string($currentHash)
            && $currentHash !== ''
            && ! isset($stopHashes[$currentHash])
            && ! isset($seen[$currentHash])
        ) {
            $seen[$currentHash] = true;
            $commit = $this->localRepository->getCommit($currentHash);

            if (! $commit instanceof \PushPull\Domain\Repository\Commit) {
                break;
            }

            $total++;

            if (count($commits) < $limit) {
                $commits[] = $commit;
            }

            $currentHash = $commit->parentHash;
        }

        return [
            'commits' => $commits,
            'total' => $total,
            'hidden' => max(0, $total - count($commits)),
        ];
    }

    /**
     * @return array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int, hidden: int}
     */
    private function graphCommitSliceFrom(?string $headHash, int $limit): array
    {
        $commits = [];
        $total = 0;
        $currentHash = $headHash;
        $seen = [];

        while (is_string($currentHash) && $currentHash !== '' && ! isset($seen[$currentHash])) {
            $seen[$currentHash] = true;
            $commit = $this->localRepository->getCommit($currentHash);

            if (! $commit instanceof \PushPull\Domain\Repository\Commit) {
                break;
            }

            $total++;

            if (count($commits) < $limit) {
                $commits[] = $commit;
            }

            $currentHash = $commit->parentHash;
        }

        return [
            'commits' => $commits,
            'total' => $total,
            'hidden' => max(0, $total - count($commits)),
        ];
    }

    /**
     * @param array<string, true> $stopHashes
     * @return array{commits: array<int, \PushPull\Domain\Repository\Commit>, total: int}
     */
    private function firstParentCommitWindow(?string $headHash, array $stopHashes, int $limit): array
    {
        $commits = [];
        $total = 0;
        $currentHash = $headHash;
        $seen = [];

        while (is_string($currentHash) && $currentHash !== '' && ! isset($stopHashes[$currentHash]) && ! isset($seen[$currentHash])) {
            $seen[$currentHash] = true;
            $commit = $this->localRepository->getCommit($currentHash);

            if (! $commit instanceof \PushPull\Domain\Repository\Commit) {
                break;
            }

            $total++;

            if (count($commits) < $limit) {
                $commits[] = $commit;
            }

            $currentHash = $commit->parentHash;
        }

        return [
            'commits' => array_reverse($commits),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, true>
     */
    private function firstParentHashSet(?string $headHash): array
    {
        $hashes = [];
        $currentHash = $headHash;

        while (is_string($currentHash) && $currentHash !== '' && ! isset($hashes[$currentHash])) {
            $hashes[$currentHash] = true;
            $commit = $this->localRepository->getCommit($currentHash);

            if (! $commit instanceof \PushPull\Domain\Repository\Commit) {
                break;
            }

            $currentHash = $commit->parentHash;
        }

        return $hashes;
    }

    /**
     * @return array<string, true>
     */
    private function ancestorHashSet(?string $headHash): array
    {
        $hashes = [];

        if (! is_string($headHash) || $headHash === '') {
            return $hashes;
        }

        $queue = [$headHash];

        while ($queue !== []) {
            $currentHash = array_shift($queue);

            if (! is_string($currentHash) || $currentHash === '' || isset($hashes[$currentHash])) {
                continue;
            }

            $hashes[$currentHash] = true;
            $commit = $this->localRepository->getCommit($currentHash);

            if (! $commit instanceof \PushPull\Domain\Repository\Commit) {
                continue;
            }

            foreach ([$commit->parentHash, $commit->secondParentHash] as $parentHash) {
                if (is_string($parentHash) && $parentHash !== '') {
                    $queue[] = $parentHash;
                }
            }
        }

        return $hashes;
    }
}
