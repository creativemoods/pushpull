<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

use PushPull\Domain\Apply\ManagedSetApplyServiceInterface;
use PushPull\Domain\Push\ManagedSetPushService;
use PushPull\Domain\Sync\CommitBranchResult;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\SyncServiceInterface;
use PushPull\Persistence\Operations\OperationRecord;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\RemoteRef;
use PushPull\Settings\PushPullSettings;

interface BranchAsyncOperationContextInterface
{
    public function settings(): PushPullSettings;

    public function syncService(): SyncServiceInterface;

    public function pushService(): ManagedSetPushService;

    /**
     * @return array{0: GitProviderInterface, 1: GitRemoteConfig}
     */
    public function resolveProvider(): array;

    /**
     * @return array{0: GitRemoteConfig, 1: RemoteRef}
     */
    public function loadRemoteRef(string $branch): array;

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function processFetchChunk(array $state, GitProviderInterface $provider, GitRemoteConfig $remoteConfig): array;

    /**
     * @param array<string, mixed> $state
     */
    public function isFetchComplete(array $state): bool;

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function finalFetchResult(OperationRecord $record, array $state): array;

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function finalPullResult(OperationRecord $record, array $state, \PushPull\Domain\Merge\MergeManagedSetResult $mergeResult): array;

    public function updateTrackingRef(string $trackingRefName, string $remoteCommitHash): void;

    /**
     * @return array<string, mixed>
     */
    public function initialFetchState(GitRemoteConfig $remoteConfig, string $remoteCommitHash): array;

    public function requireApplyService(string $managedSetKey): ManagedSetApplyServiceInterface;

    public function chunkNodeLimit(): int;

    public function noticeUrl(string $status, string $message, ?string $pageSlug = null): string;

    /**
     * @param string[] $managedSetKeys
     */
    public function commitManagedSets(array $managedSetKeys, CommitManagedSetRequest $request): CommitBranchResult;

    /**
     * @return array{managedSetKeys: string[], skippedManagedSets: array<int, array{label: string, message: string}>}
     */
    public function buildCommitPushAllPlan(): array;

    /**
     * @return string[]
     */
    public function enabledAvailableManagedSetKeys(): array;

    /**
     * @param array<int, array{label: string, message: string}> $skippedManagedSets
     */
    public function skippedManagedSetsSummary(array $skippedManagedSets): string;
}
