<?php

declare(strict_types=1);

namespace PushPull\Admin;

use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Content\ManagedContentAdapterInterface;
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
use PushPull\Support\Operations\OperationExecutor;
use RuntimeException;

final class ManagedContentPage
{
    public const MENU_SLUG = 'pushpull-managed-content';
    private const COMMIT_ACTION = 'pushpull_commit_generateblocks';
    private const FETCH_ACTION = 'pushpull_fetch_generateblocks';
    private const MERGE_ACTION = 'pushpull_merge_generateblocks';
    private const APPLY_ACTION = 'pushpull_apply_generateblocks';
    private const PUSH_ACTION = 'pushpull_push_generateblocks';
    private const RESET_REMOTE_ACTION = 'pushpull_reset_remote_generateblocks';
    private const RESOLVE_CONFLICT_ACTION = 'pushpull_resolve_conflict_generateblocks';
    private const FINALIZE_MERGE_ACTION = 'pushpull_finalize_merge_generateblocks';

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly LocalRepositoryInterface $localRepository,
        private readonly ManagedContentAdapterInterface $managedContentAdapter,
        private readonly SyncServiceInterface $syncService,
        private readonly WorkingStateRepository $workingStateRepository,
        private readonly ManagedSetConflictResolutionService $conflictResolutionService,
        private readonly OperationExecutor $operationExecutor
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

        $settings = $this->settingsRepository->get();
        $isInitialized = $this->localRepository->hasBeenInitialized($settings->branch);
        $headCommit = $this->localRepository->getHeadCommit($settings->branch);
        $exportPreview = $this->buildExportPreview();
        $commitNotice = $this->commitNotice();
        $diffResult = $this->buildDiffResult();
        $workingState = $this->workingStateRepository->get($this->managedContentAdapter->getManagedSetKey(), $settings->branch);

        echo '<div class="wrap pushpull-admin">';
        echo '<h1>' . esc_html__('Managed Content', 'pushpull') . '</h1>';
        echo '<p class="pushpull-intro">' . esc_html__('Review managed content state, compare live, local, and remote snapshots, and run the GenerateBlocks global styles fetch, merge, apply, commit, and push workflow.', 'pushpull') . '</p>';
        if ($commitNotice !== null) {
            printf(
                '<div class="notice notice-%1$s"><p>%2$s</p></div>',
                esc_attr($commitNotice['type']),
                esc_html($commitNotice['message'])
            );
        }
        echo '<div class="pushpull-status-grid">';
        $this->statusCard(__('Current repo status', 'pushpull'), $isInitialized ? __('Initialized', 'pushpull') : __('Not initialized', 'pushpull'));
        $this->statusCard(__('Ahead / behind', 'pushpull'), $diffResult !== null ? $diffResult->repositoryRelationship->label() : __('Unavailable', 'pushpull'));
        $this->statusCard(__('Uncommitted changes', 'pushpull'), $diffResult !== null ? $this->uncommittedSummary($diffResult) : __('Unavailable', 'pushpull'));
        $this->statusCard(__('Last local commit', 'pushpull'), $headCommit !== null ? $headCommit->hash : __('None recorded', 'pushpull'));
        $this->statusCard(__('Last remote fetch', 'pushpull'), $diffResult?->remote->commitHash ?? __('Not fetched', 'pushpull'));
        $this->statusCard(__('Merge / conflict state', 'pushpull'), $this->mergeStateSummary($workingState));
        echo '</div>';

        echo '<div class="pushpull-panel">';
        echo '<h2>' . esc_html__('Workflow Actions', 'pushpull') . '</h2>';
        echo '<div class="pushpull-button-grid">';
        $this->renderCommitButton($settings->manageGenerateBlocksGlobalStyles && $this->managedContentAdapter->isAvailable());
        $this->renderFetchButton($settings->manageGenerateBlocksGlobalStyles);
        $this->renderDisabledActionButton(__('Pull', 'pushpull'));
        $this->renderPushButton($settings->manageGenerateBlocksGlobalStyles);
        $this->renderMergeButton($settings->manageGenerateBlocksGlobalStyles);
        $this->renderApplyButton($settings->manageGenerateBlocksGlobalStyles);
        $this->renderResetRemoteButton($settings->manageGenerateBlocksGlobalStyles);
        $this->renderResolveConflictsButton($workingState);
        printf(
            '<a class="button button-secondary" href="%s">%s</a>',
            esc_url('#pushpull-diff'),
            esc_html__('View diff', 'pushpull')
        );
        echo '</div>';
        echo '</div>';

