<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Apply\ManagedSetApplyServiceInterface;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Domain\Sync\SyncServiceInterface;
use PushPull\Persistence\Operations\OperationLogRepository;
use PushPull\Persistence\Operations\OperationRecord;
use PushPull\Provider\CreateRemoteCommitRequest;
use PushPull\Provider\Exception\ProviderException;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitLab\GitLabProvider;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteTree;
use PushPull\Provider\RemoteRef;
use PushPull\Provider\UpdateRemoteRefRequest;
use PushPull\Settings\SettingsRepository;
use RuntimeException;

if (! defined('ABSPATH')) {
    exit;
}

final class AsyncBranchOperationRunner
{
    private const CHUNK_NODE_LIMIT = 12;
    private const ASYNC_TYPE = 'branch_action';
    /** @var array<string, ManagedSetApplyServiceInterface> */
    private array $applyServicesByManagedSetKey;

    public function __construct(
        private readonly OperationLogRepository $operationLogRepository,
        private readonly OperationLockService $operationLockService,
        private readonly SettingsRepository $settingsRepository,
        private readonly LocalRepositoryInterface $localRepository,
        private readonly GitProviderFactoryInterface $providerFactory,
        private readonly SyncServiceInterface $syncService,
        array $managedSetApplyServices = []
    ) {
        $this->applyServicesByManagedSetKey = $managedSetApplyServices;
    }

    /**
     * @return array{operationId: int, progressMessage: string, done: bool, status?: string, redirectUrl?: string, progress: array<string, mixed>}
     */
    public function start(string $managedSetKey, string $operationType): array
    {
        if (! in_array($operationType, ['fetch', 'pull', 'push', 'apply'], true)) {
            throw new RuntimeException(sprintf('Async branch action "%s" is not supported.', $operationType));
        }

        $settings = $this->settingsRepository->get();
        $record = $this->operationLogRepository->start($managedSetKey, $operationType, [
            'branch' => $settings->branch,
            'async' => true,
            'asyncType' => self::ASYNC_TYPE,
        ]);
        $lock = null;

        try {
            $lock = $this->operationLockService->acquire($operationType, $managedSetKey, $record->id);
            $state = $this->initialState($record, $operationType, $settings->branch, $lock->token);

            if (($state['phase'] ?? '') === 'complete') {
                $this->operationLogRepository->markSucceeded($record->id, $state);
                $this->operationLockService->release($lock);

                return [
                    'operationId' => $record->id,
                    'progressMessage' => (string) $state['summaryMessage'],
                    'done' => true,
                    'status' => (string) $state['summaryType'],
                    'redirectUrl' => (string) $state['redirectUrl'],
                    'progress' => $this->progressPayload($state),
                ];
            }

            $this->operationLogRepository->updateRunning($record->id, $state);

            return [
                'operationId' => $record->id,
                'progressMessage' => (string) $state['progressMessage'],
                'done' => false,
                'progress' => $this->progressPayload($state),
            ];
        } catch (\Throwable $exception) {
            $this->operationLogRepository->markFailed($record->id, $this->normalizeFailure($exception));

            if ($lock !== null) {
                $this->operationLockService->release($lock);
            }

            throw $exception;
        }
    }

    /**
     * @return array{done: bool, status: string, message: string, progress: array<string, mixed>, redirectManagedSetKey?: string}
     */
    public function continue(int $operationId): array
    {
        $record = $this->operationLogRepository->find($operationId);

        if ($record === null) {
            throw new RuntimeException(sprintf('Operation log %d could not be found.', $operationId));
        }

        if ($record->status !== OperationLogRepository::STATUS_RUNNING) {
            $summaryType = (string) ($record->result['summaryType'] ?? ($record->status === OperationLogRepository::STATUS_SUCCEEDED ? 'success' : 'error'));
            $summaryMessage = (string) ($record->result['summaryMessage'] ?? 'Operation finished.');

            return [
                'done' => true,
                'status' => $summaryType,
                'message' => $summaryMessage,
                'progress' => $this->progressPayload($record->result),
                'redirectManagedSetKey' => is_string($record->result['redirectManagedSetKey'] ?? null) ? $record->result['redirectManagedSetKey'] : null,
            ];
        }

        $state = $record->result;
        $token = (string) ($state['lockToken'] ?? '');

        if ($token === '') {
            throw new RuntimeException(sprintf('Operation log %d is missing its lock token.', $record->id));
        }

        $lock = $this->operationLockService->restore($token);
        $this->operationLockService->refresh($lock);

        try {
            $response = $this->continueOperation($record, $state);

            if (! $response['done']) {
                $this->operationLogRepository->updateRunning($record->id, $response['state']);

                return [
                    'done' => false,
                    'status' => 'running',
                    'message' => (string) $response['state']['progressMessage'],
                    'progress' => $this->progressPayload($response['state']),
                ];
            }

            $this->operationLogRepository->markSucceeded($record->id, $response['finalResult']);
            $this->operationLockService->release($lock);

            return [
                'done' => true,
                'status' => (string) $response['finalResult']['summaryType'],
                'message' => (string) $response['finalResult']['summaryMessage'],
                'progress' => $this->progressPayload($response['finalResult']),
                'redirectManagedSetKey' => is_string($response['finalResult']['redirectManagedSetKey'] ?? null) ? $response['finalResult']['redirectManagedSetKey'] : null,
            ];
        } catch (\Throwable $exception) {
            $this->operationLogRepository->markFailed($record->id, $this->normalizeFailure($exception));
            $this->operationLockService->release($lock);
            throw $exception;
        }
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

        if ($operationType === 'push') {
            return $this->initialPushState($record, $baseState, $branch);
        }

        if ($operationType === 'apply') {
            return $this->initialApplyState($record, $baseState, $branch);
        }

        [$remoteConfig, $remoteRef] = $this->loadRemoteRef($branch);

        return $baseState + [
            'phase' => 'fetch',
            'remoteRefName' => 'refs/remotes/origin/' . $branch,
            'remoteCommitHash' => $remoteRef->commitHash,
            'providerBranchRefName' => 'refs/heads/' . $remoteConfig->branch,
            'pendingCommitHashes' => [$remoteRef->commitHash => true],
            'pendingTreeHashes' => [],
            'pendingBlobHashes' => [],
            'visitedCommitHashes' => [],
            'visitedTreeHashes' => [],
            'visitedBlobHashes' => [],
            'newCommitHashes' => [],
            'newTreeHashes' => [],
            'newBlobHashes' => [],
            'progressMode' => 'indeterminate',
            'progressCurrent' => 0,
            'progressTotal' => 0,
            'progressMessage' => sprintf('Queued fetch of remote commit %s for branch %s.', $remoteRef->commitHash, $branch),
        ];
    }

