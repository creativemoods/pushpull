<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Domain\Push\ManagedSetPushService;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
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

final class ManagedSetPushServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private GenerateBlocksGlobalStylesAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private InMemoryPushProvider $provider;
    private ManagedSetPushService $pushService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new GenerateBlocksGlobalStylesAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->provider = new InMemoryPushProvider();
        $this->pushService = new ManagedSetPushService($this->repository, new InMemoryPushProviderFactory($this->provider));
    }

    public function testPushUploadsAheadLocalCommitAndRepointsLocalRefsToRemoteHashes(): void
    {
        $baseSnapshot = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);
        $localSnapshot = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '8rem']),
        ]);

        $this->importRemoteBase($baseSnapshot, 'remote-base');
        $this->provider->refs['refs/heads/main'] = new RemoteRef('refs/heads/main', 'remote-base');

        $localCommit = $this->committer->commitSnapshot(
            $localSnapshot,
            new CommitManagedSetRequest('main', 'Local change', 'Jane Doe', 'jane@example.com')
        )->commit;

        self::assertNotNull($localCommit);
        self::assertNotSame('remote-base', $localCommit->hash);

        $result = $this->pushService->push('generateblocks_global_styles', new PushPullSettings(
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

        self::assertSame('pushed', $result->status);
        self::assertNotNull($result->remoteCommitHash);
        self::assertSame($result->remoteCommitHash, $this->provider->refs['refs/heads/main']->commitHash);
        self::assertSame($result->remoteCommitHash, $this->repository->getRef('refs/heads/main')?->commitHash);
        self::assertSame($result->remoteCommitHash, $this->repository->getRef('refs/remotes/origin/main')?->commitHash);
        self::assertNotNull($this->repository->getCommit($result->remoteCommitHash));
        self::assertNotEmpty($result->pushedCommitHashes);
        self::assertNotEmpty($result->pushedTreeHashes);
        self::assertNotEmpty($result->pushedBlobHashes);
    }

    public function testPushReturnsAlreadyUpToDateWhenRefsMatch(): void
    {
        $baseSnapshot = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);

        $this->importRemoteBase($baseSnapshot, 'remote-base');
        $this->repository->updateRef('refs/heads/main', 'remote-base');
        $this->provider->refs['refs/heads/main'] = new RemoteRef('refs/heads/main', 'remote-base');

        $result = $this->pushService->push('generateblocks_global_styles', new PushPullSettings(
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

        self::assertSame('already_up_to_date', $result->status);
        self::assertSame([], $result->pushedCommitHashes);
    }

    public function testPushRepointsLocalRefsToActualUpdatedRemoteHashWhenProviderReturnsDifferentHash(): void
    {
        $baseSnapshot = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);
        $localSnapshot = $this->snapshot([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '8rem']),
        ]);

        $this->importRemoteBase($baseSnapshot, 'remote-base');
        $this->provider->refs['refs/heads/main'] = new RemoteRef('refs/heads/main', 'remote-base');
        $this->provider->updatedCommitHashOverride = 'provider-commit-2';

        $this->committer->commitSnapshot(
            $localSnapshot,
            new CommitManagedSetRequest('main', 'Local change', 'Jane Doe', 'jane@example.com')
        );

        $result = $this->pushService->push('generateblocks_global_styles', new PushPullSettings(
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

        self::assertSame('provider-commit-2', $result->remoteCommitHash);
        self::assertSame('provider-commit-2', $this->repository->getRef('refs/heads/main')?->commitHash);
        self::assertSame('provider-commit-2', $this->repository->getRef('refs/remotes/origin/main')?->commitHash);
        self::assertNotNull($this->repository->getCommit('provider-commit-2'));
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function snapshot(array $records): \PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesSnapshot
    {
        return $this->adapter->snapshotFromRuntimeRecords($records);
    }

    /**
     * @param array<string, mixed> $styleData
     * @return array<string, mixed>
     */
    private function runtimeRecord(string $selector, string $slug, int $menuOrder, array $styleData = []): array
    {
        return [
            'wp_object_id' => 1,
            'post_title' => $selector,
            'post_name' => $slug,
            'post_status' => 'publish',
            'menu_order' => $menuOrder,
            'gb_style_selector' => $selector,
            'gb_style_data' => serialize($styleData),
            'gb_style_css' => $selector . ' { color: red; }',
        ];
    }

    private function importRemoteBase(\PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesSnapshot $snapshot, string $commitHash): void
    {
        $entries = [];
        $counter = 1;

        foreach ($snapshot->items as $item) {
            $path = $this->adapter->getRepositoryPath($item);
            $blobHash = sprintf('%s-blob-%d', $commitHash, $counter++);
            $content = $this->adapter->serialize($item);
            $this->repository->importRemoteBlob(new RemoteBlob($blobHash, $content));
            $this->provider->blobs[$blobHash] = new RemoteBlob($blobHash, $content);
            $entries[] = ['path' => $path, 'type' => 'blob', 'hash' => $blobHash];
        }

        $manifestHash = sprintf('%s-blob-%d', $commitHash, $counter);
        $manifestContent = $this->adapter->serializeManifest($snapshot->manifest);
        $this->repository->importRemoteBlob(new RemoteBlob($manifestHash, $manifestContent));
        $this->provider->blobs[$manifestHash] = new RemoteBlob($manifestHash, $manifestContent);
        $entries[] = ['path' => $this->adapter->getManifestPath(), 'type' => 'blob', 'hash' => $manifestHash];

        $treeHash = $commitHash . '-tree';
        $this->repository->importRemoteTree(new RemoteTree($treeHash, $entries));
        $this->provider->trees[$treeHash] = new RemoteTree($treeHash, $entries);
        $this->repository->importRemoteCommit(new RemoteCommit($commitHash, $treeHash, [], 'Remote base'));
        $this->provider->commits[$commitHash] = new RemoteCommit($commitHash, $treeHash, [], 'Remote base');
        $this->repository->updateRef('refs/remotes/origin/main', $commitHash);
        $this->repository->updateRef('refs/heads/main', $commitHash);
        $this->repository->updateRef('HEAD', $commitHash);
    }
}

final class InMemoryPushProviderFactory implements GitProviderFactoryInterface
{
    public function __construct(private readonly GitProviderInterface $provider)
    {
    }

    public function make(string $providerKey): GitProviderInterface
    {
        return $this->provider;
    }
}

final class InMemoryPushProvider implements GitProviderInterface
{
    /** @var array<string, RemoteRef> */
    public array $refs = [];
    /** @var array<string, RemoteCommit> */
    public array $commits = [];
    /** @var array<string, RemoteTree> */
    public array $trees = [];
    /** @var array<string, RemoteBlob> */
    public array $blobs = [];
    public ?string $updatedCommitHashOverride = null;

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
        $hash = 'remote-tree-' . sha1(json_encode($entries));
        $this->trees[$hash] = new RemoteTree($hash, $entries);

        return $hash;
    }

    public function createCommit(GitRemoteConfig $config, CreateRemoteCommitRequest $request): string
    {
        $hash = 'remote-commit-' . sha1(json_encode([
            'tree' => $request->treeHash,
            'parents' => $request->parentHashes,
            'message' => $request->message,
        ]));
        $this->commits[$hash] = new RemoteCommit($hash, $request->treeHash, $request->parentHashes, $request->message);

        return $hash;
    }

    public function updateRef(GitRemoteConfig $config, UpdateRemoteRefRequest $request): UpdateRefResult
    {
        $finalCommitHash = $this->updatedCommitHashOverride ?? $request->newCommitHash;
        $this->refs[$request->refName] = new RemoteRef($request->refName, $finalCommitHash);

        return new UpdateRefResult(true, $request->refName, $finalCommitHash);
    }

    public function initializeEmptyRepository(GitRemoteConfig $config, string $commitMessage): RemoteRef
    {
        throw new RuntimeException('Not used in push tests.');
    }
}
