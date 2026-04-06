<?php

declare(strict_types=1);

namespace PushPull\Tests\Support;

use PushPull\Provider\CreateRemoteCommitRequest;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\ProviderCapabilities;
use PushPull\Provider\ProviderConnectionResult;
use PushPull\Provider\ProviderValidationResult;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteRef;
use PushPull\Provider\RemoteTree;
use PushPull\Provider\UpdateRefResult;
use PushPull\Provider\UpdateRemoteRefRequest;

final class InMemoryWorkflowGitProviderFactory implements GitProviderFactoryInterface
{
    public function __construct(private readonly GitProviderInterface $provider)
    {
    }

    public function make(string $providerKey): GitProviderInterface
    {
        return $this->provider;
    }
}

final class InMemoryWorkflowGitProvider implements GitProviderInterface
{
    /** @var array<string, RemoteRef> */
    public array $refs = [];
    /** @var array<string, RemoteCommit> */
    public array $commits = [];
    /** @var array<string, RemoteTree> */
    public array $trees = [];
    /** @var array<string, RemoteBlob> */
    public array $blobs = [];

    public function getKey(): string
    {
        return 'github';
    }

    public function getLabel(): string
    {
        return 'In-memory workflow provider';
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(true, true, true, true);
    }

    public function validateConfig(GitRemoteConfig $config): ProviderValidationResult
    {
        return new ProviderValidationResult(true, []);
    }

    public function testConnection(GitRemoteConfig $config): ProviderConnectionResult
    {
        return new ProviderConnectionResult(true, $config->repositoryPath(), 'main', $config->branch, false, []);
    }

    public function getRef(GitRemoteConfig $config, string $refName): ?RemoteRef
    {
        return $this->refs[$refName] ?? null;
    }

    public function getDefaultBranch(GitRemoteConfig $config): ?string
    {
        return 'main';
    }

    public function getCommit(GitRemoteConfig $config, string $hash): ?RemoteCommit
    {
        return $this->commits[$hash] ?? null;
    }

    public function getTree(GitRemoteConfig $config, string $hash): ?RemoteTree
    {
        return $this->trees[$hash] ?? null;
    }

    public function getBlob(GitRemoteConfig $config, string $hash): ?RemoteBlob
    {
        return $this->blobs[$hash] ?? null;
    }

    public function createBlob(GitRemoteConfig $config, string $content): string
    {
        $hash = 'remote-blob-' . sha1($content);
        $this->blobs[$hash] = new RemoteBlob($hash, $content);

        return $hash;
    }

    public function createTree(GitRemoteConfig $config, array $entries): string
    {
        $hash = 'remote-tree-' . sha1((string) json_encode($entries));
        $this->trees[$hash] = new RemoteTree($hash, $entries);

        return $hash;
    }

    public function createCommit(GitRemoteConfig $config, CreateRemoteCommitRequest $request): string
    {
        $hash = 'remote-commit-' . sha1((string) json_encode([
            'tree' => $request->treeHash,
            'parents' => $request->parentHashes,
            'message' => $request->message,
            'author' => $request->authorName,
            'email' => $request->authorEmail,
        ]));
        $this->commits[$hash] = new RemoteCommit(
            $hash,
            $request->treeHash,
            $request->parentHashes,
            $request->message,
            [
                'name' => $request->authorName,
                'email' => $request->authorEmail,
            ],
            [
                'name' => $request->authorName,
                'email' => $request->authorEmail,
            ]
        );

        return $hash;
    }

    public function updateRef(GitRemoteConfig $config, UpdateRemoteRefRequest $request): UpdateRefResult
    {
        $this->refs[$request->refName] = new RemoteRef($request->refName, $request->newCommitHash);

        return new UpdateRefResult(true, $request->refName, $request->newCommitHash);
    }

    public function initializeEmptyRepository(GitRemoteConfig $config, string $commitMessage): RemoteRef
    {
        $blobHash = $this->createBlob($config, "Initialized by PushPull.\n");
        $treeHash = $this->createTree($config, [
            ['path' => '.pushpull-initialized', 'type' => 'blob', 'hash' => $blobHash],
        ]);
        $commitHash = $this->createCommit($config, new CreateRemoteCommitRequest(
            $treeHash,
            [],
            $commitMessage,
            'PushPull',
            ''
        ));

        $this->refs['refs/heads/' . $config->branch] = new RemoteRef('refs/heads/' . $config->branch, $commitHash);

        return $this->refs['refs/heads/' . $config->branch];
    }
}
