<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\RemoteRepositoryInitializer;
use PushPull\Domain\Sync\RemoteBranchFetcher;
use PushPull\Provider\CreateRemoteCommitRequest;
use PushPull\Provider\GitLab\GitLabProvider;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\Http\HttpRequest;
use PushPull\Provider\Http\HttpResponse;
use PushPull\Provider\Http\HttpTransportInterface;
use PushPull\Provider\Exception\ProviderException;
use PushPull\Provider\ProviderCapabilities;
use PushPull\Provider\ProviderConnectionResult;
use PushPull\Provider\ProviderValidationResult;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteRef;
use PushPull\Provider\RemoteTree;
use PushPull\Provider\UpdateRefResult;
use PushPull\Provider\UpdateRemoteRefRequest;
use RuntimeException;

final class RemoteBranchFetcherTest extends TestCase
{
    public function testFetchImportsRemoteObjectsAndUpdatesTrackingRef(): void
    {
        $provider = new InMemoryProvider();
        $provider->refs['refs/heads/main'] = new RemoteRef('refs/heads/main', 'commit-2');
        $provider->commits['commit-1'] = new RemoteCommit('commit-1', 'tree-1', [], 'Initial remote commit');
        $provider->commits['commit-2'] = new RemoteCommit('commit-2', 'tree-2', ['commit-1'], 'Second remote commit');
        $provider->trees['tree-1'] = new RemoteTree('tree-1', [
            ['path' => 'generateblocks/global-styles/gbp-section.json', 'type' => 'blob', 'hash' => 'blob-1'],
        ]);
        $provider->trees['tree-2'] = new RemoteTree('tree-2', [
            ['path' => 'generateblocks/global-styles/gbp-section.json', 'type' => 'blob', 'hash' => 'blob-1'],
            ['path' => 'generateblocks/global-styles/manifest.json', 'type' => 'blob', 'hash' => 'blob-2'],
        ]);
        $provider->blobs['blob-1'] = new RemoteBlob('blob-1', "{\n  \"style\": true\n}\n");
        $provider->blobs['blob-2'] = new RemoteBlob('blob-2', "{\n  \"manifest\": true\n}\n");

        $repository = new DatabaseLocalRepository(new \wpdb());
        $fetcher = new RemoteBranchFetcher(
            $provider,
            $repository,
            new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null)
        );

        $result = $fetcher->fetchManagedSet('generateblocks_global_styles');