        if ($diffResult !== null) {
            echo '<div id="pushpull-diff" class="pushpull-panel">';
            echo '<h2>' . esc_html__('Diff Summary', 'pushpull') . '</h2>';
            printf('<p>%s</p>', esc_html(sprintf(
                'Live vs local: %d changed file(s). Local vs remote: %d changed file(s).',
                $diffResult->liveToLocal->changedCount(),
                $diffResult->localToRemote->changedCount()
            )));
            printf('<p class="description">%s</p>', esc_html($exportPreview['summary']));
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

        if ($workingState !== null && ($workingState->hasConflicts() || $workingState->mergeTargetHash !== null)) {
            $this->renderConflictPanel($workingState);
        }
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
    private function buildExportPreview(): array
    {
        if (! $this->managedContentAdapter->isAvailable()) {
            return [
                'summary' => 'GenerateBlocks global styles post type is not available on this site.',
                'paths' => [],
            ];
        }

        try {
            $items = $this->managedContentAdapter->exportAll();
        } catch (ManagedContentExportException $exception) {
            return [
                'summary' => $exception->getMessage(),
                'paths' => [],
            ];
        }

        $paths = [];

        foreach (array_slice($items, 0, 5) as $item) {
            $paths[] = $this->managedContentAdapter->getRepositoryPath($item);
        }

        if ($this->managedContentAdapter instanceof GenerateBlocksGlobalStylesAdapter) {
            $paths[] = $this->managedContentAdapter->getManifestPath();
        }

        return [
            'summary' => sprintf(
                'Adapter export preview found %d item(s) for %s.',
                count($items),
                $this->managedContentAdapter->getManagedSetLabel()
            ),
            'paths' => $paths,
        ];
    }

    private function buildDiffResult(): ?ManagedSetDiffResult
    {
        try {
            return $this->syncService->diff($this->managedContentAdapter->getManagedSetKey());
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

        if (! $settings->manageGenerateBlocksGlobalStyles) {
            $this->redirectWithNotice('error', 'GenerateBlocks global styles is not enabled in settings.');
        }

        if (! $this->managedContentAdapter->isAvailable()) {
            $this->redirectWithNotice('error', 'GenerateBlocks global styles is not available on this site.');
        }

        try {
            $result = $this->operationExecutor->run(
                $this->managedContentAdapter->getManagedSetKey(),
                'commit',
                ['branch' => $settings->branch],
                fn () => $this->syncService->commitManagedSet(
                    $this->managedContentAdapter->getManagedSetKey(),
                    new CommitManagedSetRequest(
                        $settings->branch,
                        'Commit live GenerateBlocks global styles',
                        $settings->authorName !== '' ? $settings->authorName : wp_get_current_user()->display_name,
                        $settings->authorEmail !== '' ? $settings->authorEmail : (wp_get_current_user()->user_email ?? '')
                    )
                )
            );
        } catch (ManagedContentExportException | RuntimeException $exception) {
            $this->redirectWithNotice('error', $exception->getMessage());
        }

        $message = $result->createdNewCommit
            ? sprintf('Committed %d file(s) to local branch %s.', count($result->pathHashes), $settings->branch)
            : sprintf('No local commit created. Branch %s already matches the live managed content.', $settings->branch);

        $this->redirectWithNotice('success', $message);
    }

    public function handleFetch(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::FETCH_ACTION);

        $settings = $this->settingsRepository->get();

        if (! $settings->manageGenerateBlocksGlobalStyles) {
            $this->redirectWithNotice('error', 'GenerateBlocks global styles is not enabled in settings.');
        }

        try {
            $result = $this->operationExecutor->run(
                $this->managedContentAdapter->getManagedSetKey(),
                'fetch',
                ['branch' => $settings->branch],
                fn () => $this->syncService->fetch($this->managedContentAdapter->getManagedSetKey())
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message);
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

        $this->redirectWithNotice('success', $message);
    }

    public function handleMerge(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::MERGE_ACTION);

        $settings = $this->settingsRepository->get();

        if (! $settings->manageGenerateBlocksGlobalStyles) {
            $this->redirectWithNotice('error', 'GenerateBlocks global styles is not enabled in settings.');
        }

        try {
            $result = $this->operationExecutor->run(
                $this->managedContentAdapter->getManagedSetKey(),
                'merge',
                ['branch' => $settings->branch],
                fn () => $this->syncService->merge($this->managedContentAdapter->getManagedSetKey())
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message);
        }

        $message = match ($result->status) {
            'already_up_to_date' => sprintf('Local branch %s is already up to date with the fetched remote commit.', $settings->branch),
            'fast_forward' => sprintf('Fast-forwarded local branch %s to %s.', $settings->branch, $result->theirsCommitHash),
            'merged' => sprintf('Created merge commit %s on local branch %s.', $result->commit?->hash, $settings->branch),
            'conflict' => sprintf('Merge requires resolution. Stored %d conflict(s) for branch %s.', count($result->conflicts), $settings->branch),
            default => 'Merge completed.',
        };

        $this->redirectWithNotice($result->hasConflicts() ? 'error' : 'success', $message);
    }

    public function handleApply(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::APPLY_ACTION);

        $settings = $this->settingsRepository->get();

        if (! $settings->manageGenerateBlocksGlobalStyles) {
            $this->redirectWithNotice('error', 'GenerateBlocks global styles is not enabled in settings.');
        }

        try {
            $result = $this->operationExecutor->run(
                $this->managedContentAdapter->getManagedSetKey(),
                'apply',
                ['branch' => $settings->branch],
                fn () => $this->syncService->apply($this->managedContentAdapter->getManagedSetKey())
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message);
        }

        $message = sprintf(
            'Applied local branch %s commit %s to WordPress. Created %d item(s), updated %d item(s), deleted %d item(s).',
            $result->branch,
            $result->sourceCommitHash,
            $result->createdCount,
            $result->updatedCount,
            count($result->deletedLogicalKeys)
        );

        $this->redirectWithNotice('success', $message);
    }

