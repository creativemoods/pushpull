<?php

declare(strict_types=1);

namespace PushPull\Admin;

use PushPull\Content\ManifestManagedContentAdapterInterface;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Domain\Diff\CanonicalDiffResult;
use PushPull\Domain\Diff\ManagedSetDiffResult;
use PushPull\Domain\Merge\ManagedSetConflictResolutionService;
use PushPull\Domain\Merge\MergeConflict;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\SyncServiceInterface;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Provider\Exception\ProviderException;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\Capabilities;
use PushPull\Support\Operations\AsyncBranchOperationRunner;
use PushPull\Support\Operations\OperationExecutor;
use RuntimeException;

final class ManagedContentPage
{
    public const MENU_SLUG = 'pushpull-managed-content';
    private const COMMIT_ACTION = 'pushpull_commit_managed_set';
    private const PULL_ACTION = 'pushpull_pull_managed_set';
    private const FETCH_ACTION = 'pushpull_fetch_managed_set';
    private const MERGE_ACTION = 'pushpull_merge_managed_set';
    private const APPLY_ACTION = 'pushpull_apply_managed_set';
    private const PUSH_ACTION = 'pushpull_push_managed_set';
    private const RESET_REMOTE_ACTION = 'pushpull_reset_remote_managed_set';
    private const RESOLVE_CONFLICT_ACTION = 'pushpull_resolve_conflict_managed_set';
    private const FINALIZE_MERGE_ACTION = 'pushpull_finalize_merge_managed_set';

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly LocalRepositoryInterface $localRepository,
        private readonly ManagedSetRegistry $managedSetRegistry,
        private readonly SyncServiceInterface $syncService,
        private readonly WorkingStateRepository $workingStateRepository,
        private readonly ManagedSetConflictResolutionService $conflictResolutionService,
        private readonly OperationExecutor $operationExecutor,
        private readonly AsyncBranchOperationRunner $asyncBranchOperationRunner
    ) {
    }

    public function register(): void
    {
        add_submenu_page(
            SettingsPage::MENU_SLUG,
            __('Managed Content', 'pushpull'),
            __('Managed Content', 'pushpull'),
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
            'strings' => [
                'working' => __('Working…', 'pushpull'),
                'close' => __('Close', 'pushpull'),
                'failed' => __('The PushPull operation could not be completed.', 'pushpull'),
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
        $commitNotice = $this->commitNotice();

        echo '<div class="wrap pushpull-admin">';
        echo '<h1>' . esc_html__('Managed Content', 'pushpull') . '</h1>';
        echo '<p class="pushpull-intro">' . esc_html__('Review managed content state across all enabled domains, then drill into a specific managed set for fetch, merge, apply, commit, and push actions.', 'pushpull') . '</p>';
        $this->renderPrimaryNavigation($settings);
        $this->renderManagedSetTabs($this->requestManagedSetKey());
        if ($commitNotice !== null) {
            printf(
                '<div class="notice notice-%1$s"><p>%2$s</p></div>',
                esc_attr($commitNotice['type']),
                esc_html($commitNotice['message'])
            );
        }
        if ($this->isOverviewMode()) {
            $this->renderOverview($settings);
        } else {
            $this->renderManagedSetDetail($settings, $this->currentAdapter());
        }
        $this->renderAsyncOperationModal();
        echo '</div>';
    }

    private function renderPrimaryNavigation(\PushPull\Settings\PushPullSettings $settings): void
    {
        $managedSetKey = $this->branchActionManagedSetKey($settings);
        $enabled = $managedSetKey !== null;

        echo '<div class="pushpull-page-nav-row">';
        echo '<nav class="nav-tab-wrapper wp-clearfix pushpull-page-nav">';
        printf(
            '<a href="%s" class="nav-tab">%s</a>',
            esc_url(admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)),
            esc_html__('Settings', 'pushpull')
        );
        printf(
            '<a href="%s" class="nav-tab nav-tab-active">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)),
            esc_html__('Managed Content', 'pushpull')
        );
        printf(
            '<a href="%s" class="nav-tab">%s</a>',
            esc_url(admin_url('admin.php?page=' . OperationsPage::MENU_SLUG)),
            esc_html__('Audit Log', 'pushpull')
        );
        echo '</nav>';
        echo '<div class="pushpull-top-actions">';
        $this->renderPullButton($managedSetKey, $enabled);
        $this->renderFetchButton($managedSetKey, $enabled);
        $this->renderPushButton($managedSetKey, $enabled);
        echo '</div>';
        echo '</div>';
    }

    private function renderManagedSetDetail(\PushPull\Settings\PushPullSettings $settings, ManifestManagedContentAdapterInterface $managedContentAdapter): void
    {
        $managedSetKey = $managedContentAdapter->getManagedSetKey();
        $managedSetEnabled = $this->isManagedSetEnabled($settings, $managedSetKey);
        $isInitialized = $this->localRepository->hasBeenInitialized($settings->branch);
        $headCommit = $this->localRepository->getHeadCommit($settings->branch);
        $exportPreview = $this->buildExportPreview($managedContentAdapter);
        $diffResult = $this->buildDiffResult($managedSetKey);
        $workingState = $this->workingStateRepository->get($managedSetKey, $settings->branch);

        echo '<div class="pushpull-status-grid">';
        $this->statusCard(__('Current repo status', 'pushpull'), $isInitialized ? __('Initialized', 'pushpull') : __('Not initialized', 'pushpull'));
        $this->statusCard(__('Ahead / behind', 'pushpull'), $diffResult !== null ? $diffResult->repositoryRelationship->label() : __('Unavailable', 'pushpull'));
        $this->statusCard(__('Uncommitted changes', 'pushpull'), $diffResult !== null ? $this->uncommittedSummary($diffResult) : __('Unavailable', 'pushpull'));
        $this->statusCard(__('Last local commit', 'pushpull'), $headCommit !== null ? $headCommit->hash : __('None recorded', 'pushpull'));
        $this->statusCard(__('Last remote fetch', 'pushpull'), $diffResult?->remote->commitHash ?? __('Not fetched', 'pushpull'));
        $this->statusCard(__('Merge / conflict state', 'pushpull'), $this->mergeStateSummary($workingState));
        echo '</div>';

        echo '<div class="pushpull-panel">';
        printf('<h2>%s</h2>', esc_html($managedContentAdapter->getManagedSetLabel()));
        echo '<div class="pushpull-button-grid">';
        $this->renderCommitButton($managedContentAdapter, $managedSetEnabled && $managedContentAdapter->isAvailable());
        $this->renderMergeButton($managedContentAdapter, $managedSetEnabled);
        $this->renderApplyButton($managedContentAdapter, $managedSetEnabled);
        $this->renderResolveConflictsButton($workingState);
        echo '</div>';
        echo '</div>';

        if ($diffResult !== null) {
            $this->renderManagedSetDiffPanel($managedContentAdapter, $diffResult, $exportPreview['summary']);
        }

        if ($workingState !== null && ($workingState->hasConflicts() || $workingState->mergeTargetHash !== null)) {
            $this->renderConflictPanel($managedContentAdapter, $workingState);
        }
    }

    private function renderOverview(\PushPull\Settings\PushPullSettings $settings): void
    {
        $overviewRows = [];
        $changedSetCount = 0;
        $conflictedSetCount = 0;
        $enabledSetCount = 0;

        foreach ($this->managedSetRegistry->all() as $managedSetKey => $adapter) {
            $enabled = $this->isManagedSetEnabled($settings, $managedSetKey);
            if ($enabled) {
                $enabledSetCount++;
            }

            $diffResult = $this->buildDiffResult($managedSetKey);
            $workingState = $this->workingStateRepository->get($managedSetKey, $settings->branch);

            if ($diffResult !== null && ($diffResult->liveToLocal->hasChanges() || $diffResult->localToRemote->hasChanges())) {
                $changedSetCount++;
            }

            if ($workingState !== null && $workingState->hasConflicts()) {
                $conflictedSetCount++;
            }

            $overviewRows[] = [
                'adapter' => $adapter,
                'enabled' => $enabled,
                'diffResult' => $diffResult,
                'workingState' => $workingState,
            ];
        }

        echo '<div class="pushpull-status-grid">';
        $this->statusCard(__('Current repo status', 'pushpull'), $this->localRepository->hasBeenInitialized($settings->branch) ? __('Initialized', 'pushpull') : __('Not initialized', 'pushpull'));
        $this->statusCard(__('Enabled managed sets', 'pushpull'), (string) $enabledSetCount);
        $this->statusCard(__('Sets with changes', 'pushpull'), (string) $changedSetCount);
        $this->statusCard(__('Sets with conflicts', 'pushpull'), (string) $conflictedSetCount);
        $this->statusCard(__('Last local commit', 'pushpull'), $this->localRepository->getHeadCommit($settings->branch)?->hash ?? __('None recorded', 'pushpull'));
        $this->statusCard(__('Branch', 'pushpull'), $settings->branch);
        echo '</div>';

        echo '<div class="pushpull-panel">';
        echo '<h2>' . esc_html__('All Managed Sets', 'pushpull') . '</h2>';
        echo '<p class="description">' . esc_html__('Use this overview to review all enabled domains quickly, then open a focused domain view to act on one managed set.', 'pushpull') . '</p>';

        foreach ($overviewRows as $row) {
            /** @var ManifestManagedContentAdapterInterface $adapter */
            $adapter = $row['adapter'];
            $managedSetKey = $adapter->getManagedSetKey();
            /** @var ManagedSetDiffResult|null $diffResult */
            $diffResult = $row['diffResult'];
            /** @var \PushPull\Persistence\WorkingState\WorkingStateRecord|null $workingState */
            $workingState = $row['workingState'];

            echo '<details class="pushpull-tree-browser">';
            printf(
                '<summary>%s <span class="pushpull-diff-badge pushpull-diff-badge-%s">%s</span></summary>',
                esc_html($adapter->getManagedSetLabel()),
                esc_attr($this->overviewBadgeClass($row['enabled'], $diffResult, $workingState)),
                esc_html($this->overviewBadgeText($row['enabled'], $adapter, $diffResult, $workingState))
            );

            printf(
                '<p><a class="button button-secondary" href="%s">%s</a></p>',
                esc_url(add_query_arg(['page' => self::MENU_SLUG, 'managed_set' => $managedSetKey], admin_url('admin.php'))),
                esc_html__('Open detailed view', 'pushpull')
            );

            if (! $row['enabled']) {
                echo '<p class="description">' . esc_html__('This managed set is currently disabled in settings.', 'pushpull') . '</p>';
                echo '</details>';
                continue;
            }

            if (! $adapter->isAvailable()) {
                echo '<p class="description">' . esc_html__('This managed set is enabled, but its WordPress content type is not available on this site.', 'pushpull') . '</p>';
                echo '</details>';
                continue;
            }

            if ($diffResult === null) {
                echo '<p class="description">' . esc_html__('Diff data is currently unavailable for this managed set.', 'pushpull') . '</p>';
                echo '</details>';
                continue;
            }

            printf(
                '<p>%s</p>',
                esc_html(sprintf(
                    'Live vs local: %d changed file(s). Local vs remote: %d changed file(s). Relationship: %s.',
                    $diffResult->liveToLocal->changedCount(),
                    $diffResult->localToRemote->changedCount(),
                    $diffResult->repositoryRelationship->label()
                ))
            );

            $this->renderDiffList(
                __('Uncommitted changes (live vs local)', 'pushpull'),
                $diffResult->liveToLocal,
                'live',
                'local',
                $diffResult->live->files,
                $diffResult->local->files
            );
            $this->renderDiffList(
                __('Local vs remote tracking', 'pushpull'),
                $diffResult->localToRemote,
                'local',
                'remote tracking',
                $diffResult->local->files,
                $diffResult->remote->files
            );
            $this->renderStateTreeComparison(
                __('Browse live and local trees', 'pushpull'),
                'live',
                'local',
                $diffResult->live->files,
                $diffResult->local->files,
                $diffResult->liveToLocal
            );
            $this->renderStateTreeComparison(
                __('Browse local and remote trees', 'pushpull'),
                'local',
                'remote tracking',
                $diffResult->local->files,
                $diffResult->remote->files,
                $diffResult->localToRemote
            );

            if ($workingState !== null && $workingState->hasConflicts()) {
                printf(
                    '<p class="description">%s</p>',
                    esc_html(sprintf('%d conflict(s) are pending for this managed set.', count($workingState->conflicts)))
                );
            }

            echo '</details>';
        }

        echo '</div>';
    }

    private function renderManagedSetDiffPanel(ManifestManagedContentAdapterInterface $managedContentAdapter, ManagedSetDiffResult $diffResult, string $summary): void
    {
        echo '<div id="pushpull-diff" class="pushpull-panel">';
        printf('<h2>%s</h2>', esc_html(sprintf('Diff Summary: %s', $managedContentAdapter->getManagedSetLabel())));
        printf('<p>%s</p>', esc_html(sprintf(
            'Live vs local: %d changed file(s). Local vs remote: %d changed file(s).',
            $diffResult->liveToLocal->changedCount(),
            $diffResult->localToRemote->changedCount()
        )));
        printf('<p class="description">%s</p>', esc_html($summary));
        $this->renderDiffList(
            __('Uncommitted changes (live vs local)', 'pushpull'),
            $diffResult->liveToLocal,
            'live',
            'local',
            $diffResult->live->files,
            $diffResult->local->files
        );
        $this->renderDiffList(
            __('Local vs remote tracking', 'pushpull'),
            $diffResult->localToRemote,
            'local',
            'remote tracking',
            $diffResult->local->files,
            $diffResult->remote->files
        );
        $this->renderStateTreeComparison(
            __('Browse live and local trees', 'pushpull'),
            'live',
            'local',
            $diffResult->live->files,
            $diffResult->local->files,
            $diffResult->liveToLocal
        );
        $this->renderStateTreeComparison(
            __('Browse local and remote trees', 'pushpull'),
            'local',
            'remote tracking',
            $diffResult->local->files,
            $diffResult->remote->files,
            $diffResult->localToRemote
        );
        echo '</div>';
    }

    private function statusCard(string $label, string $value): void
    {
        echo '<div class="pushpull-status-card">';
        printf('<h2>%s</h2>', esc_html($label));
        printf('<p>%s</p>', esc_html($value));
        echo '</div>';
    }

    /**
     * @return array{summary: string, paths: string[]}
     */
    private function buildExportPreview(ManifestManagedContentAdapterInterface $managedContentAdapter): array
    {
        if (! $managedContentAdapter->isAvailable()) {
            return [
                'summary' => sprintf('%s is not available on this site.', $managedContentAdapter->getManagedSetLabel()),
                'paths' => [],
            ];
        }

        try {
            $snapshot = $managedContentAdapter->exportSnapshot();
            $items = $snapshot->items;
        } catch (ManagedContentExportException $exception) {
            return [
                'summary' => $exception->getMessage(),
                'paths' => [],
            ];
        }

        $paths = [];

        foreach (array_slice($items, 0, 5) as $item) {
            $paths[] = $managedContentAdapter->getRepositoryPath($item);
        }

        if ($snapshot->repositoryFilesAuthoritative) {
            foreach (array_keys($snapshot->files) as $path) {
                if (in_array($path, $paths, true)) {
                    continue;
                }

                $paths[] = $path;

                if (count($paths) >= 6) {
                    break;
                }
            }
        } else {
            $paths[] = $managedContentAdapter->getManifestPath();
        }

        return [
            'summary' => sprintf(
                'Adapter export preview found %d item(s) for %s.',
                count($items),
                $managedContentAdapter->getManagedSetLabel()
            ),
            'paths' => $paths,
        ];
    }

    private function buildDiffResult(string $managedSetKey): ?ManagedSetDiffResult
    {
        try {
            return $this->syncService->diff($managedSetKey);
        } catch (ManagedContentExportException | ProviderException | RuntimeException) {
            return null;
        }
    }

    private function uncommittedSummary(ManagedSetDiffResult $diffResult): string
    {
        return $diffResult->liveToLocal->hasChanges()
            ? sprintf('%d changed file(s)', $diffResult->liveToLocal->changedCount())
            : 'Clean';
    }

    private function renderDiffList(
        string $label,
        CanonicalDiffResult $diff,
        string $leftLabel,
        string $rightLabel,
        array $leftFiles,
        array $rightFiles
    ): void {
        printf('<h3>%s</h3>', esc_html($label));

        if (! $diff->hasChanges()) {
            echo '<p>' . esc_html__('No changes detected.', 'pushpull') . '</p>';

            return;
        }

        echo '<ul class="pushpull-export-list">';

        foreach ($diff->entries as $entry) {
            if ($entry->status === 'unchanged') {
                continue;
            }

            $status = $this->humanDiffStatus($entry->status, $leftLabel, $rightLabel);
            echo '<li class="pushpull-diff-item">';
            echo '<details>';
            printf(
                '<summary><code>%s</code> <span class="pushpull-diff-badge pushpull-diff-badge-%s">%s</span></summary>',
                esc_html($entry->path),
                esc_attr($entry->status),
                esc_html($status)
            );
            $this->renderInlineDiffContent(
                $entry->path,
                $leftLabel,
                $rightLabel,
                isset($leftFiles[$entry->path]) ? $leftFiles[$entry->path]->content : null,
                isset($rightFiles[$entry->path]) ? $rightFiles[$entry->path]->content : null
            );
            echo '</details>';
            echo '</li>';
        }

        echo '</ul>';
    }

    private function humanDiffStatus(string $status, string $leftLabel, string $rightLabel): string
    {
        return match ($status) {
            'added' => sprintf('Only in %s', $rightLabel),
            'deleted' => sprintf('Only in %s', $leftLabel),
            default => sprintf('%s and %s differ', ucfirst($leftLabel), $rightLabel),
        };
    }

    /**
     * @param array<string, \PushPull\Domain\Diff\CanonicalManagedFile> $leftFiles
     * @param array<string, \PushPull\Domain\Diff\CanonicalManagedFile> $rightFiles
     */
    private function renderStateTreeComparison(
        string $title,
        string $leftLabel,
        string $rightLabel,
        array $leftFiles,
        array $rightFiles,
        CanonicalDiffResult $diff
    ): void {
        $statusMap = [];

        foreach ($diff->entries as $entry) {
            $statusMap[$entry->path] = $entry->status;
        }

        echo '<details class="pushpull-tree-browser">';
        printf('<summary>%s</summary>', esc_html($title));
        echo '<div class="pushpull-inline-diff">';
        $this->renderStateTreePane($leftLabel, $leftFiles, $statusMap, true);
        $this->renderStateTreePane($rightLabel, $rightFiles, $statusMap, false);
        echo '</div>';
        echo '</details>';
    }

    /**
     * @param array<string, \PushPull\Domain\Diff\CanonicalManagedFile> $files
     * @param array<string, string> $statusMap
     */
    private function renderStateTreePane(string $label, array $files, array $statusMap, bool $isLeftSide): void
    {
        echo '<div class="pushpull-inline-diff-pane">';
        printf('<h4>%s</h4>', esc_html(ucfirst($label)));

        if ($files === []) {
            echo '<p class="description">' . esc_html(sprintf('No files on the %s side.', $label)) . '</p>';
            echo '</div>';

            return;
        }

        $tree = [];

        foreach (array_keys($files) as $path) {
            $segments = explode('/', $path);
            $cursor = &$tree;

            foreach ($segments as $index => $segment) {
                $isLeaf = $index === count($segments) - 1;

                if (! isset($cursor[$segment])) {
                    $cursor[$segment] = [
                        'type' => $isLeaf ? 'file' : 'dir',
                        'children' => [],
                        'path' => $isLeaf ? $path : null,
                    ];
                }

                $cursor = &$cursor[$segment]['children'];
            }

            unset($cursor);
        }

        echo '<div class="pushpull-tree">';
        $this->renderTreeNodes($tree, $statusMap, $isLeftSide);
        echo '</div>';
        echo '</div>';
    }

    /**
     * @param array<string, array{type: string, children: array, path: ?string}> $nodes
     * @param array<string, string> $statusMap
     */
    private function renderTreeNodes(array $nodes, array $statusMap, bool $isLeftSide): void
    {
        ksort($nodes);
        echo '<ul class="pushpull-tree-list">';

        foreach ($nodes as $name => $node) {
            echo '<li>';

            if ($node['type'] === 'dir') {
                echo '<details open="open" class="pushpull-tree-dir">';
                printf('<summary><span class="pushpull-tree-name">%s</span></summary>', esc_html($name));
                $this->renderTreeNodes($node['children'], $statusMap, $isLeftSide);
                echo '</details>';
                echo '</li>';
                continue;
            }

            $path = (string) $node['path'];
            $status = $statusMap[$path] ?? 'unchanged';
            $badgeText = $this->treeBadgeText($status, $isLeftSide);
            printf(
                '<div class="pushpull-tree-file"><code>%s</code> <span class="pushpull-diff-badge pushpull-diff-badge-%s">%s</span></div>',
                esc_html($name),
                esc_attr($this->treeBadgeClass($status, $isLeftSide)),
                esc_html($badgeText)
            );
            echo '</li>';
        }

        echo '</ul>';
    }

    private function treeBadgeText(string $status, bool $isLeftSide): string
    {
        return match ($status) {
            'unchanged' => 'Unchanged',
            'modified' => 'Changed',
            'deleted' => $isLeftSide ? 'Only here' : 'Missing here',
            'added' => $isLeftSide ? 'Missing here' : 'Only here',
            default => ucfirst($status),
        };
    }

    private function treeBadgeClass(string $status, bool $isLeftSide): string
    {
        return match ($status) {
            'unchanged' => 'unchanged',
            'modified' => 'modified',
            'deleted' => $isLeftSide ? 'deleted' : 'muted',
            'added' => $isLeftSide ? 'muted' : 'added',
            default => 'modified',
        };
    }

    private function renderInlineDiffContent(
        string $path,
        string $leftLabel,
        string $rightLabel,
        ?string $leftContent,
        ?string $rightContent
    ): void {
        $lineDiff = $this->buildLineDiff($leftContent, $rightContent);
        echo '<div class="pushpull-inline-diff">';
        $this->renderInlineDiffPane($leftLabel, $leftContent, $path, $lineDiff['left']);
        $this->renderInlineDiffPane($rightLabel, $rightContent, $path, $lineDiff['right']);
        echo '</div>';
    }

    /**
     * @param array<int, array{type: string, text: string}> $lines
     */
    private function renderInlineDiffPane(string $label, ?string $content, string $path, array $lines): void
    {
        echo '<div class="pushpull-inline-diff-pane">';
        printf('<h4>%s</h4>', esc_html(ucfirst($label)));

        if ($content === null) {
            echo '<p class="description">' . esc_html(sprintf('File is absent on the %s side.', $label)) . '</p>';
            echo '</div>';

            return;
        }

        echo '<div class="pushpull-inline-diff-code">';
        foreach ($lines as $lineNumber => $line) {
            printf(
                '<div class="pushpull-inline-diff-line pushpull-inline-diff-line-%s"><span class="pushpull-inline-diff-line-number">%d</span><code>%s</code></div>',
                esc_attr($line['type']),
                esc_html((string) ($lineNumber + 1)),
                esc_html($line['text'])
            );
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * @return array{left: array<int, array{type: string, text: string}>, right: array<int, array{type: string, text: string}>}
     */
    private function buildLineDiff(?string $leftContent, ?string $rightContent): array
    {
        $leftLines = $this->splitDiffLines($leftContent);
        $rightLines = $this->splitDiffLines($rightContent);
        $pairs = $this->diffLinePairs($leftLines, $rightLines);
        $left = [];
        $right = [];

        foreach ($pairs as $pair) {
            if ($pair['left'] !== null && $pair['right'] !== null) {
                $left[] = ['type' => 'unchanged', 'text' => $pair['left']];
                $right[] = ['type' => 'unchanged', 'text' => $pair['right']];
                continue;
            }

            if ($pair['left'] !== null) {
                $left[] = ['type' => 'removed', 'text' => $pair['left']];
                $right[] = ['type' => 'empty', 'text' => ''];
                continue;
            }

            $left[] = ['type' => 'empty', 'text' => ''];
            $right[] = ['type' => 'added', 'text' => (string) $pair['right']];
        }

        return ['left' => $left, 'right' => $right];
    }

    /**
     * @return string[]
     */
    private function splitDiffLines(?string $content): array
    {
        if ($content === null) {
            return [];
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", rtrim($content, "\n"));

        return $lines === [''] ? [] : $lines;
    }

    /**
     * @param string[] $left
     * @param string[] $right
     * @return array<int, array{left: ?string, right: ?string}>
     */
    private function diffLinePairs(array $left, array $right): array
    {
        $leftCount = count($left);
        $rightCount = count($right);
        $matrix = array_fill(0, $leftCount + 1, array_fill(0, $rightCount + 1, 0));

        for ($leftIndex = $leftCount - 1; $leftIndex >= 0; $leftIndex--) {
            for ($rightIndex = $rightCount - 1; $rightIndex >= 0; $rightIndex--) {
                $matrix[$leftIndex][$rightIndex] = $left[$leftIndex] === $right[$rightIndex]
                    ? $matrix[$leftIndex + 1][$rightIndex + 1] + 1
                    : max($matrix[$leftIndex + 1][$rightIndex], $matrix[$leftIndex][$rightIndex + 1]);
            }
        }

        $pairs = [];
        $leftIndex = 0;
        $rightIndex = 0;

        while ($leftIndex < $leftCount && $rightIndex < $rightCount) {
            if ($left[$leftIndex] === $right[$rightIndex]) {
                $pairs[] = ['left' => $left[$leftIndex], 'right' => $right[$rightIndex]];
                $leftIndex++;
                $rightIndex++;
                continue;
            }

            if ($matrix[$leftIndex + 1][$rightIndex] >= $matrix[$leftIndex][$rightIndex + 1]) {
                $pairs[] = ['left' => $left[$leftIndex], 'right' => null];
                $leftIndex++;
                continue;
            }

            $pairs[] = ['left' => null, 'right' => $right[$rightIndex]];
            $rightIndex++;
        }

        while ($leftIndex < $leftCount) {
            $pairs[] = ['left' => $left[$leftIndex], 'right' => null];
            $leftIndex++;
        }

        while ($rightIndex < $rightCount) {
            $pairs[] = ['left' => null, 'right' => $right[$rightIndex]];
            $rightIndex++;
        }

        return $pairs;
    }

    public function handleCommit(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::COMMIT_ACTION);

        $settings = $this->settingsRepository->get();
        $managedSetKey = $this->selectedManagedSetKeyOrFail();
        $managedContentAdapter = $this->managedSetRegistry->get($managedSetKey);

        if (! $this->isManagedSetEnabled($settings, $managedSetKey)) {
            $this->redirectWithNotice('error', sprintf('%s is not enabled in settings.', $managedContentAdapter->getManagedSetLabel()), null);
        }

        if (! $managedContentAdapter->isAvailable()) {
            $this->redirectWithNotice('error', sprintf('%s is not available on this site.', $managedContentAdapter->getManagedSetLabel()), $managedSetKey);
        }

        try {
            $result = $this->operationExecutor->run(
                $managedSetKey,
                'commit',
                ['branch' => $settings->branch],
                fn () => $this->syncService->commitManagedSet(
                    $managedSetKey,
                    new CommitManagedSetRequest(
                        $settings->branch,
                        $managedContentAdapter->buildCommitMessage(),
                        $settings->authorName !== '' ? $settings->authorName : wp_get_current_user()->display_name,
                        $settings->authorEmail !== '' ? $settings->authorEmail : (wp_get_current_user()->user_email ?? '')
                    )
                )
            );
        } catch (ManagedContentExportException | RuntimeException $exception) {
            $this->redirectWithNotice('error', $exception->getMessage(), $managedSetKey);
        }

        $message = $result->createdNewCommit
            ? sprintf('Committed %d file(s) to local branch %s.', count($result->pathHashes), $settings->branch)
            : sprintf('No local commit created. Branch %s already matches the live managed content.', $settings->branch);

        $this->redirectWithNotice('success', $message, $managedSetKey);
    }

    public function handleFetch(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::FETCH_ACTION);

        $settings = $this->settingsRepository->get();
        $managedSetKey = $this->selectedManagedSetKeyOrFail();
        $managedContentAdapter = $this->managedSetRegistry->get($managedSetKey);

        if (! $this->isManagedSetEnabled($settings, $managedSetKey)) {
            $this->redirectWithNotice('error', sprintf('%s is not enabled in settings.', $managedContentAdapter->getManagedSetLabel()), $managedSetKey);
        }

        try {
            $result = $this->operationExecutor->run(
                $managedSetKey,
                'fetch',
                ['branch' => $settings->branch],
                fn () => $this->syncService->fetch($managedSetKey)
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message, null);
        }

        $message = sprintf(
            'Fetched remote commit %s into %s. Newly imported %d commit(s), %d tree(s), and %d blob(s); traversed %d commit(s), %d tree(s), and %d blob(s).',
            $result->remoteCommitHash,
            $result->remoteRefName,
            count($result->newCommitHashes),
            count($result->newTreeHashes),
            count($result->newBlobHashes),
            count($result->traversedCommitHashes),
            count($result->traversedTreeHashes),
            count($result->traversedBlobHashes)
        );

        $this->redirectWithNotice('success', $message, null);
    }

    public function handleAjaxStartBranchAction(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_send_json_error(['message' => __('You do not have permission to manage PushPull.', 'pushpull')], 403);
        }

        check_ajax_referer('pushpull_async_branch_action', 'nonce');

        $operationType = isset($_POST['operation_type']) ? sanitize_key(wp_unslash((string) $_POST['operation_type'])) : '';
        $managedSetKey = isset($_POST['managed_set']) ? sanitize_key(wp_unslash((string) $_POST['managed_set'])) : '';

        if ($managedSetKey === '' || ! $this->managedSetRegistry->has($managedSetKey)) {
            wp_send_json_error(['message' => __('The managed set is not supported.', 'pushpull')], 400);
        }

        $settings = $this->settingsRepository->get();
        $managedContentAdapter = $this->managedSetRegistry->get($managedSetKey);

        if (! $this->isManagedSetEnabled($settings, $managedSetKey)) {
            wp_send_json_error(['message' => sprintf('%s is not enabled in settings.', $managedContentAdapter->getManagedSetLabel())], 400);
        }

        try {
            $started = $this->asyncBranchOperationRunner->start($managedSetKey, $operationType);
        } catch (ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            wp_send_json_error(['message' => $message], 400);
        }

        wp_send_json_success([
            'operationId' => $started['operationId'],
            'message' => $started['progressMessage'],
            'done' => $started['done'],
            'status' => $started['status'] ?? 'running',
            'redirectUrl' => $started['redirectUrl'] ?? null,
            'progress' => $started['progress'],
        ]);
    }

    public function handleAjaxContinueBranchAction(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_send_json_error(['message' => __('You do not have permission to manage PushPull.', 'pushpull')], 403);
        }

        check_ajax_referer('pushpull_async_branch_action', 'nonce');

        $operationId = isset($_POST['operation_id']) ? absint(wp_unslash((string) $_POST['operation_id'])) : 0;

        if ($operationId <= 0) {
            wp_send_json_error(['message' => __('The async operation could not be found.', 'pushpull')], 400);
        }

        try {
            $response = $this->asyncBranchOperationRunner->continue($operationId);
        } catch (ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            wp_send_json_error(['message' => $message], 400);
        }

        if (! $response['done']) {
            wp_send_json_success($response);
        }

        wp_send_json_success($response + [
            'redirectUrl' => $this->noticeUrl(
                $response['status'],
                $response['message'],
                isset($response['redirectManagedSetKey']) && is_string($response['redirectManagedSetKey'])
                    ? $response['redirectManagedSetKey']
                    : null
            ),
        ]);
    }

    public function handlePull(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::PULL_ACTION);

        $settings = $this->settingsRepository->get();
        $managedSetKey = $this->selectedManagedSetKeyOrFail();
        $managedContentAdapter = $this->managedSetRegistry->get($managedSetKey);

        if (! $this->isManagedSetEnabled($settings, $managedSetKey)) {
            $this->redirectWithNotice('error', sprintf('%s is not enabled in settings.', $managedContentAdapter->getManagedSetLabel()), null);
        }

        try {
            $result = $this->operationExecutor->run(
                $managedSetKey,
                'pull',
                ['branch' => $settings->branch],
                fn () => $this->syncService->pull($managedSetKey)
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message, null);
        }

        $mergeMessage = match ($result->mergeResult->status) {
            'already_up_to_date' => sprintf('Local branch %s was already up to date after fetch.', $settings->branch),
            'fast_forward' => sprintf('Pulled remote branch %s and fast-forwarded local to %s.', $settings->branch, $result->mergeResult->theirsCommitHash),
            'merged' => sprintf('Pulled remote branch %s and created merge commit %s.', $settings->branch, $result->mergeResult->commit?->hash),
            'conflict' => sprintf('Pulled remote branch %s, but merge requires resolution. Stored %d conflict(s).', $settings->branch, count($result->mergeResult->conflicts)),
            default => sprintf('Pulled remote branch %s.', $settings->branch),
        };

        $message = sprintf(
            'Fetched %s into %s. %s',
            $result->fetchResult->remoteCommitHash,
            $result->fetchResult->remoteRefName,
            $mergeMessage
        );

        $this->redirectWithNotice($result->mergeResult->hasConflicts() ? 'error' : 'success', $message, null);
    }

    public function handleMerge(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::MERGE_ACTION);

        $settings = $this->settingsRepository->get();
        $managedSetKey = $this->selectedManagedSetKeyOrFail();
        $managedContentAdapter = $this->managedSetRegistry->get($managedSetKey);

        if (! $this->isManagedSetEnabled($settings, $managedSetKey)) {
            $this->redirectWithNotice('error', sprintf('%s is not enabled in settings.', $managedContentAdapter->getManagedSetLabel()), null);
        }

        try {
            $result = $this->operationExecutor->run(
                $managedSetKey,
                'merge',
                ['branch' => $settings->branch],
                fn () => $this->syncService->merge($managedSetKey)
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message, null);
        }

        $message = match ($result->status) {
            'already_up_to_date' => sprintf('Local branch %s is already up to date with the fetched remote commit.', $settings->branch),
            'fast_forward' => sprintf('Fast-forwarded local branch %s to %s.', $settings->branch, $result->theirsCommitHash),
            'merged' => sprintf('Created merge commit %s on local branch %s.', $result->commit?->hash, $settings->branch),
            'conflict' => sprintf('Merge requires resolution. Stored %d conflict(s) for branch %s.', count($result->conflicts), $settings->branch),
            default => 'Merge completed.',
        };

        $this->redirectWithNotice($result->hasConflicts() ? 'error' : 'success', $message, $managedSetKey);
    }

    public function handleApply(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::APPLY_ACTION);

        $settings = $this->settingsRepository->get();
        $managedSetKey = $this->selectedManagedSetKeyOrFail();
        $managedContentAdapter = $this->managedSetRegistry->get($managedSetKey);

        if (! $this->isManagedSetEnabled($settings, $managedSetKey)) {
            $this->redirectWithNotice('error', sprintf('%s is not enabled in settings.', $managedContentAdapter->getManagedSetLabel()), $managedSetKey);
        }

        try {
            $result = $this->operationExecutor->run(
                $managedSetKey,
                'apply',
                ['branch' => $settings->branch],
                fn () => $this->syncService->apply($managedSetKey)
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message, $managedSetKey);
        }

        $message = sprintf(
            'Applied local branch %s commit %s to WordPress. Created %d item(s), updated %d item(s), deleted %d item(s).',
            $result->branch,
            $result->sourceCommitHash,
            $result->createdCount,
            $result->updatedCount,
            count($result->deletedLogicalKeys)
        );

        $this->redirectWithNotice('success', $message, null);
    }

    public function handlePush(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::PUSH_ACTION);

        $settings = $this->settingsRepository->get();
        $managedSetKey = $this->selectedManagedSetKeyOrFail();
        $managedContentAdapter = $this->managedSetRegistry->get($managedSetKey);

        if (! $this->isManagedSetEnabled($settings, $managedSetKey)) {
            $this->redirectWithNotice('error', sprintf('%s is not enabled in settings.', $managedContentAdapter->getManagedSetLabel()), $managedSetKey);
        }

        try {
            $result = $this->operationExecutor->run(
                $managedSetKey,
                'push',
                ['branch' => $settings->branch],
                fn () => $this->syncService->push($managedSetKey)
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message, $managedSetKey);
        }

        $message = $result->status === 'already_up_to_date'
            ? sprintf('Local branch %s is already up to date on the provider.', $result->branch)
            : sprintf(
                'Pushed local branch %s to remote commit %s. Uploaded %d commit(s), %d tree(s), and %d blob(s).',
                $result->branch,
                $result->remoteCommitHash,
                count($result->pushedCommitHashes),
                count($result->pushedTreeHashes),
                count($result->pushedBlobHashes)
            );

        $this->redirectWithNotice('success', $message, $managedSetKey);
    }

    public function handleResetRemote(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::RESET_REMOTE_ACTION);

        $settings = $this->settingsRepository->get();
        $managedSetKey = $this->selectedManagedSetKeyOrFail();
        $managedContentAdapter = $this->managedSetRegistry->get($managedSetKey);

        if (! $this->isManagedSetEnabled($settings, $managedSetKey)) {
            $this->redirectWithNotice('error', sprintf('%s is not enabled in settings.', $managedContentAdapter->getManagedSetLabel()), $managedSetKey);
        }

        try {
            $result = $this->operationExecutor->run(
                $managedSetKey,
                'reset_remote',
                ['branch' => $settings->branch],
                fn () => $this->syncService->resetRemote($managedSetKey)
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message, $managedSetKey);
        }

        $message = sprintf(
            'Reset remote branch %s from %s to %s with a single empty-tree commit. Local branch content was not changed.',
            $result->branch,
            $result->previousRemoteCommitHash,
            $result->remoteCommitHash
        );

        $this->redirectWithNotice('success', $message, $managedSetKey);
    }

    public function handleResolveConflict(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::RESOLVE_CONFLICT_ACTION);

        $settings = $this->settingsRepository->get();
        $managedSetKey = $this->selectedManagedSetKeyOrFail();
        $path = isset($_POST['path']) ? sanitize_text_field(wp_unslash((string) $_POST['path'])) : '';
        $strategy = isset($_POST['strategy']) ? sanitize_key((string) $_POST['strategy']) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The value may contain raw JSON and is protected by nonce verification above.
        $manualContent = isset($_POST['manual_content']) ? (string) wp_unslash((string) $_POST['manual_content']) : '';

        if ($path === '' || ! in_array($strategy, ['ours', 'theirs', 'manual'], true)) {
            $this->redirectWithNotice('error', 'Conflict resolution request was incomplete.', $managedSetKey);
        }

        try {
            $result = $this->operationExecutor->run(
                $managedSetKey,
                'resolve_conflict',
                ['branch' => $settings->branch, 'path' => $path, 'strategy' => $strategy],
                fn () => match ($strategy) {
                    'ours' => $this->conflictResolutionService->resolveUsingOurs($managedSetKey, $settings->branch, $path),
                    'theirs' => $this->conflictResolutionService->resolveUsingTheirs($managedSetKey, $settings->branch, $path),
                    default => $this->conflictResolutionService->resolveUsingManual($managedSetKey, $settings->branch, $path, $manualContent),
                }
            );
        } catch (RuntimeException $exception) {
            $this->redirectWithNotice('error', $exception->getMessage(), $managedSetKey);
        }

        $message = $result->remainingConflictCount > 0
            ? sprintf('Resolved conflict for %s. %d conflict(s) remain.', $result->path, $result->remainingConflictCount)
            : sprintf('Resolved conflict for %s. All conflicts are resolved; finalize the merge to create the merge commit.', $result->path);

        $this->redirectWithNotice('success', $message, $managedSetKey);
    }

    public function handleFinalizeMerge(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::FINALIZE_MERGE_ACTION);

        $settings = $this->settingsRepository->get();
        $managedSetKey = $this->selectedManagedSetKeyOrFail();

        try {
            $result = $this->operationExecutor->run(
                $managedSetKey,
                'finalize_merge',
                ['branch' => $settings->branch],
                fn () => $this->conflictResolutionService->finalize($managedSetKey, $settings->branch)
            );
        } catch (RuntimeException $exception) {
            $this->redirectWithNotice('error', $exception->getMessage(), $managedSetKey);
        }

        $this->redirectWithNotice('success', sprintf(
            'Finalized merge on branch %s with merge commit %s.',
            $result->branch,
            $result->commit->hash
        ), $managedSetKey);
    }

    private function renderCommitButton(ManifestManagedContentAdapterInterface $managedContentAdapter, bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-primary" disabled="disabled">%s</button>',
                esc_html__('Commit', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::COMMIT_ACTION) . '" />';
        echo '<input type="hidden" name="managed_set" value="' . esc_attr($managedContentAdapter->getManagedSetKey()) . '" />';
        wp_nonce_field(self::COMMIT_ACTION);
        submit_button(__('Commit', 'pushpull'), 'primary', 'submit', false);
        echo '</form>';
    }

    private function renderFetchButton(?string $managedSetKey, bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
                esc_html__('Fetch', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pushpull-async-branch-form" data-pushpull-async-operation="fetch" data-pushpull-async-label="' . esc_attr__('Fetch', 'pushpull') . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::FETCH_ACTION) . '" />';
        echo '<input type="hidden" name="managed_set" value="' . esc_attr((string) $managedSetKey) . '" />';
        wp_nonce_field(self::FETCH_ACTION);
        submit_button(__('Fetch', 'pushpull'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderPullButton(?string $managedSetKey, bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
                esc_html__('Pull', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pushpull-async-branch-form" data-pushpull-async-operation="pull" data-pushpull-async-label="' . esc_attr__('Pull', 'pushpull') . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::PULL_ACTION) . '" />';
        echo '<input type="hidden" name="managed_set" value="' . esc_attr((string) $managedSetKey) . '" />';
        wp_nonce_field(self::PULL_ACTION);
        submit_button(__('Pull', 'pushpull'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderMergeButton(ManifestManagedContentAdapterInterface $managedContentAdapter, bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
                esc_html__('Merge', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::MERGE_ACTION) . '" />';
        echo '<input type="hidden" name="managed_set" value="' . esc_attr($managedContentAdapter->getManagedSetKey()) . '" />';
        wp_nonce_field(self::MERGE_ACTION);
        submit_button(__('Merge', 'pushpull'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderPushButton(?string $managedSetKey, bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
                esc_html__('Push', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pushpull-async-branch-form" data-pushpull-async-operation="push" data-pushpull-async-label="' . esc_attr__('Push', 'pushpull') . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::PUSH_ACTION) . '" />';
        echo '<input type="hidden" name="managed_set" value="' . esc_attr((string) $managedSetKey) . '" />';
        wp_nonce_field(self::PUSH_ACTION);
        submit_button(__('Push', 'pushpull'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderApplyButton(ManifestManagedContentAdapterInterface $managedContentAdapter, bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
                esc_html__('Apply repo to WordPress', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="pushpull-async-branch-form" data-pushpull-async-operation="apply" data-pushpull-async-label="' . esc_attr__('Apply repo to WordPress', 'pushpull') . '" data-pushpull-confirm="' . esc_attr__('Apply the local repository state back into WordPress? This will update existing managed content and remove WordPress items that are not present in the repository.', 'pushpull') . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::APPLY_ACTION) . '" />';
        echo '<input type="hidden" name="managed_set" value="' . esc_attr($managedContentAdapter->getManagedSetKey()) . '" />';
        wp_nonce_field(self::APPLY_ACTION);
        submit_button(__('Apply repo to WordPress', 'pushpull'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderResolveConflictsButton(?\PushPull\Persistence\WorkingState\WorkingStateRecord $workingState): void
    {
        if ($workingState === null || ($workingState->mergeTargetHash === null && ! $workingState->hasConflicts())) {
            $this->renderDisabledActionButton(__('Resolve conflicts', 'pushpull'));

            return;
        }

        printf(
            '<a class="button button-secondary" href="%s">%s</a>',
            esc_url('#pushpull-conflicts'),
            esc_html__('Resolve conflicts', 'pushpull')
        );
    }

    private function renderDisabledActionButton(string $label): void
    {
        printf(
            '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
            esc_html($label)
        );
    }

    private function renderManagedSetTabs(?string $activeManagedSetKey): void
    {
        if (count($this->managedSetRegistry->all()) < 2) {
            return;
        }

        echo '<div class="pushpull-panel"><p>';

        printf(
            '<a class="%s" href="%s">%s</a> ',
            esc_attr($activeManagedSetKey === null ? 'button button-primary' : 'button button-secondary'),
            esc_url(add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php'))),
            esc_html__('All managed sets', 'pushpull')
        );

        foreach ($this->managedSetRegistry->all() as $managedSetKey => $adapter) {
            $url = add_query_arg(
                [
                    'page' => self::MENU_SLUG,
                    'managed_set' => $managedSetKey,
                ],
                admin_url('admin.php')
            );
            $class = $managedSetKey === $activeManagedSetKey ? 'button button-primary' : 'button button-secondary';
            printf(
                '<a class="%s" href="%s">%s</a> ',
                esc_attr($class),
                esc_url($url),
                esc_html($adapter->getManagedSetLabel())
            );
        }

        echo '</p></div>';
    }

    private function renderConflictPanel(ManifestManagedContentAdapterInterface $managedContentAdapter, \PushPull\Persistence\WorkingState\WorkingStateRecord $workingState): void
    {
        echo '<div id="pushpull-conflicts" class="pushpull-panel">';
        echo '<h2>' . esc_html__('Merge Conflicts', 'pushpull') . '</h2>';

        if ($workingState->hasConflicts()) {
            printf(
                '<p>%s</p>',
                esc_html(sprintf('There are %d unresolved conflict(s) on branch %s.', count($workingState->conflicts), $workingState->branchName))
            );

            foreach ($workingState->conflicts as $conflict) {
                $this->renderConflictCard($conflict);
            }

            echo '</div>';

            return;
        }

        echo '<p>' . esc_html__('All conflicts are resolved. Finalize the merge to create the merge commit and clear the merge state.', 'pushpull') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::FINALIZE_MERGE_ACTION) . '" />';
        echo '<input type="hidden" name="managed_set" value="' . esc_attr($managedContentAdapter->getManagedSetKey()) . '" />';
        wp_nonce_field(self::FINALIZE_MERGE_ACTION);
        submit_button(__('Finalize merge', 'pushpull'), 'primary', 'submit', false);
        echo '</form>';
        echo '</div>';
    }

    private function renderConflictCard(MergeConflict $conflict): void
    {
        echo '<div class="pushpull-panel">';
        printf('<h3><code>%s</code></h3>', esc_html($conflict->path));
        if ($conflict->jsonPaths !== []) {
            printf('<p class="description">%s</p>', esc_html('Conflicting JSON paths: ' . implode(', ', $conflict->jsonPaths)));
        }
        echo '<div class="pushpull-layout">';
        echo '<div class="pushpull-main">';
        echo '<h4>' . esc_html__('Base', 'pushpull') . '</h4>';
        printf('<textarea class="large-text code" rows="8" readonly="readonly">%s</textarea>', esc_textarea($conflict->baseContent ?? ''));
        echo '<h4>' . esc_html__('Ours', 'pushpull') . '</h4>';
        printf('<textarea class="large-text code" rows="8" readonly="readonly">%s</textarea>', esc_textarea($conflict->oursContent ?? ''));
        echo '<h4>' . esc_html__('Theirs', 'pushpull') . '</h4>';
        printf('<textarea class="large-text code" rows="8" readonly="readonly">%s</textarea>', esc_textarea($conflict->theirsContent ?? ''));
        echo '</div>';
        echo '<aside class="pushpull-sidebar">';
        $this->renderConflictActionForm($conflict->path, 'ours', null, __('Use ours', 'pushpull'));
        $this->renderConflictActionForm($conflict->path, 'theirs', null, __('Use theirs', 'pushpull'));
        $this->renderConflictActionForm($conflict->path, 'manual', $conflict->oursContent ?? $conflict->theirsContent ?? $conflict->baseContent ?? "{}\n", __('Save manual JSON', 'pushpull'));
        echo '</aside>';
        echo '</div>';
        echo '</div>';
    }

    private function renderConflictActionForm(string $path, string $strategy, ?string $manualContent, string $buttonLabel): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::RESOLVE_CONFLICT_ACTION) . '" />';
        echo '<input type="hidden" name="managed_set" value="' . esc_attr($this->currentAdapter()->getManagedSetKey()) . '" />';
        echo '<input type="hidden" name="path" value="' . esc_attr($path) . '" />';
        echo '<input type="hidden" name="strategy" value="' . esc_attr($strategy) . '" />';
        wp_nonce_field(self::RESOLVE_CONFLICT_ACTION);

        if ($strategy === 'manual') {
            printf(
                '<textarea name="manual_content" class="large-text code" rows="10">%s</textarea>',
                esc_textarea((string) $manualContent)
            );
        }

        submit_button($buttonLabel, 'secondary', 'submit', false);
        echo '</form>';
    }

    private function currentAdapter(): ManifestManagedContentAdapterInterface
    {
        $requestedManagedSetKey = $this->requestManagedSetKey();

        if ($requestedManagedSetKey !== null && $this->managedSetRegistry->has($requestedManagedSetKey)) {
            return $this->managedSetRegistry->get($requestedManagedSetKey);
        }

        return $this->managedSetRegistry->first();
    }

    private function requestManagedSetKey(): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter used for screen selection.
        $fromGet = isset($_GET['managed_set']) ? sanitize_key(wp_unslash((string) $_GET['managed_set'])) : '';

        if ($fromGet !== '') {
            return $fromGet;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only routing parameter used after nonce validation in action handlers.
        $fromPost = isset($_POST['managed_set']) ? sanitize_key(wp_unslash((string) $_POST['managed_set'])) : '';

        return $fromPost !== '' ? $fromPost : null;
    }

    private function isOverviewMode(): bool
    {
        return $this->requestManagedSetKey() === null;
    }

    private function selectedManagedSetKeyOrFail(): string
    {
        $managedSetKey = $this->requestManagedSetKey() ?? $this->currentAdapter()->getManagedSetKey();

        if (! $this->managedSetRegistry->has($managedSetKey)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        return $managedSetKey;
    }

    private function branchActionManagedSetKey(\PushPull\Settings\PushPullSettings $settings): ?string
    {
        foreach ($this->managedSetRegistry->all() as $managedSetKey => $_adapter) {
            if ($this->isManagedSetEnabled($settings, $managedSetKey)) {
                return $managedSetKey;
            }
        }

        return null;
    }

    private function isManagedSetEnabled(\PushPull\Settings\PushPullSettings $settings, string $managedSetKey): bool
    {
        return $settings->isManagedSetEnabled($managedSetKey);
    }

    private function overviewBadgeText(
        bool $enabled,
        ManifestManagedContentAdapterInterface $adapter,
        ?ManagedSetDiffResult $diffResult,
        ?\PushPull\Persistence\WorkingState\WorkingStateRecord $workingState
    ): string {
        if (! $enabled) {
            return 'Disabled';
        }

        if (! $adapter->isAvailable()) {
            return 'Unavailable';
        }

        if ($workingState !== null && $workingState->hasConflicts()) {
            return sprintf('%d conflict(s)', count($workingState->conflicts));
        }

        if ($diffResult === null) {
            return 'Unavailable';
        }

        if ($diffResult->liveToLocal->hasChanges() || $diffResult->localToRemote->hasChanges()) {
            return sprintf(
                '%d local, %d remote',
                $diffResult->liveToLocal->changedCount(),
                $diffResult->localToRemote->changedCount()
            );
        }

        return 'Clean';
    }

    private function overviewBadgeClass(
        bool $enabled,
        ?ManagedSetDiffResult $diffResult,
        ?\PushPull\Persistence\WorkingState\WorkingStateRecord $workingState
    ): string {
        if (! $enabled) {
            return 'muted';
        }

        if ($workingState !== null && $workingState->hasConflicts()) {
            return 'deleted';
        }

        if ($diffResult !== null && ($diffResult->liveToLocal->hasChanges() || $diffResult->localToRemote->hasChanges())) {
            return 'modified';
        }

        return 'unchanged';
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private function commitNotice(): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice parameters from the redirect target.
        $status = isset($_GET['pushpull_commit_status']) ? sanitize_key((string) $_GET['pushpull_commit_status']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice parameters from the redirect target.
        $message = isset($_GET['pushpull_commit_message']) ? sanitize_text_field(wp_unslash((string) $_GET['pushpull_commit_message'])) : '';

        if ($status === '' || $message === '') {
            return null;
        }

        return [
            'type' => $status === 'success' ? 'success' : 'error',
            'message' => $message,
        ];
    }

    private function redirectWithNotice(string $status, string $message, ?string $managedSetKey = null): never
    {
        wp_safe_redirect($this->noticeUrl($status, $message, $managedSetKey));
        exit;
    }

    private function noticeUrl(string $status, string $message, ?string $managedSetKey = null): string
    {
        $queryArgs = [
            'page' => self::MENU_SLUG,
            'pushpull_commit_status' => $status,
            'pushpull_commit_message' => $message,
        ];

        if ($managedSetKey !== null) {
            $queryArgs['managed_set'] = $managedSetKey;
        }

        return add_query_arg($queryArgs, admin_url('admin.php'));
    }

    private function renderAsyncOperationModal(): void
    {
        echo '<div class="pushpull-async-modal" hidden="hidden">';
        echo '<div class="pushpull-async-modal__backdrop"></div>';
        echo '<div class="pushpull-async-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="pushpull-async-modal-title">';
        echo '<h2 id="pushpull-async-modal-title">' . esc_html__('Working…', 'pushpull') . '</h2>';
        echo '<p class="pushpull-async-modal__message">' . esc_html__('Preparing operation…', 'pushpull') . '</p>';
        echo '<div class="pushpull-async-modal__progress" hidden="hidden">';
        echo '<div class="pushpull-async-modal__progress-bar is-indeterminate" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">';
        echo '<span class="pushpull-async-modal__progress-fill"></span>';
        echo '</div>';
        echo '<p class="pushpull-async-modal__progress-label"></p>';
        echo '</div>';
        echo '<button type="button" class="button button-secondary pushpull-async-modal__close" hidden="hidden">' . esc_html__('Close', 'pushpull') . '</button>';
        echo '</div>';
        echo '</div>';
    }

    private function mergeStateSummary(?\PushPull\Persistence\WorkingState\WorkingStateRecord $workingState): string
    {
        if ($workingState === null) {
            return 'No merge state recorded';
        }

        if ($workingState->hasConflicts()) {
            return sprintf('%d conflict(s) pending', count($workingState->conflicts));
        }

        if ($workingState->mergeTargetHash !== null) {
            return 'Merge state recorded without conflicts';
        }

        return 'No pending conflicts';
    }
}