        self::assertSame('refs/remotes/origin/main', $result->remoteRefName);
        self::assertSame('commit-2', $result->remoteCommitHash);
        self::assertSame('commit-2', $repository->getRef('refs/remotes/origin/main')?->commitHash);
        self::assertNotNull($repository->getCommit('commit-1'));
        self::assertNotNull($repository->getCommit('commit-2'));
        self::assertNotNull($repository->getTree('tree-1'));
        self::assertNotNull($repository->getTree('tree-2'));
        self::assertNotNull($repository->getBlob('blob-1'));
        self::assertNotNull($repository->getBlob('blob-2'));
        self::assertSame(['commit-1', 'commit-2'], $result->traversedCommitHashes);
        self::assertSame(['commit-1', 'commit-2'], $result->newCommitHashes);
        self::assertSame(['tree-1', 'tree-2'], $result->newTreeHashes);
        self::assertSame(['blob-1', 'blob-2'], $result->newBlobHashes);
    }

    public function testSecondFetchReportsOnlyNewlyImportedDelta(): void
    {
        $provider = new InMemoryProvider();
        $provider->refs['refs/heads/main'] = new RemoteRef('refs/heads/main', 'commit-2');
        $provider->commits['commit-1'] = new RemoteCommit('commit-1', 'tree-1', [], 'Initial remote commit');
        $provider->commits['commit-2'] = new RemoteCommit('commit-2', 'tree-2', ['commit-1'], 'Second remote commit');
        $provider->trees['tree-1'] = new RemoteTree('tree-1', [
            ['path' => 'generateblocks/global-styles/gbp-section.json', 'type' => 'blob', 'hash' => 'blob-1'],
        ]);
        $provider->trees['tree-2'] = new RemoteTree('tree-2', [
            ['path' => 'generateblocks/global-styles/gbp-section.json', 'type' => 'blob', 'hash' => 'blob-1'],
            ['path' => 'generateblocks/global-styles/manifest.json', 'type' => 'blob', 'hash' => 'blob-2'],
        ]);
        $provider->blobs['blob-1'] = new RemoteBlob('blob-1', "{\n  \"style\": true\n}\n");
        $provider->blobs['blob-2'] = new RemoteBlob('blob-2', "{\n  \"manifest\": true\n}\n");

        $repository = new DatabaseLocalRepository(new \wpdb());
        $fetcher = new RemoteBranchFetcher(
            $provider,
            $repository,
            new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null)
        );
        $fetcher->fetchManagedSet('generateblocks_global_styles');

        $provider->refs['refs/heads/main'] = new RemoteRef('refs/heads/main', 'commit-3');
        $provider->commits['commit-3'] = new RemoteCommit('commit-3', 'tree-3', ['commit-2'], 'Third remote commit');
        $provider->trees['tree-3'] = new RemoteTree('tree-3', [
            ['path' => 'generateblocks/global-styles/gbp-section.json', 'type' => 'blob', 'hash' => 'blob-1'],
            ['path' => 'generateblocks/global-styles/manifest.json', 'type' => 'blob', 'hash' => 'blob-3'],
        ]);
        $provider->blobs['blob-3'] = new RemoteBlob('blob-3', "{\n  \"manifest\": false\n}\n");

        $secondResult = (new RemoteBranchFetcher(
            $provider,
            $repository,
            new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null)
        ))->fetchManagedSet('generateblocks_global_styles');

        self::assertSame(['commit-3'], $secondResult->traversedCommitHashes);
        self::assertSame(['tree-3'], $secondResult->traversedTreeHashes);
        self::assertSame(['blob-3'], $secondResult->traversedBlobHashes);
        self::assertSame(['commit-3'], $secondResult->newCommitHashes);
        self::assertSame(['tree-3'], $secondResult->newTreeHashes);
        self::assertSame(['blob-3'], $secondResult->newBlobHashes);
    }

    public function testFetchFailsWhenRemoteBranchIsMissing(): void
    {
        $fetcher = new RemoteBranchFetcher(
            new InMemoryProvider(),
            new DatabaseLocalRepository(new \wpdb()),
            new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null)
        );

        $this->expectException(RuntimeException::class);
        $fetcher->fetchManagedSet('generateblocks_global_styles');
    }

    public function testGitlabColdFetchUsesArchivePreloadOnlyForHeadTree(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for GitLab archive preload tests.');
        }

        $transport = new RecordingTransport([
            new HttpResponse(200, '{"name":"main","commit":{"id":"commit-2"}}'),
            new HttpResponse(200, '{"id":"commit-2","parent_ids":["commit-1"],"message":"Second remote commit"}'),
            new HttpResponse(200, '[{"path":"current.json","type":"blob","id":"blob-2","mode":"100644"}]', ['X-Next-Page' => '']),
            new HttpResponse(200, '{"id":"commit-1","parent_ids":[],"message":"Initial remote commit"}'),
            new HttpResponse(200, '[{"path":"old.json","type":"blob","id":"blob-1","mode":"100644"}]', ['X-Next-Page' => '']),
            new HttpResponse(200, $this->zipArchiveBody([
                'repo-commit-2/current.json' => "current\n",
            ])),
            new HttpResponse(200, "old\n"),
        ]);
        $provider = new GitLabProvider($transport);
        $repository = new DatabaseLocalRepository(new \wpdb());
        $fetcher = new RemoteBranchFetcher(
            $provider,
            $repository,
            new GitRemoteConfig('gitlab', 'group', 'repo', 'main', 'token', 'https://gitlab.example.com')
        );

        $result = $fetcher->fetchManagedSet('wordpress_pages');
        $archiveRequests = array_values(array_filter(
            $transport->requests,
            static fn (HttpRequest $request): bool => str_contains($request->url, '/repository/archive.zip?sha=')
        ));
        $treeRequests = array_values(array_filter(
            $transport->requests,
            static fn (HttpRequest $request): bool => str_contains($request->url, '/repository/tree?ref=')
        ));

        self::assertSame(['blob-2'], $result->newBlobHashes);
        self::assertNull($repository->getTree('gitlab-root-tree-commit-1'));
        self::assertNull($repository->getBlob('blob-1'));
        self::assertCount(1, $archiveRequests);
        self::assertCount(1, $treeRequests);
        self::assertStringContainsString('sha=commit-2', $archiveRequests[0]->url);
    }

    /**
     * @param array<string, string> $files
     */
    private function zipArchiveBody(array $files): string
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'pushpull-gitlab-test-archive-');
        self::assertIsString($archivePath);

        $zip = new \ZipArchive();
        self::assertSame(true, $zip->open($archivePath, \ZipArchive::OVERWRITE));

        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
        }

        $zip->close();
        $body = (string) file_get_contents($archivePath);
        @unlink($archivePath);

        return $body;
    }

    public function testInitializerCreatesFirstRemoteCommitAndFetchesItIntoTrackingRef(): void
    {
        $provider = new InMemoryProvider();
        $repository = new DatabaseLocalRepository(new \wpdb());
        $initializer = new RemoteRepositoryInitializer(new InMemoryProviderFactory($provider), $repository);

        $result = $initializer->initialize('generateblocks_global_styles', new \PushPull\Settings\PushPullSettings(
            'github',
            'owner',
            'repo',
            'main',
            'token',
            '',
            false,
            true,
            'Jane Doe',
            'jane@example.com',
            ['generateblocks_global_styles']
        ));

        self::assertSame('refs/heads/main', $result->remoteRefName);
        self::assertSame($result->remoteCommitHash, $repository->getRef('refs/remotes/origin/main')?->commitHash);
        self::assertSame($result->remoteCommitHash, $repository->getRef('refs/heads/main')?->commitHash);
        self::assertSame($result->remoteCommitHash, $repository->getRef('HEAD')?->commitHash);
        self::assertNotNull($repository->getCommit($result->remoteCommitHash));
    }
}

