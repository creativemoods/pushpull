<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\ManagedSetRegistry;
use PushPull\Content\ManifestManagedContentAdapterInterface;
use PushPull\Content\ConfigManagedContentAdapterInterface;
use PushPull\Content\OverlayManagedContentAdapterInterface;
use PushPull\Domain\Apply\ApplyManagedSetResult;
use PushPull\Domain\Apply\ConfigManagedSetApplyService;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Apply\ManagedSetApplyServiceInterface;
use PushPull\Domain\Apply\OverlayManagedSetApplyService;
use PushPull\Domain\Diff\ManagedSetDiffResult;
use PushPull\Domain\Diff\ManagedSetDiffService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Merge\ManagedSetMergeService;
use PushPull\Domain\Merge\MergeManagedSetResult;
use PushPull\Domain\Push\ManagedSetPushService;
use PushPull\Domain\Push\PushManagedSetResult;
use PushPull\Domain\Push\RemoteBranchResetService;
use PushPull\Domain\Push\ResetRemoteBranchResult;
use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Provider\Exception\ProviderException;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\SettingsRepository;
use RuntimeException;

final class LocalSyncService implements SyncServiceInterface
{
    /** @var array<string, ManagedSetRepositoryCommitter> */
    private array $committersByManagedSetKey;
    /** @var array<string, ManagedSetDiffService> */
    private array $diffServicesByManagedSetKey;
    /** @var array<string, ManagedSetApplyServiceInterface> */
    private array $applyServicesByManagedSetKey;

    public function __construct(
        private readonly ManagedSetRegistry $managedSetRegistry,
        array $managedSetCommitters,
        array $managedSetDiffServices,
        array $managedSetApplyServices,
        private readonly ManagedSetMergeService $generateBlocksMergeService,
        private readonly ManagedSetPushService $generateBlocksPushService,
        private readonly RemoteBranchResetService $remoteBranchResetService,
        private readonly LocalRepositoryInterface $localRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly GitProviderFactoryInterface $providerFactory,
        private readonly ?RepositoryStateReader $repositoryStateReader = null,
        private readonly ?ContentMapRepository $contentMapRepository = null,
        private readonly ?WorkingStateRepository $workingStateRepository = null
    ) {
        $this->committersByManagedSetKey = $managedSetCommitters;
        $this->diffServicesByManagedSetKey = $managedSetDiffServices;
        $this->applyServicesByManagedSetKey = $managedSetApplyServices;
    }

    public function commitManagedSet(string $managedSetKey, CommitManagedSetRequest $request): CommitManagedSetResult
    {
        $adapter = $this->requireAdapter($managedSetKey);
        $committer = $this->requireCommitter($managedSetKey);
        $this->guardAgainstUnfetchedRemoteBootstrap($request->branch);

        return $committer->commitSnapshot(
            $adapter->exportSnapshot(),
            $request
        );
    }

    public function fetch(string $managedSetKey): FetchManagedSetResult
    {
        $this->requireAdapter($managedSetKey);

        $settings = $this->settingsRepository->get();
        [$provider, $remoteConfig] = $this->resolveValidatedProvider($settings);

        return (new RemoteBranchFetcher($provider, $this->localRepository, $remoteConfig))
            ->fetchManagedSet($managedSetKey);
    }

    public function pull(string $managedSetKey): PullManagedSetResult
    {
        $this->requireAdapter($managedSetKey);

        $fetchResult = $this->fetch($managedSetKey);
        $mergeResult = $this->merge($managedSetKey);
        $settings = $this->settingsRepository->get();

        return new PullManagedSetResult(
            $managedSetKey,
            $settings->branch,
            $fetchResult,
            $mergeResult
        );
    }

    public function diff(string $managedSetKey): ManagedSetDiffResult
    {
        $diffService = $this->requireDiffService($managedSetKey);

        return $diffService->diff($this->settingsRepository->get());
    }

