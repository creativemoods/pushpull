<?php

declare(strict_types=1);

namespace PushPull\Cli;

use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\ManifestManagedContentAdapterInterface;
use PushPull\Domain\Merge\ManagedSetConflictResolutionService;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\RemoteRepositoryInitializer;
use PushPull\Domain\Sync\SyncServiceInterface;
use PushPull\Persistence\LocalRepositoryResetService;
use PushPull\Provider\Exception\ProviderException;
use PushPull\Provider\Exception\UnsupportedProviderException;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Integration\Contracts\SiteKeyActivationServiceInterface;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\FetchAvailability\FetchAvailabilityService;
use RuntimeException;
use WP_CLI;
use WP_CLI_Command;

final class PushPullCliCommand extends WP_CLI_Command
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly ManagedSetRegistry $managedSetRegistry,
        private readonly SyncServiceInterface $syncService,
        private readonly GitProviderFactoryInterface $providerFactory,
        private readonly LocalRepositoryResetService $localRepositoryResetService,
        private readonly RemoteRepositoryInitializer $remoteRepositoryInitializer,
        private readonly ManagedSetConflictResolutionService $conflictResolutionService,
        private readonly FetchAvailabilityService $fetchAvailabilityService,
        private readonly LocalRepositoryInterface $localRepository,
        private readonly SiteKeyActivationServiceInterface $wpmlSiteKeyActivationService
    ) {
    }

    /**
     * Show high-level PushPull status.
     */
    public function status(array $args, array $assocArgs): void
    {
        $settings = $this->settingsRepository->get();
        $rows = [];

        foreach ($this->enabledManagedSetKeys() as $managedSetKey) {
            $adapter = $this->managedSetRegistry->get($managedSetKey);
            $diff = $this->syncService->diff($managedSetKey);
            $rows[] = [
                'managed_set' => $managedSetKey,
                'label' => $adapter->getManagedSetLabel(),
                'available' => $adapter->isAvailable() ? 'yes' : 'no',
                'branch_state' => array_key_exists($adapter->getManifestPath(), $diff->local->files) ? 'present' : 'absent',
                'live_local' => $diff->liveToLocal->hasChanges() ? 'changed' : 'clean',
                'local_remote' => $diff->localToRemote->hasChanges() ? 'changed' : 'clean',
                'relationship' => $diff->repositoryRelationship->status,
            ];
        }

        WP_CLI::line(sprintf('Branch: %s', $settings->branch));
        WP_CLI::line(sprintf('Enabled domains: %d', count($rows)));
        $fetchState = $this->fetchAvailabilityService->getCachedState($settings);
        WP_CLI::line(sprintf('Fetch availability: %s', $fetchState['status']));

        if ($rows === []) {
            WP_CLI::success('No enabled domains.');
            return;
        }

        $this->renderRows($rows, ['managed_set', 'label', 'available', 'branch_state', 'live_local', 'local_remote', 'relationship']);

        $absentManagedSets = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['branch_state'] ?? '') === 'absent'
        ));

        if ($absentManagedSets !== []) {
            WP_CLI::warning(sprintf(
                'Enabled domain(s) absent from the current local branch: %s',
                implode(', ', array_map(static fn (array $row): string => (string) $row['managed_set'], $absentManagedSets))
            ));
        }
    }

    /**
     * List domains known to PushPull.
     */
    public function domains(array $args, array $assocArgs): void
    {
        $settings = $this->settingsRepository->get();
        $rows = [];

        foreach ($this->managedSetRegistry->allInDependencyOrder() as $managedSetKey => $adapter) {
            $rows[] = [
                'managed_set' => $managedSetKey,
                'label' => $adapter->getManagedSetLabel(),
                'enabled' => $settings->isManagedSetEnabled($managedSetKey) ? 'yes' : 'no',
                'available' => $adapter->isAvailable() ? 'yes' : 'no',
            ];
        }

        $this->renderRows($rows, ['managed_set', 'label', 'enabled', 'available']);
    }

    /**
     * Test the configured remote connection.
     *
     * @subcommand test-connection
     */
    public function testConnection(array $args, array $assocArgs): void
    {
        $settings = $this->settingsRepository->get();

        try {
            $provider = $this->providerFactory->make($settings->providerKey);
            $result = $provider->testConnection(GitRemoteConfig::fromSettings($settings));
        } catch (ProviderException | UnsupportedProviderException $exception) {
            $this->failFromException($exception);
        }

        $message = $result->emptyRepository
            ? sprintf(
                'Connection successful for %s, but the repository is empty. Initialize branch %s to create the first commit and enable fetch and push.',
                $result->repositoryPath,
                $result->resolvedBranch ?? $settings->branch
            )
            : sprintf(
                'Connection successful for %s. Default branch: %s. Resolved branch: %s.',
                $result->repositoryPath,
                $result->defaultBranch ?? 'n/a',
                $result->resolvedBranch ?? 'n/a'
            );

        WP_CLI::success($message);
    }

    /**
     * Reset the local PushPull repository state.
     *
     * @subcommand reset-local-repository
     */
    public function resetLocalRepository(array $args, array $assocArgs): void
    {
        $this->localRepositoryResetService->reset();
        WP_CLI::success('Local PushPull repository state reset.');
    }

    /**
     * Initialize the configured remote repository branch.
     *
     * @subcommand initialize-remote-repository
     *
     * ## OPTIONS
     *
     * [--managed-set=<managed-set>]
     * : Managed set key used for the initial fetch. Defaults to the first enabled domain.
     */
    public function initializeRemoteRepository(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->managedSetKeyFromAssocArgs($assocArgs, false);

        try {
            $result = $this->remoteRepositoryInitializer->initialize($managedSetKey, $this->settingsRepository->get());
        } catch (ProviderException | RuntimeException $exception) {
            $this->failFromException($exception);
        }

        WP_CLI::success(sprintf(
            'Initialized remote branch %s at %s and fetched it into %s.',
            $result->branch,
            $result->remoteCommitHash,
            $result->remoteRefName
        ));
    }

    /**
     * Reset the configured remote branch to an empty-tree commit.
     *
     * @subcommand reset-remote-branch
     *
     * ## OPTIONS
     *
     * [--managed-set=<managed-set>]
     * : Managed set key used for the branch operation. Defaults to the first enabled domain.
     */
    public function resetRemoteBranch(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->managedSetKeyFromAssocArgs($assocArgs, false);

        try {
            $result = $this->syncService->resetRemote($managedSetKey);
        } catch (ProviderException | RuntimeException $exception) {
            $this->failFromException($exception);
        }

        WP_CLI::success(sprintf(
            'Reset remote branch %s from %s to %s.',
            $result->branch,
            $result->previousRemoteCommitHash,
            $result->remoteCommitHash
        ));
    }

    /**
     * Commit one managed domain to the local branch.
     *
     * ## OPTIONS
     *
     * <managed-set>
     * : Managed set key to commit.
     */
    public function commit(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->requiredManagedSetKey($args);
        $adapter = $this->requireEnabledAvailableManagedSet($managedSetKey);
        $settings = $this->settingsRepository->get();

        try {
            $result = $this->syncService->commitManagedSet(
                $managedSetKey,
                new CommitManagedSetRequest(
                    $settings->branch,
                    $adapter->buildCommitMessage(),
                    $settings->authorName !== '' ? $settings->authorName : wp_get_current_user()->display_name,
                    $settings->authorEmail !== '' ? $settings->authorEmail : (wp_get_current_user()->user_email ?? '')
                )
            );
        } catch (RuntimeException $exception) {
            $this->failFromException($exception);
        }

        if (! $result->createdNewCommit) {
            WP_CLI::success(sprintf('No local commit created. Branch %s already matches the live managed content.', $settings->branch));
            return;
        }

        WP_CLI::success(sprintf('Committed %d file(s) to local branch %s.', count($result->pathHashes), $settings->branch));
    }

    /**
     * Fetch the configured remote branch.
     *
     * ## OPTIONS
     *
     * [--managed-set=<managed-set>]
     * : Managed set key used for the branch operation. Defaults to the first enabled domain.
     */
    public function fetch(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->managedSetKeyFromAssocArgs($assocArgs, false);

        try {
            $result = $this->syncService->fetch($managedSetKey);
        } catch (ProviderException | RuntimeException $exception) {
            $this->failFromException($exception);
        }

        WP_CLI::success(sprintf(
            'Fetched remote commit %s into %s. Newly imported %d commit(s), %d tree(s), and %d blob(s).',
            $result->remoteCommitHash,
            $result->remoteRefName,
            count($result->newCommitHashes),
            count($result->newTreeHashes),
            count($result->newBlobHashes)
        ));
    }

    /**
     * Pull the configured remote branch into local.
     *
     * ## OPTIONS
     *
     * [--managed-set=<managed-set>]
     * : Managed set key used for the branch operation. Defaults to the first enabled domain.
     */
    public function pull(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->managedSetKeyFromAssocArgs($assocArgs, false);

        try {
            $result = $this->syncService->pull($managedSetKey);
        } catch (ProviderException | RuntimeException $exception) {
            $this->failFromException($exception);
        }

        $message = match ($result->mergeResult->status) {
            'already_up_to_date' => sprintf('Fetched %s into %s. Local branch %s was already up to date after fetch.', $result->fetchResult->remoteCommitHash, $result->fetchResult->remoteRefName, $result->branch),
            'fast_forward' => sprintf('Fetched %s into %s and fast-forwarded local to %s.', $result->fetchResult->remoteCommitHash, $result->fetchResult->remoteRefName, $result->mergeResult->theirsCommitHash),
            'merged' => sprintf('Fetched %s into %s and created merge commit %s.', $result->fetchResult->remoteCommitHash, $result->fetchResult->remoteRefName, $result->mergeResult->commit?->hash),
            'conflict' => sprintf('Fetched %s into %s, but merge requires resolution. Stored %d conflict(s).', $result->fetchResult->remoteCommitHash, $result->fetchResult->remoteRefName, count($result->mergeResult->conflicts)),
            default => sprintf('Fetched %s into %s.', $result->fetchResult->remoteCommitHash, $result->fetchResult->remoteRefName),
        };

        if ($result->mergeResult->hasConflicts()) {
            WP_CLI::warning($message);
            return;
        }

        WP_CLI::success($message);
    }

    /**
     * Merge the fetched remote-tracking state into the local branch.
     *
     * ## OPTIONS
     *
     * [--managed-set=<managed-set>]
     * : Managed set key used for the branch operation. Defaults to the first enabled domain.
     */
    public function merge(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->managedSetKeyFromAssocArgs($assocArgs, false);

        try {
            $result = $this->syncService->merge($managedSetKey);
        } catch (RuntimeException $exception) {
            $this->failFromException($exception);
        }

        $message = match ($result->status) {
            'already_up_to_date' => sprintf('Local branch %s is already up to date with the fetched remote commit.', $this->settingsRepository->get()->branch),
            'fast_forward' => sprintf('Fast-forwarded local branch %s to %s.', $this->settingsRepository->get()->branch, $result->theirsCommitHash),
            'merged' => sprintf('Created merge commit %s on local branch %s.', $result->commit?->hash, $this->settingsRepository->get()->branch),
            'conflict' => sprintf('Merge requires resolution. Stored %d conflict(s) for branch %s.', count($result->conflicts), $this->settingsRepository->get()->branch),
            default => 'Merge completed.',
        };

        if ($result->hasConflicts()) {
            WP_CLI::warning($message);
            return;
        }

        WP_CLI::success($message);
    }

    /**
     * Apply one managed domain from the local repository back into WordPress.
     *
     * ## OPTIONS
     *
     * <managed-set>
     * : Managed set key to apply.
     */
    public function apply(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->requiredManagedSetKey($args);
        $this->requireEnabledAvailableManagedSet($managedSetKey);

        try {
            $result = $this->syncService->apply($managedSetKey);
        } catch (RuntimeException $exception) {
            $this->failFromException($exception);
        }

        WP_CLI::success(sprintf(
            'Applied local branch %s commit %s to WordPress. Created %d item(s), updated %d item(s), deleted %d item(s).',
            $result->branch,
            $result->sourceCommitHash,
            $result->createdCount,
            $result->updatedCount,
            count($result->deletedLogicalKeys)
        ));
    }

    /**
     * Push the local branch to the remote provider.
     *
     * ## OPTIONS
     *
     * [--managed-set=<managed-set>]
     * : Managed set key used for the branch operation. Defaults to the first enabled domain.
     */
    public function push(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->managedSetKeyFromAssocArgs($assocArgs, false);

        try {
            $result = $this->syncService->push($managedSetKey);
        } catch (ProviderException | RuntimeException $exception) {
            $this->failFromException($exception);
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

        WP_CLI::success($message);
    }

    /**
     * Commit all enabled available domains, then push the branch.
     *
     * @subcommand commit-push-all
     */
    public function commitPushAll(array $args, array $assocArgs): void
    {
        $settings = $this->settingsRepository->get();
        $managedSetKeys = $this->enabledAvailableManagedSetKeys();
        $branchManagedSetKey = $this->branchManagedSetKey();

        if ($managedSetKeys === [] || $branchManagedSetKey === null) {
            WP_CLI::error('Enable at least one available managed domain to use this action.');
        }

        $committedDomainCount = 0;
        $committedFileCount = 0;
        try {
            foreach ($managedSetKeys as $managedSetKey) {
                $adapter = $this->managedSetRegistry->get($managedSetKey);
                $result = $this->syncService->commitManagedSet(
                    $managedSetKey,
                    new CommitManagedSetRequest(
                        $settings->branch,
                        $adapter->buildCommitMessage(),
                        $settings->authorName !== '' ? $settings->authorName : wp_get_current_user()->display_name,
                        $settings->authorEmail !== '' ? $settings->authorEmail : (wp_get_current_user()->user_email ?? '')
                    )
                );

                if (! $result->createdNewCommit) {
                    continue;
                }

                $committedDomainCount++;
                $committedFileCount += count($result->pathHashes);
            }

            $pushResult = $this->syncService->push($branchManagedSetKey);
        } catch (ProviderException | RuntimeException $exception) {
            $this->failFromException($exception);
        }

        if ($pushResult->status === 'already_up_to_date' && $committedDomainCount === 0) {
            WP_CLI::success('Nothing to commit or push. Live content and the remote branch are already up to date.');
            return;
        }

        WP_CLI::success(sprintf(
            'Committed %1$d changed domain(s) across %2$d file(s) and pushed branch %3$s to remote commit %4$s.',
            $committedDomainCount,
            $committedFileCount,
            $pushResult->branch,
            $pushResult->remoteCommitHash
        ));
    }

    /**
     * Pull the branch and apply all enabled available domains to WordPress.
     *
     * @subcommand pull-apply-all
     */
    public function pullApplyAll(array $args, array $assocArgs): void
    {
        $managedSetKeys = $this->enabledAvailableManagedSetKeys();
        $branchManagedSetKey = $this->branchManagedSetKey();

        if ($managedSetKeys === [] || $branchManagedSetKey === null) {
            WP_CLI::error('Enable at least one available managed domain to use this action.');
        }

        try {
            $pullResult = $this->syncService->pull($branchManagedSetKey);
        } catch (ProviderException | RuntimeException $exception) {
            $this->failFromException($exception);
        }

        if ($pullResult->mergeResult->hasConflicts()) {
            WP_CLI::error(sprintf(
                'Pull requires conflict resolution before PushPull can apply the repository to WordPress. Stored %d conflict(s).',
                count($pullResult->mergeResult->conflicts)
            ));
        }

        $createdCount = 0;
        $updatedCount = 0;
        $deletedCount = 0;
        $appliedDomainCount = 0;

        try {
            foreach ($managedSetKeys as $managedSetKey) {
                $diffResult = $this->syncService->diff($managedSetKey);

                if (! $diffResult->liveToLocal->hasChanges()) {
                    continue;
                }

                $result = $this->syncService->apply($managedSetKey);
                $appliedDomainCount++;
                $createdCount += $result->createdCount;
                $updatedCount += $result->updatedCount;
                $deletedCount += count($result->deletedLogicalKeys);
            }
        } catch (RuntimeException $exception) {
            $this->failFromException($exception);
        }

        WP_CLI::success(sprintf(
            'Pulled branch %1$s and applied %2$d managed domain(s) to WordPress. Created %3$d item(s), updated %4$d item(s), and deleted %5$d missing item(s).',
            $this->settingsRepository->get()->branch,
            $appliedDomainCount,
            $createdCount,
            $updatedCount,
            $deletedCount
        ));
    }

    /**
     * Resolve a stored merge conflict.
     *
     * @subcommand resolve-conflict
     *
     * ## OPTIONS
     *
     * <managed-set>
     * : Managed set key with the conflict.
     *
     * <path>
     * : Conflict path to resolve.
     *
     * --strategy=<strategy>
     * : Resolution strategy: ours, theirs, or manual.
     *
     * [--content=<json>]
     * : Manual JSON content when using --strategy=manual.
     */
    public function resolveConflict(array $args, array $assocArgs): void
    {
        if (count($args) < 2) {
            WP_CLI::error('Usage: wp pushpull resolve-conflict <managed-set> <path> --strategy=<ours|theirs|manual> [--content=<json>]');
        }

        $managedSetKey = sanitize_key((string) $args[0]);
        $path = (string) $args[1];
        $strategy = sanitize_key((string) ($assocArgs['strategy'] ?? ''));
        $branch = $this->settingsRepository->get()->branch;

        try {
            $result = match ($strategy) {
                'ours' => $this->conflictResolutionService->resolveUsingOurs($managedSetKey, $branch, $path),
                'theirs' => $this->conflictResolutionService->resolveUsingTheirs($managedSetKey, $branch, $path),
                'manual' => $this->conflictResolutionService->resolveUsingManual($managedSetKey, $branch, $path, (string) ($assocArgs['content'] ?? '')),
                default => throw new RuntimeException('Strategy must be one of: ours, theirs, manual.'),
            };
        } catch (RuntimeException $exception) {
            $this->failFromException($exception);
        }

        $message = $result->remainingConflictCount > 0
            ? sprintf('Resolved conflict for %s. %d conflict(s) remain.', $result->path, $result->remainingConflictCount)
            : sprintf('Resolved conflict for %s. All conflicts are resolved; finalize the merge to create the merge commit.', $result->path);

        WP_CLI::success($message);
    }

    /**
     * Finalize a resolved merge state.
     *
     * @subcommand finalize-merge
     *
     * ## OPTIONS
     *
     * <managed-set>
     * : Managed set key with the merge state.
     */
    public function finalizeMerge(array $args, array $assocArgs): void
    {
        $managedSetKey = $this->requiredManagedSetKey($args);
        $branch = $this->settingsRepository->get()->branch;

        try {
            $result = $this->conflictResolutionService->finalize($managedSetKey, $branch);
        } catch (RuntimeException $exception) {
            $this->failFromException($exception);
        }

        WP_CLI::success(sprintf(
            'Finalized merge on branch %s with merge commit %s.',
            $result->branch,
            $result->commit->hash
        ));
    }

    /**
     * Register a WPML site key through WPML's internal installer path.
     *
     * @subcommand wpml-register-site-key
     *
     * ## OPTIONS
     *
     * <site-key>
     * : WPML site key to validate and register.
     */
    public function wpmlRegisterSiteKey(array $args, array $assocArgs): void
    {
        $siteKey = (string) ($args[0] ?? '');

        if ($siteKey === '') {
            WP_CLI::error('A WPML site key is required.');
        }

        try {
            $result = $this->wpmlSiteKeyActivationService->activateSiteKey($siteKey);
        } catch (RuntimeException $exception) {
            $this->failFromException($exception);
        }

        WP_CLI::success(sprintf(
            'Registered WPML site key through the installer for repository %s.',
            $result->integrationKey
        ));
    }

    private function requiredManagedSetKey(array $args): string
    {
        $managedSetKey = sanitize_key((string) ($args[0] ?? ''));

        if ($managedSetKey === '') {
            WP_CLI::error('A managed set key is required.');
        }

        return $managedSetKey;
    }

    private function managedSetKeyFromAssocArgs(array $assocArgs, bool $requireAvailable): string
    {
        $managedSetKey = sanitize_key((string) ($assocArgs['managed-set'] ?? ''));

        if ($managedSetKey !== '') {
            if ($requireAvailable) {
                $this->requireEnabledAvailableManagedSet($managedSetKey);
            } else {
                $this->requireEnabledManagedSet($managedSetKey);
            }

            return $managedSetKey;
        }

        $branchManagedSetKey = $this->branchManagedSetKey();

        if ($branchManagedSetKey === null) {
            WP_CLI::error('Enable at least one managed domain first.');
        }

        return $branchManagedSetKey;
    }

    private function requireEnabledManagedSet(string $managedSetKey): void
    {
        if (! $this->managedSetRegistry->has($managedSetKey)) {
            WP_CLI::error(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        if (! $this->settingsRepository->get()->isManagedSetEnabled($managedSetKey)) {
            WP_CLI::error(sprintf('Managed set "%s" is not enabled.', $managedSetKey));
        }
    }

    private function requireEnabledAvailableManagedSet(string $managedSetKey): ManifestManagedContentAdapterInterface
    {
        $this->requireEnabledManagedSet($managedSetKey);
        $adapter = $this->managedSetRegistry->get($managedSetKey);

        if (! $adapter->isAvailable()) {
            WP_CLI::error(sprintf('Managed set "%s" is not available on this site.', $managedSetKey));
        }

        return $adapter;
    }

    /**
     * @return string[]
     */
    private function enabledManagedSetKeys(): array
    {
        $settings = $this->settingsRepository->get();
        $managedSetKeys = [];

        foreach ($this->managedSetRegistry->allInDependencyOrder() as $managedSetKey => $_adapter) {
            if ($settings->isManagedSetEnabled($managedSetKey)) {
                $managedSetKeys[] = $managedSetKey;
            }
        }

        return $managedSetKeys;
    }

    /**
     * @return string[]
     */
    private function enabledAvailableManagedSetKeys(): array
    {
        return array_values(array_filter(
            $this->enabledManagedSetKeys(),
            fn (string $managedSetKey): bool => $this->managedSetRegistry->get($managedSetKey)->isAvailable()
        ));
    }

    private function branchManagedSetKey(): ?string
    {
        $keys = $this->enabledManagedSetKeys();
        $first = array_key_first($keys);

        return is_int($first) ? $keys[$first] : null;
    }

    private function failFromException(ProviderException|UnsupportedProviderException|RuntimeException $exception): never
    {
        if ($exception instanceof ProviderException) {
            WP_CLI::error($exception->debugSummary());
        }

        WP_CLI::error($exception->getMessage());
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
