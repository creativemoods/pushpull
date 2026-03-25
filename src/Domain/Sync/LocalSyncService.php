<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Domain\Apply\ApplyManagedSetResult;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\ManagedSetDiffResult;
use PushPull\Domain\Diff\ManagedSetDiffService;
use PushPull\Domain\Merge\ManagedSetMergeService;
use PushPull\Domain\Merge\MergeManagedSetResult;
use PushPull\Domain\Push\ManagedSetPushService;
use PushPull\Domain\Push\PushManagedSetResult;
use PushPull\Domain\Push\RemoteBranchResetService;
use PushPull\Domain\Push\ResetRemoteBranchResult;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Settings\SettingsRepository;
use RuntimeException;

final class LocalSyncService implements SyncServiceInterface
{
    public function __construct(
        private readonly GenerateBlocksGlobalStylesAdapter $generateBlocksAdapter,
        private readonly GenerateBlocksRepositoryCommitter $generateBlocksCommitter,
        private readonly ManagedSetDiffService $generateBlocksDiffService,
        private readonly ManagedSetMergeService $generateBlocksMergeService,
        private readonly ManagedSetApplyService $generateBlocksApplyService,
        private readonly ManagedSetPushService $generateBlocksPushService,
        private readonly RemoteBranchResetService $remoteBranchResetService,
        private readonly LocalRepositoryInterface $localRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly GitProviderFactoryInterface $providerFactory
    ) {
    }

    public function commitManagedSet(string $managedSetKey, CommitManagedSetRequest $request): CommitManagedSetResult
    {
        if ($managedSetKey !== $this->generateBlocksAdapter->getManagedSetKey()) {
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        return $this->generateBlocksCommitter->commitSnapshot(
            $this->generateBlocksAdapter->exportSnapshot(),
            $request
        );
    }

    public function fetch(string $managedSetKey): FetchManagedSetResult
    {
        if ($managedSetKey !== $this->generateBlocksAdapter->getManagedSetKey()) {
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        $settings = $this->settingsRepository->get();
        [$provider, $remoteConfig] = $this->resolveValidatedProvider($settings);

        return (new RemoteBranchFetcher($provider, $this->localRepository, $remoteConfig))
            ->fetchManagedSet($managedSetKey);
    }

    public function diff(string $managedSetKey): ManagedSetDiffResult
    {
        if ($managedSetKey !== $this->generateBlocksAdapter->getManagedSetKey()) {
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        return $this->generateBlocksDiffService->diff($this->settingsRepository->get());
    }

    public function merge(string $managedSetKey): MergeManagedSetResult
    {
        if ($managedSetKey !== $this->generateBlocksAdapter->getManagedSetKey()) {
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        $settings = $this->settingsRepository->get();

        return $this->generateBlocksMergeService->merge($managedSetKey, $settings->branch);
    }

    public function apply(string $managedSetKey): ApplyManagedSetResult
    {
        if ($managedSetKey !== $this->generateBlocksAdapter->getManagedSetKey()) {
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        return $this->generateBlocksApplyService->apply($this->settingsRepository->get());
    }

    public function push(string $managedSetKey): PushManagedSetResult
    {
        if ($managedSetKey !== $this->generateBlocksAdapter->getManagedSetKey()) {
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        return $this->generateBlocksPushService->push($managedSetKey, $this->settingsRepository->get());
    }

    public function resetRemote(string $managedSetKey): ResetRemoteBranchResult
    {
        if ($managedSetKey !== $this->generateBlocksAdapter->getManagedSetKey()) {
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        return $this->remoteBranchResetService->reset($managedSetKey, $this->settingsRepository->get());
    }

    /**
     * @return array{0: \PushPull\Provider\GitProviderInterface, 1: GitRemoteConfig}
     */
    private function resolveValidatedProvider(\PushPull\Settings\PushPullSettings $settings): array
    {
        $remoteConfig = GitRemoteConfig::fromSettings($settings);
        $provider = $this->providerFactory->make($remoteConfig->providerKey);
        $validation = $provider->validateConfig($remoteConfig);

        if (! $validation->isValid()) {
            throw new RuntimeException(implode(' ', $validation->messages));
        }

        return [$provider, $remoteConfig];
    }
}
