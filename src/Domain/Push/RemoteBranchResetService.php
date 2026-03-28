<?php

declare(strict_types=1);

namespace PushPull\Domain\Push;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Provider\CreateRemoteCommitRequest;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteTree;
use PushPull\Provider\UpdateRemoteRefRequest;
use PushPull\Settings\PushPullSettings;
use RuntimeException;

final class RemoteBranchResetService
{
    public function __construct(
        private readonly LocalRepositoryInterface $localRepository,
        private readonly GitProviderFactoryInterface $providerFactory
    ) {
    }

    public function reset(string $managedSetKey, PushPullSettings $settings): ResetRemoteBranchResult
    {
        $remoteConfig = GitRemoteConfig::fromSettings($settings);
        $provider = $this->providerFactory->make($remoteConfig->providerKey);
        $remoteRefName = 'refs/heads/' . $settings->branch;
        $currentRemoteRef = $provider->getRef($remoteConfig, $remoteRefName);

        if ($currentRemoteRef === null || $currentRemoteRef->commitHash === '') {
            throw new RuntimeException(sprintf('Remote branch %s does not exist or cannot be reset safely.', $settings->branch));
        }

        $remoteTreeHash = $provider->createTree($remoteConfig, []);
        $authorName = $settings->authorName !== '' ? $settings->authorName : 'PushPull';
        $remoteCommitHash = $provider->createCommit($remoteConfig, new CreateRemoteCommitRequest(
            $remoteTreeHash,
            [$currentRemoteRef->commitHash],
            sprintf('Reset remote branch %s contents', $settings->branch),
            $authorName,
            $settings->authorEmail
        ));

        $update = $provider->updateRef($remoteConfig, new UpdateRemoteRefRequest(
            $remoteRefName,
            $remoteCommitHash,
            $currentRemoteRef->commitHash
        ));

        if (! $update->success) {
            throw new RuntimeException(sprintf('Remote branch %s could not be reset.', $settings->branch));
        }

        $finalRemoteCommitHash = $update->commitHash !== '' ? $update->commitHash : $remoteCommitHash;
        $this->localRepository->importRemoteTree(new RemoteTree($remoteTreeHash, []));
        $this->localRepository->importRemoteCommit(new RemoteCommit(
            $finalRemoteCommitHash,
            $remoteTreeHash,
            [$currentRemoteRef->commitHash],
            sprintf('Reset remote branch %s contents', $settings->branch)
        ));
        $this->localRepository->updateRef('refs/remotes/origin/' . $settings->branch, $finalRemoteCommitHash);

        return new ResetRemoteBranchResult(
            $managedSetKey,
            $settings->branch,
            $currentRemoteRef->commitHash,
            $finalRemoteCommitHash,
            $remoteTreeHash
        );
    }
}
