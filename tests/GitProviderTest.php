<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Provider\CreateRemoteCommitRequest;
use PushPull\Provider\Exception\ProviderException;
use PushPull\Provider\Exception\UnsupportedProviderException;
use PushPull\Provider\GitProviderFactory;
use PushPull\Provider\GitHub\GitHubProvider;
use PushPull\Provider\GitLab\GitLabProvider;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\Http\HttpRequest;
use PushPull\Provider\Http\HttpResponse;
use PushPull\Provider\Http\HttpTransportInterface;
use PushPull\Provider\UpdateRemoteRefRequest;

final class GitProviderTest extends TestCase
{
    public function testFactoryReturnsGithubProvider(): void
    {
        $factory = new GitProviderFactory();

        self::assertInstanceOf(GitHubProvider::class, $factory->make('github'));
    }

    public function testFactoryReturnsGitlabProvider(): void
    {
        $factory = new GitProviderFactory();

        self::assertInstanceOf(GitLabProvider::class, $factory->make('gitlab'));
    }

    public function testFactoryRejectsUnknownProviders(): void
    {
        $factory = new GitProviderFactory();

        $this->expectException(UnsupportedProviderException::class);
        $factory->make('forgejo');
    }

    public function testGithubValidationFlagsMissingFields(): void
    {
        $provider = new GitHubProvider(new FakeTransport([]));
        $validation = $provider->validateConfig(new GitRemoteConfig('github', '', '', '', '', 'https://git.example.com'));

        self::assertFalse($validation->isValid());
        self::assertContains('Owner / workspace is required.', $validation->messages);
        self::assertContains('Repository name is required.', $validation->messages);
        self::assertContains('Branch is required.', $validation->messages);
        self::assertContains('API token is required before remote operations can run.', $validation->messages);
        self::assertNotEmpty($validation->messages);
    }

    public function testGithubValidationSucceedsForNormalConfig(): void
    {
        $provider = new GitHubProvider(new FakeTransport([]));
        $validation = $provider->validateConfig(new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null));