    public function handlePush(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::PUSH_ACTION);

        $settings = $this->settingsRepository->get();

        if (! $settings->manageGenerateBlocksGlobalStyles) {
            $this->redirectWithNotice('error', 'GenerateBlocks global styles is not enabled in settings.');
        }

        try {
            $result = $this->operationExecutor->run(
                $this->managedContentAdapter->getManagedSetKey(),
                'push',
                ['branch' => $settings->branch],
                fn () => $this->syncService->push($this->managedContentAdapter->getManagedSetKey())
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message);
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

        $this->redirectWithNotice('success', $message);
    }

    public function handleResetRemote(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::RESET_REMOTE_ACTION);

        $settings = $this->settingsRepository->get();

        if (! $settings->manageGenerateBlocksGlobalStyles) {
            $this->redirectWithNotice('error', 'GenerateBlocks global styles is not enabled in settings.');
        }

        try {
            $result = $this->operationExecutor->run(
                $this->managedContentAdapter->getManagedSetKey(),
                'reset_remote',
                ['branch' => $settings->branch],
                fn () => $this->syncService->resetRemote($this->managedContentAdapter->getManagedSetKey())
            );
        } catch (ManagedContentExportException | ProviderException | RuntimeException $exception) {
            $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();
            $this->redirectWithNotice('error', $message);
        }

        $message = sprintf(
            'Reset remote branch %s from %s to %s with a single empty-tree commit. Local branch content was not changed.',
            $result->branch,
            $result->previousRemoteCommitHash,
            $result->remoteCommitHash
        );

