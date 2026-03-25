<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Settings\PushPullSettings;
use RuntimeException;

final class RemoteRepositoryInitializer
{
    public function __construct(
        private readonly GitProviderFactoryInterface $providerFactory,
        private readonly LocalRepositoryInterface $localRepository
    ) {
    }

    public function initialize(string $managedSetKey, PushPullSettings $settings): InitializeRemoteRepositoryResult
    {
        $remoteConfig = GitRemoteConfig::fromSettings($settings);
        $provider = $this->providerFactory->make($remoteConfig->providerKey);
        $validation = $provider->validateConfig($remoteConfig);

        if (! $validation->isValid()) {
            throw new RuntimeException(implode(' ', $validation->messages));
        }

        $remoteRef = $provider->initializeEmptyRepository(
            $remoteConfig,
            sprintf('Initialize PushPull repository for branch %s', $settings->branch)
        );

        $fetchResult = (new RemoteBranchFetcher($provider, $this->localRepository, $remoteConfig))
            ->fetchManagedSet($managedSetKey);

        return new InitializeRemoteRepositoryResult(
            $managedSetKey,
            $settings->branch,
            $remoteRef->name,
            $remoteRef->commitHash,
            $fetchResult
        );
    }
}