final class InMemoryProviderFactory implements \PushPull\Provider\GitProviderFactoryInterface
{
    public function __construct(private readonly GitProviderInterface $provider)
    {
    }

    public function make(string $providerKey): GitProviderInterface
    {
        return $this->provider;
    }
}

final class RecordingTransport implements HttpTransportInterface
{
    /** @var HttpRequest[] */
    public array $requests = [];

    /** @param HttpResponse[] $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        if ($this->responses === []) {
            throw new ProviderException(ProviderException::TRANSPORT, 'No fake response queued.');
        }

        return array_shift($this->responses);
    }
}

final class InMemoryProvider implements GitProviderInterface
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
        return 'memory';
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
        throw new RuntimeException('Not used in fetch tests.');
    }

    public function createTree(GitRemoteConfig $config, array $entries): string
    {
        throw new RuntimeException('Not used in fetch tests.');
    }

    public function createCommit(GitRemoteConfig $config, CreateRemoteCommitRequest $request): string
    {
        throw new RuntimeException('Not used in fetch tests.');
    }

    public function updateRef(GitRemoteConfig $config, UpdateRemoteRefRequest $request): UpdateRefResult
    {
        throw new RuntimeException('Not used in fetch tests.');
    }

    public function initializeEmptyRepository(GitRemoteConfig $config, string $commitMessage): RemoteRef
    {
        $blobHash = 'blob-init';
        $treeHash = 'tree-init';
        $commitHash = 'commit-init';
        $this->blobs[$blobHash] = new RemoteBlob($blobHash, "Initialized by PushPull.\n");
        $this->trees[$treeHash] = new RemoteTree($treeHash, [
            ['path' => '.pushpull-initialized', 'type' => 'blob', 'hash' => $blobHash],
        ]);
        $this->commits[$commitHash] = new RemoteCommit($commitHash, $treeHash, [], $commitMessage);
        $this->refs['refs/heads/' . $config->branch] = new RemoteRef('refs/heads/' . $config->branch, $commitHash);

        return $this->refs['refs/heads/' . $config->branch];
    }
}