    /**
     * @param array<string, mixed> $baseState
     * @return array<string, mixed>
     */
    private function initialPushState(OperationRecord $record, array $baseState, string $branch): array
    {
        $settings = $this->settingsRepository->get();
        $localRef = $this->localRepository->getRef('refs/heads/' . $branch);
        $trackingRef = $this->localRepository->getRef('refs/remotes/origin/' . $branch);

        if ($localRef === null || $localRef->commitHash === '') {
            throw new RuntimeException(sprintf('Local branch %s does not have a commit to push.', $branch));
        }

        if ($trackingRef === null || $trackingRef->commitHash === '') {
            throw new RuntimeException(sprintf('Remote tracking branch %s has not been fetched yet.', $branch));
        }

        $relationship = $this->determineRelationship($localRef->commitHash, $trackingRef->commitHash);

        if ($relationship === 'in_sync') {
            $message = sprintf('Local branch %s is already up to date on the provider.', $branch);

            return $baseState + [
                'phase' => 'complete',
                'summaryType' => 'success',
                'summaryMessage' => $message,
                'redirectUrl' => $this->noticeUrl('success', $message),
                'progressMode' => 'determinate',
                'progressCurrent' => 1,
                'progressTotal' => 1,
            ];
        }

        if ($relationship !== 'ahead') {
            throw new RuntimeException(sprintf(
                'Local branch %s cannot be pushed because it is %s relative to the fetched remote state.',
                $branch,
                str_replace('_', ' ', $relationship)
            ));
        }

        [$provider, $remoteConfig] = $this->resolveProvider();
        $remoteRefName = 'refs/heads/' . $settings->branch;
        $currentRemoteRef = $provider->getRef($remoteConfig, $remoteRefName);

        if ($currentRemoteRef === null || $currentRemoteRef->commitHash === '') {
            throw new RuntimeException(sprintf('Remote branch %s does not exist or cannot be updated safely.', $branch));
        }

        if ($currentRemoteRef->commitHash !== $trackingRef->commitHash) {
            throw new RuntimeException(sprintf(
                'Remote branch %s has changed since the last fetch. Fetch again before pushing.',
                $branch
            ));
        }

        $commitOrder = [];
        $this->collectCommitPushOrder($localRef->commitHash, $trackingRef->commitHash, [], $commitOrder);
        $treeOrder = [];
        $blobHashes = [];
        $seenTrees = [];
        $treeMap = [];
        $blobMap = [];
        $processedCommits = [];
        $remoteBaseCommit = $this->localRepository->getCommit($trackingRef->commitHash);

        foreach ($commitOrder as $commitHash) {
            $commit = $this->localRepository->getCommit($commitHash);

            if ($commit === null) {
                throw new RuntimeException(sprintf('Local commit %s could not be found for push planning.', $commitHash));
            }

            if ($commit->secondParentHash === null && $commit->parentHash === $trackingRef->commitHash && $remoteBaseCommit !== null) {
                $this->collectTreePushPlanAgainstRemote(
                    $commit->treeHash,
                    $remoteBaseCommit->treeHash,
                    $seenTrees,
                    $treeOrder,
                    $blobHashes,
                    $treeMap,
                    $blobMap
                );
                $processedCommits[$commitHash] = true;
                continue;
            }

            if (
                $commit->secondParentHash === null
                && is_string($commit->parentHash)
                && $commit->parentHash !== ''
                && isset($processedCommits[$commit->parentHash])
            ) {
                $parentCommit = $this->localRepository->getCommit($commit->parentHash);

                if ($parentCommit !== null) {
                    $this->collectTreePushPlanAgainstLocal(
                        $commit->treeHash,
                        $parentCommit->treeHash,
                        $seenTrees,
                        $treeOrder,
                        $blobHashes
                    );
                    $processedCommits[$commitHash] = true;
                    continue;
                }
            }

            $this->collectTreePushPlan($commit->treeHash, $seenTrees, $treeOrder, $blobHashes);
            $processedCommits[$commitHash] = true;
        }

        $blobOrder = array_values(array_keys($blobHashes));
        $totalSteps = count($blobOrder) + count($treeOrder) + count($commitOrder) + 1;

        return $baseState + [
            'phase' => 'push_blobs',
            'stopAtRemoteHash' => $trackingRef->commitHash,
            'remoteRefName' => $remoteRefName,
            'localHeadHash' => $localRef->commitHash,
            'blobOrder' => $blobOrder,
            'treeOrder' => $treeOrder,
            'commitOrder' => $commitOrder,
            'blobMap' => $blobMap,
            'treeMap' => $treeMap,
            'commitMap' => [],
            'pushedBlobHashes' => [],
            'pushedTreeHashes' => [],
            'pushedCommitHashes' => [],
            'blobIndex' => 0,
            'treeIndex' => 0,
            'commitIndex' => 0,
            'progressMode' => 'determinate',
            'progressCurrent' => 0,
            'progressTotal' => max(1, $totalSteps),
            'progressMessage' => sprintf(
                'Prepared push plan for branch %s: %d blob(s), %d tree(s), and %d commit(s).',
                $branch,
                count($blobOrder),
                count($treeOrder),
                count($commitOrder)
            ),
        ];
    }

