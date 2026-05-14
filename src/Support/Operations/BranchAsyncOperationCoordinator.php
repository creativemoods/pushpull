<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Domain\Apply\ManagedSetApplyServiceInterface;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Apply\ConfigManagedSetApplyService;
use PushPull\Domain\Apply\OverlayManagedSetApplyService;
use PushPull\Content\ConfigManagedContentAdapterInterface;
use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\OverlayManagedContentAdapterInterface;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Domain\Push\ManagedSetPushService;
use PushPull\Domain\Sync\BranchCommitService;
use PushPull\Domain\Sync\CommitBranchResult;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\SyncServiceInterface;
use PushPull\Domain\Sync\FetchObjectGraphWalker;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Persistence\Operations\OperationLogRepository;
use PushPull\Persistence\Operations\OperationRecord;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\RemoteRef;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\FetchAvailability\FetchAvailabilityService;
use RuntimeException;

if (! defined('ABSPATH')) {
    exit;
}

final class BranchAsyncOperationCoordinator implements AsyncOperationHandlerInterface, BranchAsyncOperationContextInterface
{
    private const CHUNK_NODE_LIMIT = 12;
    private const ASYNC_TYPE = 'branch_action';
    /** @var array<string, ManagedSetApplyServiceInterface> */
    private array $applyServicesByManagedSetKey;
    private ?AsyncOperationEngine $asyncOperationEngine = null;
    /** @var BranchAsyncOperationHandlerInterface[]|null */
    private ?array $branchHandlers = null;

    public function __construct(
        private readonly OperationLogRepository $operationLogRepository,
        private readonly OperationLockService $operationLockService,
        private readonly SettingsRepository $settingsRepository,
        private readonly LocalRepositoryInterface $localRepository,
        private readonly GitProviderFactoryInterface $providerFactory,
        private readonly SyncServiceInterface $syncService,
        array $managedSetApplyServices = [],
        private readonly ?ManagedSetRegistry $managedSetRegistry = null,
        private readonly ?RepositoryStateReader $repositoryStateReader = null,
        private readonly ?ContentMapRepository $contentMapRepository = null,
        private readonly ?WorkingStateRepository $workingStateRepository = null,
        private readonly ?FetchAvailabilityService $fetchAvailabilityService = null
    ) {
        $this->applyServicesByManagedSetKey = $managedSetApplyServices;
    }

    /**
     * @return array{operationId: int, progressMessage: string, done: bool, status?: string, redirectUrl?: string, progress: array<string, mixed>}
     */
    public function start(string $managedSetKey, string $operationType, array $payload = []): array
    {
        $settings = $this->settingsRepository->get();
        $this->guardOperationAllowedBySiteMode($settings, $operationType);

        return $this->asyncOperationEngine()->start($managedSetKey, $operationType, array_merge([
            'branch' => $settings->branch,
            'async' => true,
            'asyncType' => self::ASYNC_TYPE,
        ], $payload));
    }

    /**
     * @return array{done: bool, status: string, message: string, progress: array<string, mixed>, redirectManagedSetKey?: string}
     */
    public function continue(int $operationId): array
    {
        return $this->asyncOperationEngine()->continue($operationId);
    }

    public function cancel(int $operationId): OperationRecord
    {
        return $this->asyncOperationEngine()->cancel($operationId);
    }