    public function merge(string $managedSetKey): MergeManagedSetResult
    {
        $this->requireAdapter($managedSetKey);

        $settings = $this->settingsRepository->get();

        return $this->generateBlocksMergeService->merge($managedSetKey, $settings->branch);
    }

    public function apply(string $managedSetKey): ApplyManagedSetResult
    {
        $applyService = $this->requireApplyService($managedSetKey);

        return $applyService->apply($this->settingsRepository->get());
    }

    public function push(string $managedSetKey): PushManagedSetResult
    {
        $this->requireAdapter($managedSetKey);

        return $this->generateBlocksPushService->push($managedSetKey, $this->settingsRepository->get());
    }

    public function resetRemote(string $managedSetKey): ResetRemoteBranchResult
    {
        $this->requireAdapter($managedSetKey);

        return $this->remoteBranchResetService->reset($managedSetKey, $this->settingsRepository->get());
    }

    private function requireAdapter(string $managedSetKey): ManifestManagedContentAdapterInterface
    {
        if (! $this->managedSetRegistry->has($managedSetKey)) {
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        return $this->managedSetRegistry->get($managedSetKey);
    }

    private function requireCommitter(string $managedSetKey): ManagedSetRepositoryCommitter
    {
        if (! isset($this->committersByManagedSetKey[$managedSetKey])) {
            $this->committersByManagedSetKey[$managedSetKey] = new ManagedSetRepositoryCommitter(
                $this->localRepository,
                $this->requireAdapter($managedSetKey)
            );
        }

        return $this->committersByManagedSetKey[$managedSetKey];
    }

    private function requireDiffService(string $managedSetKey): ManagedSetDiffService
    {
        if (! isset($this->diffServicesByManagedSetKey[$managedSetKey])) {
            if (! $this->repositoryStateReader instanceof RepositoryStateReader) {
                throw new RuntimeException(sprintf('Managed set "%s" cannot be diffed.', $managedSetKey));
            }

            $this->diffServicesByManagedSetKey[$managedSetKey] = new ManagedSetDiffService(
                $this->requireAdapter($managedSetKey),
                $this->repositoryStateReader,
                $this->localRepository
            );
        }

        return $this->diffServicesByManagedSetKey[$managedSetKey];
    }

    private function requireApplyService(string $managedSetKey): ManagedSetApplyServiceInterface
    {
        if (! isset($this->applyServicesByManagedSetKey[$managedSetKey])) {
            if (
                ! $this->repositoryStateReader instanceof RepositoryStateReader
                || ! $this->workingStateRepository instanceof WorkingStateRepository
            ) {
                throw new RuntimeException(sprintf('Managed set "%s" cannot be applied.', $managedSetKey));
            }

            $adapter = $this->requireAdapter($managedSetKey);

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
                    throw new RuntimeException(sprintf('Managed set "%s" cannot be applied.', $managedSetKey));
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

    private function guardAgainstUnfetchedRemoteBootstrap(string $branch): void
    {
        $localRef = $this->localRepository->getRef('refs/heads/' . $branch);

        if ($localRef !== null && $localRef->commitHash !== '') {
            return;
        }

        $trackingRef = $this->localRepository->getRef('refs/remotes/origin/' . $branch);

        if ($trackingRef !== null && $trackingRef->commitHash !== '') {
            return;
        }

        $settings = $this->settingsRepository->get();
        [$provider, $remoteConfig] = $this->resolveValidatedProvider($settings);

        try {
            $remoteRef = $provider->getRef($remoteConfig, 'refs/heads/' . $branch);
        } catch (ProviderException $exception) {
            if ($exception->category === ProviderException::EMPTY_REPOSITORY) {
                return;
            }

            throw $exception;
        }

        if ($remoteRef === null || $remoteRef->commitHash === '') {
            return;
        }

        throw new RuntimeException(sprintf(
            'Remote branch %s already has commits. Fetch it before creating the first local commit.',
            $branch
        ));
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