        self::assertTrue($validation->isValid());
        self::assertCount(0, $validation->messages);
    }

    public function testConnectionSuccessIsNormalized(): void
    {
        $provider = new GitHubProvider(new FakeTransport([
            new HttpResponse(200, '{"default_branch":"main"}'),
            new HttpResponse(200, '{"ref":"refs/heads/main","object":{"sha":"abc123"}}'),
        ]));
        $config = new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null);

        $result = $provider->testConnection($config);

        self::assertTrue($result->success);
        self::assertSame('main', $result->defaultBranch);
        self::assertSame('main', $result->resolvedBranch);
    }

    public function testConnectionTreatsEmptyRepositoryAsReachableButUninitialized(): void
    {
        $provider = new GitHubProvider(new FakeTransport([
            new HttpResponse(200, '{"default_branch":"main"}'),
            new HttpResponse(409, '{"message":"Git Repository is empty."}'),
        ]));

        $result = $provider->testConnection(new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null));

        self::assertTrue($result->success);
        self::assertTrue($result->emptyRepository);
        self::assertSame('main', $result->resolvedBranch);
    }

    public function testDefaultBranchRetrievalUsesRepositoryMetadata(): void
    {
        $provider = new GitHubProvider(new FakeTransport([
            new HttpResponse(200, '{"default_branch":"trunk"}'),
        ]));

        self::assertSame('trunk', $provider->getDefaultBranch(new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null)));
    }

    public function testGetRefNormalizesGitHubPayload(): void
    {
        $provider = new GitHubProvider(new FakeTransport([
            new HttpResponse(200, '{"ref":"refs/heads/main","object":{"sha":"abc123"}}'),
        ]));

        $ref = $provider->getRef(new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null), 'refs/heads/main');

        self::assertSame('refs/heads/main', $ref?->name);
        self::assertSame('abc123', $ref?->commitHash);
    }

    public function testReadBlobTreeAndCommitAreNormalized(): void
    {
        $provider = new GitHubProvider(new FakeTransport([
            new HttpResponse(200, '{"sha":"blob-1","content":"aGVsbG8=","encoding":"base64"}'),
            new HttpResponse(200, '{"sha":"tree-1","tree":[{"path":"file.json","type":"blob","sha":"blob-1","mode":"100644"}]}'),
            new HttpResponse(200, '{"sha":"commit-1","tree":{"sha":"tree-1"},"parents":[{"sha":"parent-1"}],"message":"Hello","author":{"name":"Jane","email":"jane@example.com"},"committer":{"name":"Jane","email":"jane@example.com"}}'),
        ]));
        $config = new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null);
        $blob = $provider->getBlob($config, 'blob-1');
        $tree = $provider->getTree($config, 'tree-1');
        $commit = $provider->getCommit($config, 'commit-1');

        self::assertSame('hello', $blob?->content);
        self::assertSame('tree-1', $tree?->hash);
        self::assertSame('commit-1', $commit?->hash);
        self::assertSame('Jane', $commit?->author['name']);
    }

    public function testEmptyTreeHashIsHandledLocallyWithoutHttpCall(): void
    {
        $provider = new GitHubProvider(new FakeTransport([]));
        $tree = $provider->getTree(
            new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null),
            '4b825dc642cb6eb9a060e54bf8d69288fbee4904'
        );

        self::assertSame('4b825dc642cb6eb9a060e54bf8d69288fbee4904', $tree?->hash);
        self::assertSame([], $tree?->entries);
    }

    public function testCreateTreeReturnsCanonicalEmptyTreeHashWithoutHttpCall(): void
    {
        $provider = new GitHubProvider(new FakeTransport([]));

        self::assertSame(
            '4b825dc642cb6eb9a060e54bf8d69288fbee4904',
            $provider->createTree(
                new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null),
                []
            )
        );
    }

    public function testCreateBlobTreeAndCommitReturnShas(): void
    {
        $provider = new GitHubProvider(new FakeTransport([
            new HttpResponse(201, '{"sha":"blob-1"}'),
            new HttpResponse(201, '{"sha":"tree-1"}'),
            new HttpResponse(201, '{"sha":"commit-1"}'),
        ]));
        $config = new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null);

        self::assertSame('blob-1', $provider->createBlob($config, 'hello'));
        self::assertSame('tree-1', $provider->createTree($config, [['path' => 'file.json', 'type' => 'blob', 'hash' => 'blob-1']]));
        self::assertSame('commit-1', $provider->createCommit($config, new CreateRemoteCommitRequest('tree-1', ['parent-1'], 'msg', 'Jane', 'jane@example.com')));
    }

    public function testInitializeEmptyRepositoryCreatesFirstCommitViaContentsApi(): void
    {
        $provider = new GitHubProvider(new FakeTransport([
            new HttpResponse(201, '{"commit":{"sha":"commit-1"}}'),
        ]));

        $ref = $provider->initializeEmptyRepository(
            new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null),
            'Initialize PushPull repository'
        );

        self::assertSame('refs/heads/main', $ref->name);
        self::assertSame('commit-1', $ref->commitHash);
    }

    public function testCreateCommitOmitsAuthorAndCommitterWhenEmailIsBlank(): void
    {
        $transport = new RecordingFakeTransport([
            new HttpResponse(201, '{"sha":"commit-1"}'),
        ]);
        $provider = new GitHubProvider($transport);

        self::assertSame(
            'commit-1',
            $provider->createCommit(
                new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null),
                new CreateRemoteCommitRequest('tree-1', ['parent-1'], 'msg', 'PushPull', '')
            )
        );

        self::assertCount(1, $transport->requests);
        self::assertIsArray($transport->requests[0]->json);
        self::assertArrayNotHasKey('author', $transport->requests[0]->json);
        self::assertArrayNotHasKey('committer', $transport->requests[0]->json);
    }

    public function testUpdateRefReturnsNormalizedSuccessResult(): void
    {
        $provider = new GitHubProvider(new FakeTransport([
            new HttpResponse(200, '{"ref":"refs/heads/main","object":{"sha":"commit-2"}}'),
        ]));

        $result = $provider->updateRef(
            new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null),
            new UpdateRemoteRefRequest('refs/heads/main', 'commit-2', 'commit-1')
        );

        self::assertTrue($result->success);
        self::assertSame('commit-2', $result->commitHash);
    }

    public function testEmptyRepositoryConflictIsNormalized(): void
    {
        $provider = new GitHubProvider(new FakeTransport([
            new HttpResponse(409, '{"message":"Git Repository is empty."}'),
        ]));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Git Repository is empty.');

        try {
            $provider->getTree(new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null), 'tree-1');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderException::EMPTY_REPOSITORY, $exception->category);
            throw $exception;
        }
    }

    public function testAuthenticationAndTransportErrorsAreNormalized(): void
    {
        $provider = new GitHubProvider(new FakeTransport([
            new HttpResponse(401, '{"message":"Bad credentials"}'),
        ]));

        try {
            $provider->getRef(new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null), 'refs/heads/main');
            self::fail('Expected ProviderException for bad credentials.');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderException::AUTHENTICATION, $exception->category);
        }
    }

    public function testTransientTransportErrorsAreRetried(): void
    {
        $provider = new GitHubProvider(new SequenceTransport([
            new ProviderException(ProviderException::TRANSPORT, 'temporary'),
            new HttpResponse(200, '{"ref":"refs/heads/main","object":{"sha":"abc123"}}'),
        ]));

        $ref = $provider->getRef(
            new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null),
            'refs/heads/main'
        );

        self::assertSame('abc123', $ref?->commitHash);
    }

    public function testServiceUnavailableResponsesAreRetried(): void
    {
        $provider = new GitHubProvider(new SequenceTransport([
            new HttpResponse(503, '{"message":"Service unavailable"}'),
            new HttpResponse(200, '{"default_branch":"main"}'),
        ]));

        self::assertSame(
            'main',
            $provider->getDefaultBranch(new GitRemoteConfig('github', 'owner', 'repo', 'main', 'token', null))
        );
    }

    public function testGitlabValidationFlagsMissingFields(): void
    {
        $provider = new GitLabProvider(new FakeTransport([]));
        $validation = $provider->validateConfig(new GitRemoteConfig('gitlab', '', '', '', '', 'https://gitlab.example.com'));

        self::assertFalse($validation->isValid());
        self::assertContains('Owner / workspace is required.', $validation->messages);
        self::assertContains('Repository name is required.', $validation->messages);
        self::assertContains('Branch is required.', $validation->messages);
        self::assertContains('API token is required before remote operations can run.', $validation->messages);
    }

    public function testGitlabConnectionSuccessIsNormalized(): void
    {
        $provider = new GitLabProvider(new FakeTransport([
            new HttpResponse(200, '{"default_branch":"main","empty_repo":false}'),
            new HttpResponse(200, '{"name":"main","commit":{"id":"abc123"}}'),
        ]));
        $config = new GitRemoteConfig('gitlab', 'group', 'repo', 'main', 'token', 'https://gitlab.example.com');

        $result = $provider->testConnection($config);

        self::assertTrue($result->success);
        self::assertSame('main', $result->defaultBranch);
        self::assertSame('main', $result->resolvedBranch);
    }

    public function testGitlabConnectionTreatsEmptyRepositoryAsReachableButUninitialized(): void
    {
        $provider = new GitLabProvider(new FakeTransport([
            new HttpResponse(200, '{"default_branch":"main","empty_repo":true}'),
        ]));

        $result = $provider->testConnection(new GitRemoteConfig('gitlab', 'group', 'repo', 'main', 'token', 'https://gitlab.example.com'));

        self::assertTrue($result->success);
        self::assertTrue($result->emptyRepository);
        self::assertSame('main', $result->resolvedBranch);
    }

    public function testGitlabReadBlobTreeAndCommitAreNormalized(): void
    {
        $provider = new GitLabProvider(new FakeTransport([
            new HttpResponse(200, '{"id":"commit-1","parent_ids":["parent-1"],"message":"Hello","author_name":"Jane","author_email":"jane@example.com","committer_name":"Jane","committer_email":"jane@example.com"}'),
            new HttpResponse(200, '[{"path":"file.json","type":"blob","id":"blob-1","mode":"100644"}]', ['X-Next-Page' => '']),
            new HttpResponse(200, 'hello'),
        ]));
        $config = new GitRemoteConfig('gitlab', 'group', 'repo', 'main', 'token', 'https://gitlab.example.com');
        $commit = $provider->getCommit($config, 'commit-1');
        $tree = $provider->getTree($config, $commit?->treeHash ?? '');
        $blob = $provider->getBlob($config, 'blob-1');

        self::assertSame('commit-1', $commit?->hash);
        self::assertSame(['parent-1'], $commit?->parents);
        self::assertSame('Jane', $commit?->author['name']);
        self::assertSame('file.json', $tree?->entries[0]['path']);
        self::assertSame('hello', $blob?->content);
    }

    public function testGitlabRegistersNestedTreesFromRecursiveCommitSnapshot(): void
    {
        $provider = new GitLabProvider(new FakeTransport([
            new HttpResponse(200, '{"id":"commit-1","parent_ids":[],"message":"Hello"}'),
            new HttpResponse(200, '[{"path":"dir","type":"tree","id":"tree-dir","mode":"040000"},{"path":"dir/file.json","type":"blob","id":"blob-1","mode":"100644"}]', ['X-Next-Page' => '']),
        ]));
        $config = new GitRemoteConfig('gitlab', 'group', 'repo', 'main', 'token', 'https://gitlab.example.com');

        $commit = $provider->getCommit($config, 'commit-1');
        $rootTree = $provider->getTree($config, $commit?->treeHash ?? '');

        self::assertSame('dir/file.json', $rootTree?->entries[0]['path']);
        self::assertSame('blob', $rootTree?->entries[0]['type']);
        self::assertSame('blob-1', $rootTree?->entries[0]['hash']);
    }

    public function testGitlabUpdateRefMaterializesLinearCommitIntoRepositoryCommitActions(): void
    {
        $transport = new RecordingFakeTransport([
            new HttpResponse(200, '{"name":"main","commit":{"id":"remote-1"}}'),
            new HttpResponse(200, '{"id":"remote-1","parent_ids":[],"message":"Remote base"}'),
            new HttpResponse(200, '[]', ['X-Next-Page' => '']),
            new HttpResponse(201, '{"id":"remote-2"}'),
        ]);
        $provider = new GitLabProvider($transport);
        $config = new GitRemoteConfig('gitlab', 'group', 'repo', 'main', 'token', 'https://gitlab.example.com');
        $blobHash = $provider->createBlob($config, 'hello');
        $treeHash = $provider->createTree($config, [[
            'path' => 'file.json',
            'type' => 'blob',
            'hash' => $blobHash,
        ]]);
        $commitHash = $provider->createCommit($config, new CreateRemoteCommitRequest(
            $treeHash,
            ['remote-1'],
            'Add file',
            'PushPull',
            'pushpull@example.com'
        ));

        $result = $provider->updateRef(
            $config,
            new UpdateRemoteRefRequest('refs/heads/main', $commitHash, 'remote-1')
        );

        self::assertTrue($result->success);
        self::assertSame('remote-2', $result->commitHash);
        self::assertCount(4, $transport->requests);
        self::assertSame('POST', $transport->requests[3]->method);
        self::assertSame('main', $transport->requests[3]->json['branch'] ?? null);
        self::assertSame('Add file', $transport->requests[3]->json['commit_message'] ?? null);
        self::assertSame('create', $transport->requests[3]->json['actions'][0]['action'] ?? null);
        self::assertSame('file.json', $transport->requests[3]->json['actions'][0]['file_path'] ?? null);
    }

    public function testGitlabFlattensMergeCommitPushesIntoLinearCommitActions(): void
    {
        $transport = new RecordingFakeTransport([
            new HttpResponse(200, '{"name":"main","commit":{"id":"remote-1"}}'),
            new HttpResponse(200, '{"id":"remote-1","parent_ids":[],"message":"Remote base"}'),
            new HttpResponse(200, '[]', ['X-Next-Page' => '']),
            new HttpResponse(201, '{"id":"remote-2"}'),
        ]);
        $provider = new GitLabProvider($transport);
        $config = new GitRemoteConfig('gitlab', 'group', 'repo', 'main', 'token', 'https://gitlab.example.com');
        $blobHash = $provider->createBlob($config, 'merged');
        $treeHash = $provider->createTree($config, [[
            'path' => 'merged.json',
            'type' => 'blob',
            'hash' => $blobHash,
        ]]);
        $commitHash = $provider->createCommit(
            $config,
            new CreateRemoteCommitRequest($treeHash, ['remote-1', 'other-parent'], 'Flatten merge', 'PushPull', 'pushpull@example.com')
        );

        $result = $provider->updateRef(
            $config,
            new UpdateRemoteRefRequest('refs/heads/main', $commitHash, 'remote-1')
        );

        self::assertTrue($result->success);
        self::assertSame('remote-2', $result->commitHash);
        self::assertCount(4, $transport->requests);
        self::assertSame('Flatten merge', $transport->requests[3]->json['commit_message'] ?? null);
    }
}

final class FakeTransport implements HttpTransportInterface
{
    /** @param HttpResponse[] $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        if ($this->responses === []) {
            throw new ProviderException(ProviderException::TRANSPORT, 'No fake response queued.');
        }

        return array_shift($this->responses);
    }
}

final class RecordingFakeTransport implements HttpTransportInterface
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

final class SequenceTransport implements HttpTransportInterface
{
    /** @param array<HttpResponse|ProviderException> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        if ($this->responses === []) {
            throw new ProviderException(ProviderException::TRANSPORT, 'No fake response queued.');
        }

        $response = array_shift($this->responses);

        if ($response instanceof ProviderException) {
            throw $response;
        }

        return $response;
    }
}