    /**
     * @param array<string, mixed> $baseState
     * @return array<string, mixed>
     */
    private function initialApplyState(OperationRecord $record, array $baseState, string $branch): array
    {
        $applyService = $this->requireApplyService($record->managedSetKey);
        $prepared = $applyService->prepareApply($this->settingsRepository->get());
        $orderedLogicalKeys = $prepared['orderedLogicalKeys'];

        return $baseState + [
            'phase' => 'apply_items',
            'sourceCommitHash' => $prepared['commitHash'],
            'orderedLogicalKeys' => $orderedLogicalKeys,
            'applyIndex' => 0,
            'createdCount' => 0,
            'updatedCount' => 0,
            'appliedWpObjectIds' => [],
            'desiredLogicalKeys' => [],
            'deletedLogicalKeys' => [],
            'progressMode' => 'determinate',
            'progressCurrent' => 0,
            'progressTotal' => max(1, count($orderedLogicalKeys) + 1),
            'progressMessage' => sprintf(
                'Prepared apply plan for %s: %d item(s) from local branch %s.',
                $record->managedSetKey,
                count($orderedLogicalKeys),
                $branch
            ),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{done: bool, state?: array<string, mixed>, finalResult?: array<string, mixed>}
     */
    private function continueOperation(OperationRecord $record, array $state): array
    {
        return match ((string) ($record->operationType ?? '')) {
            'fetch' => $this->continueFetchOrPull($record, $state, false),
            'pull' => $this->continueFetchOrPull($record, $state, true),
            'push' => $this->continuePush($record, $state),
            'apply' => $this->continueApply($record, $state),
            default => throw new RuntimeException(sprintf('Async branch action "%s" is not supported.', $record->operationType)),
        };
    }

    /**
     * @param array<string, mixed> $state
     * @return array{done: bool, state?: array<string, mixed>, finalResult?: array<string, mixed>}
     */
    private function continueFetchOrPull(OperationRecord $record, array $state, bool $withMerge): array
    {
        if (($state['phase'] ?? 'fetch') === 'merge') {
            $mergeResult = $this->syncService->merge($record->managedSetKey);

            return [
                'done' => true,
                'finalResult' => $this->finalPullResult($record, $state, $mergeResult),
            ];
        }

        [$provider, $remoteConfig] = $this->resolveProvider();
        $state = $this->processFetchChunk($state, $provider, $remoteConfig);

        if (! $this->isFetchComplete($state)) {
            return [
                'done' => false,
                'state' => $state,
            ];
        }

        $trackingRefName = 'refs/remotes/origin/' . $remoteConfig->branch;
        $this->localRepository->updateRef($trackingRefName, (string) $state['remoteCommitHash']);
        $state['phase'] = $withMerge ? 'merge' : 'complete';
        $state['progressMessage'] = $withMerge
            ? sprintf('Fetched remote commit %s into %s. Preparing merge.', $state['remoteCommitHash'], $trackingRefName)
            : sprintf('Fetched remote commit %s into %s.', $state['remoteCommitHash'], $trackingRefName);

        if ($withMerge) {
            return [
                'done' => false,
                'state' => $state,
            ];
        }

        return [
            'done' => true,
            'finalResult' => $this->finalFetchResult($record, $state),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{done: bool, state?: array<string, mixed>, finalResult?: array<string, mixed>}
     */
    private function continuePush(OperationRecord $record, array $state): array
    {
        [$provider, $remoteConfig] = $this->resolveProvider();
        $this->rehydrateGitLabPushState($provider, $remoteConfig, $state);
        $budget = self::CHUNK_NODE_LIMIT;

        while ($budget > 0) {
            if ((int) $state['blobIndex'] < count($state['blobOrder'])) {
                $localBlobHash = (string) $state['blobOrder'][(int) $state['blobIndex']];
                $blob = $this->localRepository->getBlob($localBlobHash);

                if ($blob === null) {
                    throw new RuntimeException(sprintf('Local blob %s could not be found for push.', $localBlobHash));
                }

                $remoteBlobHash = $provider->createBlob($remoteConfig, $blob->content);
                $state['blobMap'][$localBlobHash] = $remoteBlobHash;
                $state['pushedBlobHashes'][] = $remoteBlobHash;
                $this->localRepository->importRemoteBlob(new RemoteBlob($remoteBlobHash, $blob->content));
                $state['blobIndex']++;
                $state['progressCurrent']++;
                $state['progressMessage'] = $this->pushProgressMessage($state, sprintf('Uploaded blob %s.', $localBlobHash));
                $budget--;
                continue;
            }

            if ((int) $state['treeIndex'] < count($state['treeOrder'])) {
                $localTreeHash = (string) $state['treeOrder'][(int) $state['treeIndex']];
                $tree = $this->localRepository->getTree($localTreeHash);

                if ($tree === null) {
                    throw new RuntimeException(sprintf('Local tree %s could not be found for push.', $localTreeHash));
                }

                $entries = [];

                foreach ($tree->entries as $entry) {
                    $remoteHash = $entry->type === 'blob'
                        ? (string) ($state['blobMap'][$entry->hash] ?? '')
                        : (string) ($state['treeMap'][$entry->hash] ?? '');

                    if ($remoteHash === '') {
                        throw new RuntimeException(sprintf('Dependent object %s was not uploaded before tree %s.', $entry->hash, $localTreeHash));
                    }

                    $entries[] = [
                        'path' => $entry->path,
                        'type' => $entry->type,
                        'hash' => $remoteHash,
                    ];
                }

                $remoteTreeHash = $provider->createTree($remoteConfig, $entries);
                $state['treeMap'][$localTreeHash] = $remoteTreeHash;
                $state['pushedTreeHashes'][] = $remoteTreeHash;
                $this->localRepository->importRemoteTree(new RemoteTree($remoteTreeHash, $entries));
                $state['treeIndex']++;
                $state['progressCurrent']++;
                $state['progressMessage'] = $this->pushProgressMessage($state, sprintf('Uploaded tree %s.', $localTreeHash));
                $budget--;
                continue;
            }

            if ((int) $state['commitIndex'] < count($state['commitOrder'])) {
                $localCommitHash = (string) $state['commitOrder'][(int) $state['commitIndex']];
                $localCommit = $this->localRepository->getCommit($localCommitHash);

                if ($localCommit === null) {
                    throw new RuntimeException(sprintf('Local commit %s could not be found for push.', $localCommitHash));
                }

                $remoteParentHashes = [];

                foreach ([$localCommit->parentHash, $localCommit->secondParentHash] as $parentHash) {
                    if ($parentHash === null || $parentHash === '') {
                        continue;
                    }

                    if ($parentHash === $state['stopAtRemoteHash']) {
                        $remoteParentHashes[] = $parentHash;
                        continue;
                    }

                    $remoteParentHash = (string) ($state['commitMap'][$parentHash] ?? '');

                    if ($remoteParentHash === '') {
                        throw new RuntimeException(sprintf('Parent commit for %s was not uploaded before child commit.', $localCommitHash));
                    }

                    $remoteParentHashes[] = $remoteParentHash;
                }

                $remoteTreeHash = (string) ($state['treeMap'][$localCommit->treeHash] ?? '');

                if ($remoteTreeHash === '') {
                    throw new RuntimeException(sprintf('Tree %s was not uploaded before commit %s.', $localCommit->treeHash, $localCommitHash));
                }

                $remoteCommitHash = $provider->createCommit($remoteConfig, new CreateRemoteCommitRequest(
                    $remoteTreeHash,
                    $remoteParentHashes,
                    $localCommit->message,
                    $localCommit->authorName !== '' ? $localCommit->authorName : 'PushPull',
                    $localCommit->authorEmail
                ));
                $state['commitMap'][$localCommitHash] = $remoteCommitHash;
                $state['pushedCommitHashes'][] = $remoteCommitHash;
                $this->localRepository->importRemoteCommit(new RemoteCommit(
                    $remoteCommitHash,
                    $remoteTreeHash,
                    $remoteParentHashes,
                    $localCommit->message
                ));
                $state['commitIndex']++;
                $state['progressCurrent']++;
                $state['progressMessage'] = $this->pushProgressMessage($state, sprintf('Uploaded commit %s.', $localCommitHash));
                $budget--;
                continue;
            }

            $remoteHeadHash = (string) ($state['commitMap'][$state['localHeadHash']] ?? '');

            if ($remoteHeadHash === '') {
                throw new RuntimeException('Remote head hash could not be resolved for branch push.');
            }

            $update = $provider->updateRef($remoteConfig, new UpdateRemoteRefRequest(
                (string) $state['remoteRefName'],
                $remoteHeadHash,
                (string) $state['stopAtRemoteHash']
            ));

            if (! $update->success) {
                throw new RuntimeException(sprintf('Remote branch %s could not be updated.', $state['branch']));
            }

            $finalRemoteHeadHash = $update->commitHash !== '' ? $update->commitHash : $remoteHeadHash;
            $this->aliasRemoteCommitHash($remoteHeadHash, $finalRemoteHeadHash);
            $this->localRepository->updateRef('refs/heads/' . $state['branch'], $finalRemoteHeadHash);
            $this->localRepository->updateRef('refs/remotes/origin/' . $state['branch'], $finalRemoteHeadHash);
            $this->localRepository->updateRef('HEAD', $finalRemoteHeadHash);
            $state['progressCurrent']++;

            return [
                'done' => true,
                'finalResult' => [
                    'summaryType' => 'success',
                    'summaryMessage' => sprintf(
                        'Pushed local branch %s to remote commit %s. Uploaded %d commit(s), %d tree(s), and %d blob(s).',
                        $state['branch'],
                        $finalRemoteHeadHash,
                        count($state['pushedCommitHashes']),
                        count($state['pushedTreeHashes']),
                        count($state['pushedBlobHashes'])
                    ),
                    'operationType' => 'push',
                    'managedSetKey' => $record->managedSetKey,
                    'branch' => $state['branch'],
                    'status' => 'pushed',
                    'remoteCommitHash' => $finalRemoteHeadHash,
                    'pushedCommitHashes' => array_values(array_unique($state['pushedCommitHashes'])),
                    'pushedTreeHashes' => array_values(array_unique($state['pushedTreeHashes'])),
                    'pushedBlobHashes' => array_values(array_unique($state['pushedBlobHashes'])),
                    'progressMode' => 'determinate',
                    'progressCurrent' => (int) $state['progressCurrent'],
                    'progressTotal' => (int) $state['progressTotal'],
                ],
            ];
        }

        return [
            'done' => false,
            'state' => $state,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{done: bool, state?: array<string, mixed>, finalResult?: array<string, mixed>}
     */
    private function continueApply(OperationRecord $record, array $state): array
    {
        $applyService = $this->requireApplyService($record->managedSetKey);
        $settings = $this->settingsRepository->get();
        $budget = self::CHUNK_NODE_LIMIT;

        while ($budget > 0) {
            if ((int) $state['applyIndex'] < count($state['orderedLogicalKeys'])) {
                $logicalKey = (string) $state['orderedLogicalKeys'][(int) $state['applyIndex']];
                $menuOrder = (int) $state['applyIndex'];
                $result = $applyService->applyLogicalKey($settings, $logicalKey, $menuOrder);

                if ($result['created']) {
                    $state['createdCount']++;
                } else {
                    $state['updatedCount']++;
                }

                $state['appliedWpObjectIds'][] = $result['postId'];
                $state['desiredLogicalKeys'][$logicalKey] = true;
                $state['applyIndex']++;
                $state['progressCurrent']++;
                $state['progressMessage'] = sprintf(
                    'Applied %s to WordPress. Processed %d of %d item(s).',
                    $logicalKey,
                    $state['applyIndex'],
                    count($state['orderedLogicalKeys'])
                );
                $budget--;
                continue;
            }

            $state['deletedLogicalKeys'] = $applyService->deleteMissingLogicalKeys($state['desiredLogicalKeys']);
            $state['progressCurrent'] = (int) $state['progressTotal'];
            $state['progressMessage'] = sprintf(
                'Applied %d item(s) from local branch %s to WordPress.',
                count($state['orderedLogicalKeys']),
                $state['branch']
            );

            return [
                'done' => true,
                'finalResult' => [
                    'summaryType' => 'success',
                    'summaryMessage' => sprintf(
                        'Applied repository commit %s to WordPress. Created %d item(s), updated %d item(s), and deleted %d missing item(s).',
                        $state['sourceCommitHash'],
                        $state['createdCount'],
                        $state['updatedCount'],
                        count($state['deletedLogicalKeys'])
                    ),
                    'progressMode' => 'determinate',
                    'progressCurrent' => (int) $state['progressTotal'],
                    'progressTotal' => (int) $state['progressTotal'],
                    'createdCount' => $state['createdCount'],
                    'updatedCount' => $state['updatedCount'],
                    'appliedWpObjectIds' => $state['appliedWpObjectIds'],
                    'deletedLogicalKeys' => $state['deletedLogicalKeys'],
                    'redirectManagedSetKey' => $record->managedSetKey,
                ],
            ];
        }

        return [
            'done' => false,
            'state' => $state,
        ];
    }

    /**
     * Rebuild GitLab's synthetic staged objects when async push continues in a new request.
     *
     * @param array<string, mixed> $state
     */
    private function rehydrateGitLabPushState(GitProviderInterface $provider, GitRemoteConfig $remoteConfig, array $state): void
    {
        if (! $provider instanceof GitLabProvider) {
            return;
        }

        foreach (($state['blobMap'] ?? []) as $localBlobHash => $remoteBlobHash) {
            if (! is_string($localBlobHash) || ! is_string($remoteBlobHash) || $localBlobHash === '' || $remoteBlobHash === '') {
                continue;
            }

            $blob = $this->localRepository->getBlob($localBlobHash);

            if ($blob === null) {
                throw new RuntimeException(sprintf('Local blob %s could not be found while restoring GitLab push state.', $localBlobHash));
            }

            $provider->createBlob($remoteConfig, $blob->content);
        }

        foreach (($state['treeMap'] ?? []) as $localTreeHash => $remoteTreeHash) {
            if (! is_string($localTreeHash) || ! is_string($remoteTreeHash) || $localTreeHash === '' || $remoteTreeHash === '') {
                continue;
            }

            $tree = $this->localRepository->getTree($localTreeHash);

            if ($tree === null) {
                throw new RuntimeException(sprintf('Local tree %s could not be found while restoring GitLab push state.', $localTreeHash));
            }

            $entries = [];

            foreach ($tree->entries as $entry) {
                $remoteHash = $entry->type === 'blob'
                    ? (string) (($state['blobMap'][$entry->hash] ?? '') ?: $entry->hash)
                    : (string) (($state['treeMap'][$entry->hash] ?? '') ?: $entry->hash);

                $entries[] = [
                    'path' => $entry->path,
                    'type' => $entry->type,
                    'hash' => $remoteHash,
                ];
            }

            $provider->createTree($remoteConfig, $entries);
        }

        foreach (($state['commitMap'] ?? []) as $localCommitHash => $remoteCommitHash) {
            if (! is_string($localCommitHash) || ! is_string($remoteCommitHash) || $localCommitHash === '' || $remoteCommitHash === '') {
                continue;
            }

            $localCommit = $this->localRepository->getCommit($localCommitHash);

            if ($localCommit === null) {
                throw new RuntimeException(sprintf('Local commit %s could not be found while restoring GitLab push state.', $localCommitHash));
            }

            $remoteParentHashes = [];

            foreach ([$localCommit->parentHash, $localCommit->secondParentHash] as $parentHash) {
                if ($parentHash === null || $parentHash === '') {
                    continue;
                }

                if ($parentHash === ($state['stopAtRemoteHash'] ?? null)) {
                    $remoteParentHashes[] = $parentHash;
                    continue;
                }

                $mappedParentHash = (string) (($state['commitMap'][$parentHash] ?? '') ?: $parentHash);
                $remoteParentHashes[] = $mappedParentHash;
            }

            $remoteTreeHash = (string) (($state['treeMap'][$localCommit->treeHash] ?? '') ?: $localCommit->treeHash);

            $provider->createCommit($remoteConfig, new CreateRemoteCommitRequest(
                $remoteTreeHash,
                $remoteParentHashes,
                $localCommit->message,
                $localCommit->authorName !== '' ? $localCommit->authorName : 'PushPull',
                $localCommit->authorEmail
            ));
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function processFetchChunk(array $state, GitProviderInterface $provider, GitRemoteConfig $remoteConfig): array
    {
        $budget = self::CHUNK_NODE_LIMIT;

        while ($budget > 0) {
            $commitHash = $this->popPendingHash($state['pendingCommitHashes']);

            if ($commitHash !== null) {
                if (isset($state['visitedCommitHashes'][$commitHash])) {
                    $budget--;
                    continue;
                }

                $remoteCommit = $provider->getCommit($remoteConfig, $commitHash);

                if ($remoteCommit === null) {
                    throw new RuntimeException(sprintf('Remote commit "%s" could not be loaded.', $commitHash));
                }

                foreach ($remoteCommit->parents as $parentHash) {
                    if (! isset($state['visitedCommitHashes'][$parentHash])) {
                        $state['pendingCommitHashes'][$parentHash] = true;
                    }
                }

                $state['pendingTreeHashes'][$remoteCommit->treeHash] = true;
                if ($this->localRepository->getCommit($commitHash) === null) {
                    $state['newCommitHashes'][$commitHash] = true;
                }

                $this->localRepository->importRemoteCommit($remoteCommit);
                $state['visitedCommitHashes'][$commitHash] = true;
                $state['progressMessage'] = $this->fetchProgressMessage($state, sprintf('Imported commit %s.', $commitHash));
                $budget--;
                continue;
            }

            $treeHash = $this->popPendingHash($state['pendingTreeHashes']);

            if ($treeHash !== null) {
                if (isset($state['visitedTreeHashes'][$treeHash])) {
                    $budget--;
                    continue;
                }

                $remoteTree = $provider->getTree($remoteConfig, $treeHash);

                if ($remoteTree === null) {
                    throw new RuntimeException(sprintf('Remote tree "%s" could not be loaded.', $treeHash));
                }

                foreach ($remoteTree->entries as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    $entryHash = (string) ($entry['hash'] ?? '');
                    $entryType = (string) ($entry['type'] ?? 'blob');

                    if ($entryHash === '') {
                        continue;
                    }

                    if ($entryType === 'tree') {
                        $state['pendingTreeHashes'][$entryHash] = true;
                        continue;
                    }

                    $state['pendingBlobHashes'][$entryHash] = true;
                }

                if ($this->localRepository->getTree($treeHash) === null) {
                    $state['newTreeHashes'][$treeHash] = true;
                }

                $this->localRepository->importRemoteTree($remoteTree);
                $state['visitedTreeHashes'][$treeHash] = true;
                $state['progressMessage'] = $this->fetchProgressMessage($state, sprintf('Imported tree %s.', $treeHash));
                $budget--;
                continue;
            }

            $blobHash = $this->popPendingHash($state['pendingBlobHashes']);

            if ($blobHash !== null) {
                if (isset($state['visitedBlobHashes'][$blobHash])) {
                    $budget--;
                    continue;
                }

                $remoteBlob = $provider->getBlob($remoteConfig, $blobHash);

                if ($remoteBlob === null) {
                    throw new RuntimeException(sprintf('Remote blob "%s" could not be loaded.', $blobHash));
                }

                if ($this->localRepository->getBlob($blobHash) === null) {
                    $state['newBlobHashes'][$blobHash] = true;
                }

                $this->localRepository->importRemoteBlob($remoteBlob);
                $state['visitedBlobHashes'][$blobHash] = true;
                $state['progressMessage'] = $this->fetchProgressMessage($state, sprintf('Imported blob %s.', $blobHash));
                $budget--;
                continue;
            }

            break;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function finalFetchResult(OperationRecord $record, array $state): array
    {
        return [
            'summaryType' => 'success',
            'summaryMessage' => sprintf(
                'Fetched remote commit %s into %s. Newly imported %d commit(s), %d tree(s), and %d blob(s); traversed %d commit(s), %d tree(s), and %d blob(s).',
                $state['remoteCommitHash'],
                $state['remoteRefName'],
                count($state['newCommitHashes']),
                count($state['newTreeHashes']),
                count($state['newBlobHashes']),
                count($state['visitedCommitHashes']),
                count($state['visitedTreeHashes']),
                count($state['visitedBlobHashes'])
            ),
            'operationType' => $record->operationType,
            'managedSetKey' => $record->managedSetKey,
            'branch' => $state['branch'],
            'remoteCommitHash' => $state['remoteCommitHash'],
            'remoteRefName' => $state['remoteRefName'],
            'newCommitHashes' => array_keys($state['newCommitHashes']),
            'newTreeHashes' => array_keys($state['newTreeHashes']),
            'newBlobHashes' => array_keys($state['newBlobHashes']),
            'traversedCommitHashes' => array_keys($state['visitedCommitHashes']),
            'traversedTreeHashes' => array_keys($state['visitedTreeHashes']),
            'traversedBlobHashes' => array_keys($state['visitedBlobHashes']),
            'progressMode' => 'indeterminate',
            'progressCurrent' => 0,
            'progressTotal' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function finalPullResult(OperationRecord $record, array $state, \PushPull\Domain\Merge\MergeManagedSetResult $mergeResult): array
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
            'newCommitHashes' => array_keys($state['newCommitHashes']),
            'newTreeHashes' => array_keys($state['newTreeHashes']),
            'newBlobHashes' => array_keys($state['newBlobHashes']),
            'traversedCommitHashes' => array_keys($state['visitedCommitHashes']),
            'traversedTreeHashes' => array_keys($state['visitedTreeHashes']),
            'traversedBlobHashes' => array_keys($state['visitedBlobHashes']),
            'progressMode' => 'indeterminate',
            'progressCurrent' => 0,
            'progressTotal' => 0,
        ];
    }

    /**
     * @return array{0: GitRemoteConfig, 1: RemoteRef}
     */
    private function loadRemoteRef(string $branch): array
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
    private function resolveProvider(): array
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

    /**
     * @param array<string, mixed> $pending
     */
    private function popPendingHash(array &$pending): ?string
    {
        $next = array_key_first($pending);

        if ($next === null) {
            return null;
        }

        unset($pending[$next]);

        return (string) $next;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isFetchComplete(array $state): bool
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
        return sprintf(
            '%s Traversed %d commit(s), %d tree(s), and %d blob(s) so far.',
            $prefix,
            count($state['visitedCommitHashes']),
            count($state['visitedTreeHashes']),
            count($state['visitedBlobHashes'])
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function progressPayload(array $state): array
    {
        return [
            'mode' => (string) ($state['progressMode'] ?? 'indeterminate'),
            'current' => (int) ($state['progressCurrent'] ?? 0),
            'total' => (int) ($state['progressTotal'] ?? 0),
        ];
    }

    private function aliasRemoteCommitHash(string $stagedRemoteHash, string $finalRemoteHash): void
    {
        if ($stagedRemoteHash === $finalRemoteHash) {
            return;
        }

        $stagedCommit = $this->localRepository->getCommit($stagedRemoteHash);

        if ($stagedCommit === null) {
            return;
        }

        $this->localRepository->importRemoteCommit(new RemoteCommit(
            $finalRemoteHash,
            $stagedCommit->treeHash,
            array_values(array_filter([$stagedCommit->parentHash, $stagedCommit->secondParentHash])),
            $stagedCommit->message
        ));
    }

    private function noticeUrl(string $status, string $message): string
    {
        return add_query_arg([
            'page' => \PushPull\Admin\ManagedContentPage::MENU_SLUG,
            'pushpull_commit_status' => $status,
            'pushpull_commit_message' => $message,
        ], admin_url('admin.php'));
    }

    /**
     * @param array<string, true> $seen
     * @param string[] $order
     * @return array<string, true>
     */
    private function collectCommitPushOrder(string $commitHash, string $stopAtRemoteHash, array $seen, array &$order): array
    {
        if ($commitHash === $stopAtRemoteHash || isset($seen[$commitHash])) {
            return $seen;
        }

        $seen[$commitHash] = true;
        $commit = $this->localRepository->getCommit($commitHash);

        if ($commit === null) {
            throw new RuntimeException(sprintf('Local commit %s could not be found for push planning.', $commitHash));
        }

        foreach ([$commit->parentHash, $commit->secondParentHash] as $parentHash) {
            if (is_string($parentHash) && $parentHash !== '') {
                $seen = $this->collectCommitPushOrder($parentHash, $stopAtRemoteHash, $seen, $order);
            }
        }

        $order[] = $commitHash;

        return $seen;
    }

    /**
     * @param array<string, true> $seenTrees
     * @param string[] $treeOrder
     * @param array<string, true> $blobHashes
     */
    private function collectTreePushPlan(string $treeHash, array &$seenTrees, array &$treeOrder, array &$blobHashes): void
    {
        if (isset($seenTrees[$treeHash])) {
            return;
        }

        $seenTrees[$treeHash] = true;
        $tree = $this->localRepository->getTree($treeHash);

        if ($tree === null) {
            throw new RuntimeException(sprintf('Local tree %s could not be found for push planning.', $treeHash));
        }

        foreach ($tree->entries as $entry) {
            if ($entry->type === 'tree') {
                $this->collectTreePushPlan($entry->hash, $seenTrees, $treeOrder, $blobHashes);
                continue;
            }

            $blobHashes[$entry->hash] = true;
        }

        $treeOrder[] = $treeHash;
    }

    /**
     * @param array<string, true> $seenTrees
     * @param string[] $treeOrder
     * @param array<string, true> $blobHashes
     * @param array<string, string> $treeMap
     * @param array<string, string> $blobMap
     */
    private function collectTreePushPlanAgainstRemote(
        string $localTreeHash,
        string $remoteTreeHash,
        array &$seenTrees,
        array &$treeOrder,
        array &$blobHashes,
        array &$treeMap,
        array &$blobMap
    ): void {
        if (isset($treeMap[$localTreeHash]) || isset($seenTrees[$localTreeHash])) {
            return;
        }

        $localTree = $this->localRepository->getTree($localTreeHash);
        $remoteTree = $this->localRepository->getTree($remoteTreeHash);

        if ($localTree === null || $remoteTree === null) {
            $this->collectTreePushPlan($localTreeHash, $seenTrees, $treeOrder, $blobHashes);

            return;
        }

        $remoteEntriesByPath = [];

        foreach ($remoteTree->entries as $entry) {
            $remoteEntriesByPath[$entry->path] = $entry;
        }

        $canReuseTree = count($localTree->entries) === count($remoteTree->entries);

        foreach ($localTree->entries as $entry) {
            $remoteEntry = $remoteEntriesByPath[$entry->path] ?? null;

            if ($remoteEntry === null || $remoteEntry->type !== $entry->type) {
                $canReuseTree = false;

                if ($entry->type === 'tree') {
                    $this->collectTreePushPlan($entry->hash, $seenTrees, $treeOrder, $blobHashes);
                } else {
                    $blobHashes[$entry->hash] = true;
                }

                continue;
            }

            if ($entry->type === 'tree') {
                $beforeMapped = isset($treeMap[$entry->hash]);
                $this->collectTreePushPlanAgainstRemote($entry->hash, $remoteEntry->hash, $seenTrees, $treeOrder, $blobHashes, $treeMap, $blobMap);

                if (! $beforeMapped && ! isset($treeMap[$entry->hash])) {
                    $canReuseTree = false;
                }

                continue;
            }

            $localBlob = $this->localRepository->getBlob($entry->hash);
            $remoteBlob = $this->localRepository->getBlob($remoteEntry->hash);

            if ($localBlob !== null && $remoteBlob !== null && $localBlob->content === $remoteBlob->content) {
                $blobMap[$entry->hash] = $remoteEntry->hash;
                continue;
            }

            $canReuseTree = false;
            $blobHashes[$entry->hash] = true;
        }

        if ($canReuseTree) {
            $treeMap[$localTreeHash] = $remoteTreeHash;

            return;
        }

        $this->collectTreePushPlan($localTreeHash, $seenTrees, $treeOrder, $blobHashes);
    }

    /**
     * @param array<string, true> $seenTrees
     * @param string[] $treeOrder
     * @param array<string, true> $blobHashes
     */
    private function collectTreePushPlanAgainstLocal(
        string $localTreeHash,
        string $baselineLocalTreeHash,
        array &$seenTrees,
        array &$treeOrder,
        array &$blobHashes
    ): void {
        if (isset($seenTrees[$localTreeHash])) {
            return;
        }

        $localTree = $this->localRepository->getTree($localTreeHash);
        $baselineTree = $this->localRepository->getTree($baselineLocalTreeHash);

        if ($localTree === null || $baselineTree === null) {
            $this->collectTreePushPlan($localTreeHash, $seenTrees, $treeOrder, $blobHashes);

            return;
        }

        $baselineEntriesByPath = [];

        foreach ($baselineTree->entries as $entry) {
            $baselineEntriesByPath[$entry->path] = $entry;
        }

        $treeChanged = count($localTree->entries) !== count($baselineTree->entries);

        foreach ($localTree->entries as $entry) {
            $baselineEntry = $baselineEntriesByPath[$entry->path] ?? null;

            if ($baselineEntry !== null && $baselineEntry->type === $entry->type && $baselineEntry->hash === $entry->hash) {
                continue;
            }

            $treeChanged = true;

            if ($entry->type === 'tree' && $baselineEntry !== null && $baselineEntry->type === 'tree') {
                $this->collectTreePushPlanAgainstLocal($entry->hash, $baselineEntry->hash, $seenTrees, $treeOrder, $blobHashes);
                continue;
            }

            if ($entry->type === 'tree') {
                $this->collectTreePushPlan($entry->hash, $seenTrees, $treeOrder, $blobHashes);
                continue;
            }

            $blobHashes[$entry->hash] = true;
        }

        if ($treeChanged) {
            $seenTrees[$localTreeHash] = true;
            $treeOrder[] = $localTreeHash;
        }
    }

    private function determineRelationship(string $localCommitHash, string $remoteCommitHash): string
    {
        if ($localCommitHash === $remoteCommitHash) {
            return 'in_sync';
        }

        $localAncestors = $this->ancestorSet($localCommitHash);
        $remoteAncestors = $this->ancestorSet($remoteCommitHash);

        if (isset($localAncestors[$remoteCommitHash])) {
            return 'ahead';
        }

        if (isset($remoteAncestors[$localCommitHash])) {
            return 'behind';
        }

        foreach ($localAncestors as $hash => $_value) {
            if (isset($remoteAncestors[$hash])) {
                return 'diverged';
            }
        }

        return 'unrelated';
    }

    /**
     * @return array<string, true>
     */
    private function ancestorSet(string $startingHash): array
    {
        $seen = [];
        $queue = [$startingHash];

        while ($queue !== []) {
            $hash = array_shift($queue);

            if (! is_string($hash) || $hash === '' || isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $commit = $this->localRepository->getCommit($hash);

            if ($commit === null) {
                continue;
            }

            foreach ([$commit->parentHash, $commit->secondParentHash] as $parentHash) {
                if (is_string($parentHash) && $parentHash !== '') {
                    $queue[] = $parentHash;
                }
            }
        }

        return $seen;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function pushProgressMessage(array $state, string $prefix): string
    {
        return sprintf(
            '%s Uploaded %d of %d objects.',
            $prefix,
            (int) ($state['progressCurrent'] ?? 0),
            (int) ($state['progressTotal'] ?? 0)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFailure(\Throwable $exception): array
    {
        $message = $exception instanceof ProviderException ? $exception->debugSummary() : $exception->getMessage();

        return [
            'summaryType' => 'error',
            'summaryMessage' => $message,
            'exception' => $exception::class,
            'progressMode' => 'indeterminate',
            'progressCurrent' => 0,
            'progressTotal' => 0,
        ];
    }

    private function requireApplyService(string $managedSetKey): ManagedSetApplyService
    {
        if (! isset($this->applyServicesByManagedSetKey[$managedSetKey])) {
            throw new RuntimeException(sprintf('Managed set "%s" cannot be applied asynchronously.', $managedSetKey));
        }

        return $this->applyServicesByManagedSetKey[$managedSetKey];
    }
}
