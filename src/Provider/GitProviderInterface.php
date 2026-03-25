<?php

declare(strict_types=1);

namespace PushPull\Provider;

interface GitProviderInterface
{
    public function getKey(): string;

    public function getLabel(): string;

    public function getCapabilities(): ProviderCapabilities;

    public function validateConfig(GitRemoteConfig $config): ProviderValidationResult;

    public function testConnection(GitRemoteConfig $config): ProviderConnectionResult;

    public function getRef(GitRemoteConfig $config, string $refName): ?RemoteRef;

    public function getDefaultBranch(GitRemoteConfig $config): ?string;

    public function getCommit(GitRemoteConfig $config, string $hash): ?RemoteCommit;

    public function getTree(GitRemoteConfig $config, string $hash): ?RemoteTree;

    public function getBlob(GitRemoteConfig $config, string $hash): ?RemoteBlob;

    public function createBlob(GitRemoteConfig $config, string $content): string;

    public function createTree(GitRemoteConfig $config, array $entries): string;

    public function createCommit(GitRemoteConfig $config, CreateRemoteCommitRequest $request): string;

    public function updateRef(GitRemoteConfig $config, UpdateRemoteRefRequest $request): UpdateRefResult;

    public function initializeEmptyRepository(GitRemoteConfig $config, string $commitMessage): RemoteRef;
}
