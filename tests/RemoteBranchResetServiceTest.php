<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Domain\Push\RemoteBranchResetService;
use PushPull\Domain\Repository\DatabaseLocalRepository;
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
use PushPull\Settings\PushPullSettings;

final class RemoteBranchResetServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private ResetTestProvider $provider;
    private RemoteBranchResetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->provider = new ResetTestProvider();
        $this->service = new RemoteBranchResetService($this->repository, new ResetTestProviderFactory($this->provider));
    }

    public function testResetRemoteCreatesSingleEmptyTreeCommitAndOnlyUpdatesTrackingRef(): void
    {
        $baseTree = new RemoteTree('remote-tree-base', [
            ['path' => 'generateblocks/global-styles/manifest.json', 'type' => 'blob', 'hash' => 'remote-blob-manifest'],
        ]);
        $baseCommit = new RemoteCommit('remote-base', $baseTree->hash, [], 'Base commit');

        $this->provider->trees[$baseTree->hash] = $baseTree;
        $this->provider->commits[$baseCommit->hash] = $baseCommit;
        $this->provider->refs['refs/heads/main'] = new RemoteRef('refs/heads/main', $baseCommit->hash);

        $this->repository->importRemoteTree($baseTree);
        $this->repository->importRemoteCommit($baseCommit);
        $this->repository->updateRef('refs/remotes/origin/main', $baseCommit->hash);
        $this->repository->updateRef('refs/heads/main', $baseCommit->hash);
        $this->repository->updateRef('HEAD', $baseCommit->hash);

        $result = $this->service->reset('generateblocks_global_styles', new PushPullSettings(
            'github',
            'creativemoods',
            'pushpulltestrepo',
            'main',
            'token',
            '',
            false,
            true,
            'Jane Doe',
            'jane@example.com',
            ['generateblocks_global_styles']
        ));

        self::assertSame('generateblocks_global_styles', $result->managedSetKey);
        self::assertSame('main', $result->branch);
        self::assertSame('remote-base', $result->previousRemoteCommitHash);
        self::assertNotSame('remote-base', $result->remoteCommitHash);
        self::assertSame([], $this->provider->trees[$result->remoteTreeHash]->entries);
        self::assertSame([$result->previousRemoteCommitHash], $this->provider->commits[$result->remoteCommitHash]->parents);
        self::assertSame('remote-base', $this->repository->getRef('refs/heads/main')?->commitHash);
        self::assertSame($result->remoteCommitHash, $this->repository->getRef('refs/remotes/origin/main')?->commitHash);
        self::assertSame($result->remoteCommitHash, $this->provider->refs['refs/heads/main']->commitHash);
    }
}

final class ResetTestProviderFactory implements GitProviderFactoryInterface
{
    public function __construct(private readonly GitProviderInterface $provider)
    {
    }

    public function make(string $providerKey): GitProviderInterface
    {
        return $this->provider;
    }
}

final class ResetTestProvider implements GitProviderInterface
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
        return 'Memory';
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
        ]));
        $this->commits[$hash] = new RemoteCommit($hash, $request->treeHash, $request->parentHashes, $request->message);

        return $hash;
    }

    public function updateRef(GitRemoteConfig $config, UpdateRemoteRefRequest $request): UpdateRefResult
    {
        $this->refs[$request->refName] = new RemoteRef($request->refName, $request->newCommitHash);

        return new UpdateRefResult(true, $request->refName, $request->newCommitHash);
    }

    public function initializeEmptyRepository(GitRemoteConfig $config, string $commitMessage): RemoteRef
    {
        throw new \RuntimeException('Not used in remote reset tests.');
    }
}