    public function supportsAsyncOperation(string $operationType): bool
    {
        if (in_array($operationType, ['commit_push_all', 'pull_apply_all'], true)) {
            return true;
        }

        return $this->branchHandlerFor($operationType) !== null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function initializeAsyncOperation(OperationRecord $record, array $context, string $lockToken): array
    {
        return $this->initialState(
            $record,
            $record->operationType,
            (string) ($context['branch'] ?? ''),
            $lockToken
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array{done: bool, state?: array<string, mixed>, finalResult?: array<string, mixed>}
     */
    public function continueAsyncOperation(OperationRecord $record, array $state): array
    {
        return $this->continueOperation($record, $state);
    }

    /**
     * @param array<string, mixed> $finalResult
     */
    public function finalizeAsyncOperation(array $finalResult): void
    {
        $this->refreshFetchAvailability($finalResult);
    }

    /**
     * @param array<string, mixed> $finalResult
     */
    private function refreshFetchAvailability(array $finalResult): void
    {
        if (! $this->fetchAvailabilityService instanceof FetchAvailabilityService) {
            return;
        }

        $operationType = (string) ($finalResult['operationType'] ?? '');

        if (! in_array($operationType, ['fetch', 'pull', 'push', 'commit_push_all', 'pull_apply_all', 'reset_remote_branch'], true)) {
            return;
        }

        $branch = (string) ($finalResult['branch'] ?? '');
        $remoteCommitHash = (string) ($finalResult['remoteCommitHash'] ?? '');

        if ($branch === '' || $remoteCommitHash === '') {
            return;
        }

        $settings = $this->settingsRepository->get();

        if ($settings->branch !== $branch) {
            return;
        }

        $this->fetchAvailabilityService->markUpToDate($settings, $remoteCommitHash, $remoteCommitHash);
    }

    public function settings(): \PushPull\Settings\PushPullSettings
    {
        return $this->settingsRepository->get();
    }

    public function syncService(): SyncServiceInterface
    {
        return $this->syncService;
    }

    public function updateTrackingRef(string $trackingRefName, string $remoteCommitHash): void
    {
        $this->localRepository->updateRef($trackingRefName, $remoteCommitHash);
    }

    public function chunkNodeLimit(): int
    {
        return self::CHUNK_NODE_LIMIT;
    }

    private function asyncOperationEngine(): AsyncOperationEngine
    {
        if ($this->asyncOperationEngine === null) {
            $this->asyncOperationEngine = new AsyncOperationEngine(
                $this->operationLogRepository,
                $this->operationLockService,
                $this
            );
        }

        return $this->asyncOperationEngine;
    }

    private function branchHandlerFor(string $operationType): ?BranchAsyncOperationHandlerInterface
    {
        foreach ($this->branchHandlers() as $handler) {
            if ($handler->supports($operationType)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * @return BranchAsyncOperationHandlerInterface[]
     */
    private function branchHandlers(): array
    {
        if ($this->branchHandlers === null) {
            $this->branchHandlers = [
                new FetchPullBranchAsyncOperationHandler($this),
                new PushBranchAsyncOperationHandler($this),
                new ApplyBranchAsyncOperationHandler($this),
                new ResetRemoteBranchAsyncOperationHandler($this),
                new CommitPushAllBranchAsyncOperationHandler($this),
                new PullApplyAllBranchAsyncOperationHandler($this),
            ];
        }

        return $this->branchHandlers;
    }

    /**
     * @return array<string, mixed>
     */
    private function initialState(OperationRecord $record, string $operationType, string $branch, string $lockToken): array
    {
        $baseState = [
            'asyncType' => self::ASYNC_TYPE,
            'operationId' => $record->id,
            'operationType' => $operationType,
            'managedSetKey' => $record->managedSetKey,
            'branch' => $branch,
            'lockToken' => $lockToken,
        ];

        $handler = $this->branchHandlerFor($operationType);

        if ($handler !== null) {
            return $handler->initialize($record, $baseState, $branch);
        }

        throw new RuntimeException(sprintf('Async branch action "%s" is not supported.', $operationType));
    }

    /**
     * @param array<string, mixed> $state
     * @return array{done: bool, state?: array<string, mixed>, finalResult?: array<string, mixed>}
     */
    private function continueOperation(OperationRecord $record, array $state): array
    {
        $operationType = (string) ($record->operationType ?? '');

        $handler = $this->branchHandlerFor($operationType);

        if ($handler !== null) {
            return $handler->continue($record, $state);
        }

        throw new RuntimeException(sprintf('Async branch action "%s" is not supported.', $record->operationType));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function processFetchChunk(array $state, GitProviderInterface $provider, GitRemoteConfig $remoteConfig): array
    {
        $walker = new FetchObjectGraphWalker($provider, $this->localRepository, $remoteConfig);
        $state = $walker->continue($state, self::CHUNK_NODE_LIMIT);
        $state['progressMessage'] = $this->fetchProgressMessage($state, 'Fetching remote objects.');

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function finalFetchResult(OperationRecord $record, array $state): array
    {
        $summaryMessage = sprintf(
            'Fetched remote commit %s into %s. Newly imported %d commit(s), %d tree(s), and %d blob(s); traversed %d commit(s), %d tree(s), and %d blob(s).',
            $state['remoteCommitHash'],
            $state['remoteRefName'],
            count($state['newCommitHashes']),
            count($state['newTreeHashes']),
            count($state['newBlobHashes']),
            count($state['visitedCommitHashes']),
            count($state['visitedTreeHashes']),
            count($state['visitedBlobHashes'])
        );

        if (($state['archivePreloadMessage'] ?? '') !== '') {
            $summaryMessage .= ' ' . (string) $state['archivePreloadMessage'];
        }

        return [
            'summaryType' => 'success',
            'summaryMessage' => $summaryMessage,
            'operationType' => $record->operationType,
            'managedSetKey' => $record->managedSetKey,
            'branch' => $state['branch'],
            'remoteCommitHash' => $state['remoteCommitHash'],
            'remoteRefName' => $state['remoteRefName'],
            'newCommitHashes' => $this->sortedHashKeys($state['newCommitHashes']),
            'newTreeHashes' => $this->sortedHashKeys($state['newTreeHashes']),
            'newBlobHashes' => $this->sortedHashKeys($state['newBlobHashes']),
            'traversedCommitHashes' => $this->sortedHashKeys($state['visitedCommitHashes']),
            'traversedTreeHashes' => $this->sortedHashKeys($state['visitedTreeHashes']),
            'traversedBlobHashes' => $this->sortedHashKeys($state['visitedBlobHashes']),
            'progressMode' => 'indeterminate',
            'progressCurrent' => 0,
            'progressTotal' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function finalPullResult(OperationRecord $record, array $state, \PushPull\Domain\Merge\MergeManagedSetResult $mergeResult): array
    {
        $mergeMessage = match ($mergeResult->status) {
            'already_up_to_date' => sprintf('Local branch %s was already up to date after fetch.', $state['branch']),
            'fast_forward' => sprintf('Pulled remote branch %s and fast-forwarded local to %s.', $state['branch'], $mergeResult->theirsCommitHash),
            'merged' => sprintf('Pulled remote branch %s and created merge commit %s.', $state['branch'], $mergeResult->commit?->hash),
            'conflict' => sprintf('Pulled remote branch %s, but merge requires resolution. Stored %d conflict(s).', $state['branch'], count($mergeResult->conflicts)),
            default => sprintf('Pulled remote branch %s.', $state['branch']),
        };

        return [
            'summaryType' => $mergeResult->hasConflicts() ? 'error' : 'success',
            'summaryMessage' => sprintf(
                'Fetched %s into %s. %s',
                $state['remoteCommitHash'],
                $state['remoteRefName'],
                $mergeMessage
            ),
            'operationType' => $record->operationType,
            'managedSetKey' => $record->managedSetKey,
            'branch' => $state['branch'],
            'remoteCommitHash' => $state['remoteCommitHash'],
            'remoteRefName' => $state['remoteRefName'],
            'mergeStatus' => $mergeResult->status,
            'mergeCommitHash' => $mergeResult->commit?->hash,
            'conflictCount' => count($mergeResult->conflicts),
            'newCommitHashes' => $this->sortedHashKeys($state['newCommitHashes']),
            'newTreeHashes' => $this->sortedHashKeys($state['newTreeHashes']),
            'newBlobHashes' => $this->sortedHashKeys($state['newBlobHashes']),
            'traversedCommitHashes' => $this->sortedHashKeys($state['visitedCommitHashes']),
            'traversedTreeHashes' => $this->sortedHashKeys($state['visitedTreeHashes']),
            'traversedBlobHashes' => $this->sortedHashKeys($state['visitedBlobHashes']),
            'progressMode' => 'indeterminate',
            'progressCurrent' => 0,
            'progressTotal' => 0,
        ];
    }

    /**
     * @return array{0: GitRemoteConfig, 1: RemoteRef}
     */
    public function loadRemoteRef(string $branch): array
    {
        [$provider, $remoteConfig] = $this->resolveProvider();
        $remoteRef = $provider->getRef($remoteConfig, 'refs/heads/' . $branch);

        if ($remoteRef === null || $remoteRef->commitHash === '') {
            throw new RuntimeException(sprintf('Remote branch "%s" was not found.', $branch));
        }

        return [$remoteConfig, $remoteRef];
    }

    /**
     * @return array{0: GitProviderInterface, 1: GitRemoteConfig}
     */
    public function resolveProvider(): array
    {
        $settings = $this->settingsRepository->get();
        $remoteConfig = GitRemoteConfig::fromSettings($settings);
        $provider = $this->providerFactory->make($remoteConfig->providerKey);
        $validation = $provider->validateConfig($remoteConfig);

        if (! $validation->isValid()) {
            throw new RuntimeException(implode(' ', $validation->messages));
        }

        return [$provider, $remoteConfig];
    }

    public function pushService(): ManagedSetPushService
    {
        return new ManagedSetPushService($this->localRepository, $this->providerFactory);
    }

    /**
     * @param array<string, mixed> $pending
     */
    public function initialFetchState(GitRemoteConfig $remoteConfig, string $remoteCommitHash): array
    {
        $walker = new FetchObjectGraphWalker(
            $this->providerFactory->make($remoteConfig->providerKey),
            $this->localRepository,
            $remoteConfig
        );

        return $walker->initialState($remoteCommitHash);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function isFetchComplete(array $state): bool
    {
        return $state['pendingCommitHashes'] === []
            && $state['pendingTreeHashes'] === []
            && $state['pendingBlobHashes'] === [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function fetchProgressMessage(array $state, string $prefix): string
    {
        $message = sprintf(
            '%s Traversed %d commit(s), %d tree(s), and %d blob(s) so far.',
            $prefix,
            count($state['visitedCommitHashes']),
            count($state['visitedTreeHashes']),
            count($state['visitedBlobHashes'])
        );

        if (($state['archivePreloadMessage'] ?? '') !== '') {
            $message .= ' ' . (string) $state['archivePreloadMessage'];
        }

        return $message;
    }

    /**
     * @param array<string, bool> $hashMap
     * @return list<string>
     */
    private function sortedHashKeys(array $hashMap): array
    {
        $hashes = array_keys($hashMap);
        sort($hashes);

        return $hashes;
    }

    private function guardOperationAllowedBySiteMode(\PushPull\Settings\PushPullSettings $settings, string $operationType): void
    {
        if (in_array($operationType, ['apply', 'pull_apply_all'], true) && ! $settings->allowsLiveWrites()) {
            throw new RuntimeException('This site is configured as push-only. Applying repository state into WordPress is disabled.');
        }

        if (in_array($operationType, ['push', 'commit_push_all', 'reset_remote_branch'], true) && ! $settings->allowsRemoteWrites()) {
            throw new RuntimeException('This site is configured as pull-only. Pushing branch changes to the remote repository is disabled.');
        }
    }

    public function noticeUrl(string $status, string $message, ?string $pageSlug = null): string
    {
        return add_query_arg([
            'page' => $pageSlug !== null && $pageSlug !== '' ? $pageSlug : \PushPull\Admin\ManagedContentPage::MENU_SLUG,
            'pushpull_commit_status' => $status,
            'pushpull_commit_message' => $message,
        ], admin_url('admin.php'));
    }

    /**
     * @param string[] $managedSetKeys
     */
    public function commitManagedSets(array $managedSetKeys, CommitManagedSetRequest $request): CommitBranchResult
    {
        if (! $this->managedSetRegistry instanceof ManagedSetRegistry) {
            throw new RuntimeException('Managed set registry is unavailable for branch commits.');
        }

        $adapters = [];

        foreach ($this->managedSetRegistry->sortManagedSetKeysInDependencyOrder($managedSetKeys) as $managedSetKey) {
            $adapters[$managedSetKey] = $this->managedSetRegistry->get($managedSetKey);
        }

        return (new BranchCommitService($this->localRepository))->commitManagedSets($adapters, $request);
    }

    /**
     * @return string[]
     */
    public function enabledAvailableManagedSetKeys(): array
    {
        $settings = $this->settingsRepository->get();
        $managedSetKeys = [];

        foreach ($this->managedSetRegistry?->allInDependencyOrder() ?? [] as $managedSetKey => $adapter) {
            if (! $settings->isManagedSetEnabled($managedSetKey) || ! $adapter->isAvailable()) {
                continue;
            }

            $managedSetKeys[] = $managedSetKey;
        }

        return $managedSetKeys;
    }

    /**
     * @return array{managedSetKeys: string[], skippedManagedSets: array<int, array{label: string, message: string}>}
     */
    public function buildCommitPushAllPlan(): array
    {
        $managedSetKeys = [];
        $skippedManagedSets = [];

        foreach ($this->enabledAvailableManagedSetKeys() as $managedSetKey) {
            $adapter = $this->managedSetRegistry?->get($managedSetKey);

            try {
                $this->syncService->diff($managedSetKey);
            } catch (\Throwable $throwable) {
                $skippedManagedSets[] = [
                    'label' => $adapter?->getManagedSetLabel() ?? $managedSetKey,
                    'message' => $throwable->getMessage(),
                ];
                continue;
            }

            $managedSetKeys[] = $managedSetKey;
        }

        return [
            'managedSetKeys' => $managedSetKeys,
            'skippedManagedSets' => $skippedManagedSets,
        ];
    }

    /**
     * @param array<int, array{label: string, message: string}> $skippedManagedSets
     */
    public function skippedManagedSetsSummary(array $skippedManagedSets): string
    {
        $parts = array_map(
            static fn (array $entry): string => sprintf('%s (%s)', $entry['label'], $entry['message']),
            $skippedManagedSets
        );

        return sprintf(
            'Skipped %d managed domain(s) due to diff/export errors: %s.',
            count($skippedManagedSets),
            implode('; ', $parts)
        );
    }

    public function requireApplyService(string $managedSetKey): ManagedSetApplyServiceInterface
    {
        if (! isset($this->applyServicesByManagedSetKey[$managedSetKey])) {
            if (
                ! $this->managedSetRegistry instanceof ManagedSetRegistry
                || ! $this->repositoryStateReader instanceof RepositoryStateReader
                || ! $this->workingStateRepository instanceof WorkingStateRepository
                || ! $this->managedSetRegistry->has($managedSetKey)
            ) {
                throw new RuntimeException(sprintf('Managed set "%s" cannot be applied asynchronously.', $managedSetKey));
            }

            $adapter = $this->managedSetRegistry->get($managedSetKey);

            if ($adapter instanceof OverlayManagedContentAdapterInterface) {
                $this->applyServicesByManagedSetKey[$managedSetKey] = new OverlayManagedSetApplyService(
                    $adapter,
                    $this->repositoryStateReader,
                    $this->workingStateRepository
                );
            } elseif ($adapter instanceof ConfigManagedContentAdapterInterface) {
                $this->applyServicesByManagedSetKey[$managedSetKey] = new ConfigManagedSetApplyService(
                    $adapter,
                    $this->repositoryStateReader,
                    $this->workingStateRepository
                );
            } else {
                if (! $this->contentMapRepository instanceof ContentMapRepository) {
                    throw new RuntimeException(sprintf('Managed set "%s" cannot be applied asynchronously.', $managedSetKey));
                }

                $this->applyServicesByManagedSetKey[$managedSetKey] = new ManagedSetApplyService(
                    $adapter,
                    $this->repositoryStateReader,
                    $this->contentMapRepository,
                    $this->workingStateRepository
                );
            }
        }

        return $this->applyServicesByManagedSetKey[$managedSetKey];
    }
}