        $this->redirectWithNotice('success', $message);
    }

    public function handleResolveConflict(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::RESOLVE_CONFLICT_ACTION);

        $settings = $this->settingsRepository->get();
        $path = isset($_POST['path']) ? sanitize_text_field(wp_unslash((string) $_POST['path'])) : '';
        $strategy = isset($_POST['strategy']) ? sanitize_key((string) $_POST['strategy']) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The value may contain raw JSON and is protected by nonce verification above.
        $manualContent = isset($_POST['manual_content']) ? (string) wp_unslash((string) $_POST['manual_content']) : '';

        if ($path === '' || ! in_array($strategy, ['ours', 'theirs', 'manual'], true)) {
            $this->redirectWithNotice('error', 'Conflict resolution request was incomplete.');
        }

        try {
            $result = $this->operationExecutor->run(
                $this->managedContentAdapter->getManagedSetKey(),
                'resolve_conflict',
                ['branch' => $settings->branch, 'path' => $path, 'strategy' => $strategy],
                fn () => match ($strategy) {
                    'ours' => $this->conflictResolutionService->resolveUsingOurs($this->managedContentAdapter->getManagedSetKey(), $settings->branch, $path),
                    'theirs' => $this->conflictResolutionService->resolveUsingTheirs($this->managedContentAdapter->getManagedSetKey(), $settings->branch, $path),
                    default => $this->conflictResolutionService->resolveUsingManual($this->managedContentAdapter->getManagedSetKey(), $settings->branch, $path, $manualContent),
                }
            );
        } catch (RuntimeException $exception) {
            $this->redirectWithNotice('error', $exception->getMessage());
        }

        $message = $result->remainingConflictCount > 0
            ? sprintf('Resolved conflict for %s. %d conflict(s) remain.', $result->path, $result->remainingConflictCount)
            : sprintf('Resolved conflict for %s. All conflicts are resolved; finalize the merge to create the merge commit.', $result->path);

        $this->redirectWithNotice('success', $message);
    }

    public function handleFinalizeMerge(): void
    {
        if (! current_user_can(Capabilities::MANAGE_PLUGIN)) {
            wp_die(esc_html__('You do not have permission to manage PushPull.', 'pushpull'));
        }

        check_admin_referer(self::FINALIZE_MERGE_ACTION);

        $settings = $this->settingsRepository->get();

        try {
            $result = $this->operationExecutor->run(
                $this->managedContentAdapter->getManagedSetKey(),
                'finalize_merge',
                ['branch' => $settings->branch],
                fn () => $this->conflictResolutionService->finalize($this->managedContentAdapter->getManagedSetKey(), $settings->branch)
            );
        } catch (RuntimeException $exception) {
            $this->redirectWithNotice('error', $exception->getMessage());
        }

        $this->redirectWithNotice('success', sprintf(
            'Finalized merge on branch %s with merge commit %s.',
            $result->branch,
            $result->commit->hash
        ));
    }

    private function renderCommitButton(bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-primary" disabled="disabled">%s</button>',
                esc_html__('Commit', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="pushpull_commit_generateblocks" />';
        wp_nonce_field(self::COMMIT_ACTION);
        submit_button(__('Commit', 'pushpull'), 'primary', 'submit', false);
        echo '</form>';
    }

    private function renderFetchButton(bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
                esc_html__('Fetch', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="pushpull_fetch_generateblocks" />';
        wp_nonce_field(self::FETCH_ACTION);
        submit_button(__('Fetch', 'pushpull'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderMergeButton(bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
                esc_html__('Merge', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="pushpull_merge_generateblocks" />';
        wp_nonce_field(self::MERGE_ACTION);
        submit_button(__('Merge', 'pushpull'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderPushButton(bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
                esc_html__('Push', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="pushpull_push_generateblocks" />';
        wp_nonce_field(self::PUSH_ACTION);
        submit_button(__('Push', 'pushpull'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderApplyButton(bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
                esc_html__('Apply repo to WordPress', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return window.confirm(\'Apply the local repository state back into WordPress? This will update existing managed styles and remove local styles that are not present in the repository.\');">';
        echo '<input type="hidden" name="action" value="pushpull_apply_generateblocks" />';
        wp_nonce_field(self::APPLY_ACTION);
        submit_button(__('Apply repo to WordPress', 'pushpull'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderResetRemoteButton(bool $enabled): void
    {
        if (! $enabled) {
            printf(
                '<button type="button" class="button button-secondary" disabled="disabled">%s</button>',
                esc_html__('Reset remote branch', 'pushpull')
            );

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return window.confirm(\'Reset the remote branch to an empty commit? This will not delete Git history, but it will create one new remote commit that removes all tracked files from the branch.\');">';
        echo '<input type="hidden" name="action" value="pushpull_reset_remote_generateblocks" />';
        wp_nonce_field(self::RESET_REMOTE_ACTION);
        submit_button(__('Reset remote branch', 'pushpull'), 'delete', 'submit', false);
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

    private function renderConflictPanel(\PushPull\Persistence\WorkingState\WorkingStateRecord $workingState): void
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
        echo '<input type="hidden" name="action" value="pushpull_finalize_merge_generateblocks" />';
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
        echo '<input type="hidden" name="action" value="pushpull_resolve_conflict_generateblocks" />';
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

    private function redirectWithNotice(string $status, string $message): never
    {
        $url = add_query_arg(
            [
                'page' => self::MENU_SLUG,
                'pushpull_commit_status' => $status,
                'pushpull_commit_message' => $message,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
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
