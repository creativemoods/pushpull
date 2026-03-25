<?php

declare(strict_types=1);

namespace PushPull\Provider\GitHub;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Provider\CreateRemoteCommitRequest;
use PushPull\Provider\Exception\ProviderException;
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
use PushPull\Provider\Http\HttpRequest;
use PushPull\Provider\Http\HttpTransportInterface;
use PushPull\Provider\Http\WordPressHttpTransport;

final class GitHubProvider implements GitProviderInterface
{
    private const API_VERSION = '2022-11-28';
    private const EMPTY_TREE_HASH = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly ?HttpTransportInterface $transport = null)
    {
    }

    public function getKey(): string
    {
        return 'github';
    }

    public function getLabel(): string
    {
        return 'GitHub';
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(true, true, true, true);
    }

    public function validateConfig(GitRemoteConfig $config): ProviderValidationResult
    {
        $messages = [];

        if ($config->ownerOrWorkspace === '') {
            $messages[] = 'Owner / workspace is required.';
        }

        if ($config->repository === '') {
            $messages[] = 'Repository name is required.';
        }

        if ($config->branch === '') {
            $messages[] = 'Branch is required.';
        }

        if ($config->apiToken === '') {
            $messages[] = 'API token is required before remote operations can run.';
        }

        if ($config->baseUrl !== null && ! str_starts_with($config->baseUrl, 'http')) {
            $messages[] = 'Base URL must be an absolute HTTP(S) URL.';
        }

        return new ProviderValidationResult($messages === [], $messages);
    }

    public function testConnection(GitRemoteConfig $config): ProviderConnectionResult
    {
        $this->assertValidConfig($config);
        $repository = $this->requestJson('GET', $config, '/repos/' . $this->repositoryPath($config), 'get_repository');
        $defaultBranch = is_string($repository['default_branch'] ?? null) ? $repository['default_branch'] : null;
        $resolvedBranch = $config->branch !== '' ? $config->branch : $defaultBranch;

        if ($resolvedBranch === null || $resolvedBranch === '') {
            throw new ProviderException(
                ProviderException::UNSUPPORTED_RESPONSE,
                'GitHub repository metadata did not include a default branch.'
            );
        }

        try {
            $this->getRef($config, 'refs/heads/' . $resolvedBranch);
        } catch (ProviderException $exception) {
            if ($exception->category !== ProviderException::EMPTY_REPOSITORY) {
                throw $exception;
            }

            return new ProviderConnectionResult(
                true,
                $this->repositoryPath($config),
                $defaultBranch,
                $resolvedBranch,
                true,
                ['GitHub repository is reachable but empty and needs an initial commit before fetch or push can run.']
            );
        }

        return new ProviderConnectionResult(
            true,
            $this->repositoryPath($config),
            $defaultBranch,
            $resolvedBranch,
            false,
            ['GitHub repository metadata and target branch are accessible.']
        );
    }

    public function getRef(GitRemoteConfig $config, string $refName): ?RemoteRef
    {
        $payload = $this->requestJson('GET', $config, '/repos/' . $this->repositoryPath($config) . '/git/ref/' . $this->normalizeRefPath($refName), 'get_ref', [
            404 => ProviderException::REF_NOT_FOUND,
        ]);

        return new RemoteRef(
            (string) ($payload['ref'] ?? $refName),
            (string) ($payload['object']['sha'] ?? '')
        );
    }

    public function getDefaultBranch(GitRemoteConfig $config): ?string
    {
        $repository = $this->requestJson('GET', $config, '/repos/' . $this->repositoryPath($config), 'get_repository');

        return is_string($repository['default_branch'] ?? null) ? $repository['default_branch'] : null;
    }

    public function getCommit(GitRemoteConfig $config, string $hash): ?RemoteCommit
    {
        $payload = $this->requestJson('GET', $config, '/repos/' . $this->repositoryPath($config) . '/git/commits/' . $hash, 'get_commit', [
            404 => ProviderException::REPOSITORY_NOT_FOUND,
            409 => ProviderException::EMPTY_REPOSITORY,
        ]);

        return new RemoteCommit(
            (string) ($payload['sha'] ?? $hash),
            (string) ($payload['tree']['sha'] ?? ''),
            array_map(static fn (array $parent): string => (string) ($parent['sha'] ?? ''), (array) ($payload['parents'] ?? [])),
            (string) ($payload['message'] ?? ''),
            is_array($payload['author'] ?? null) ? array_filter($payload['author'], 'is_string') : null,
            is_array($payload['committer'] ?? null) ? array_filter($payload['committer'], 'is_string') : null
        );
    }

    public function getTree(GitRemoteConfig $config, string $hash): ?RemoteTree
    {
        if ($hash === self::EMPTY_TREE_HASH) {
            return new RemoteTree(self::EMPTY_TREE_HASH, []);
        }

        $payload = $this->requestJson('GET', $config, '/repos/' . $this->repositoryPath($config) . '/git/trees/' . $hash, 'get_tree', [
            404 => ProviderException::REPOSITORY_NOT_FOUND,
            409 => ProviderException::EMPTY_REPOSITORY,
        ]);

        $entries = [];

        foreach ((array) ($payload['tree'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entries[] = [
                'path' => (string) ($entry['path'] ?? ''),
                'type' => (string) ($entry['type'] ?? 'blob'),
                'hash' => (string) ($entry['sha'] ?? ''),
                'mode' => (string) ($entry['mode'] ?? ''),
            ];
        }

        return new RemoteTree((string) ($payload['sha'] ?? $hash), $entries);
    }

    public function getBlob(GitRemoteConfig $config, string $hash): ?RemoteBlob
    {
        $payload = $this->requestJson('GET', $config, '/repos/' . $this->repositoryPath($config) . '/git/blobs/' . $hash, 'get_blob', [
            404 => ProviderException::REPOSITORY_NOT_FOUND,
            409 => ProviderException::EMPTY_REPOSITORY,
        ]);

        $encoding = (string) ($payload['encoding'] ?? 'utf-8');
        $content = (string) ($payload['content'] ?? '');
        $normalizedContent = $encoding === 'base64'
            ? (string) base64_decode(str_replace("\n", '', $content), true)
            : $content;

        return new RemoteBlob((string) ($payload['sha'] ?? $hash), $normalizedContent);
    }

    public function createBlob(GitRemoteConfig $config, string $content): string
    {
        $payload = $this->requestJson('POST', $config, '/repos/' . $this->repositoryPath($config) . '/git/blobs', 'create_blob', [], [
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ]);

        return (string) ($payload['sha'] ?? '');
    }

    public function createTree(GitRemoteConfig $config, array $entries): string
    {
        if ($entries === []) {
            return self::EMPTY_TREE_HASH;
        }

        $normalizedEntries = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = (string) ($entry['type'] ?? 'blob');
            $normalizedEntries[] = [
                'path' => (string) ($entry['path'] ?? ''),
                'mode' => (string) ($entry['mode'] ?? ($type === 'tree' ? '040000' : '100644')),
                'type' => $type,
                'sha' => (string) ($entry['hash'] ?? $entry['sha'] ?? ''),
            ];
        }

        $payload = $this->requestJson('POST', $config, '/repos/' . $this->repositoryPath($config) . '/git/trees', 'create_tree', [
            409 => ProviderException::EMPTY_REPOSITORY,
        ], [
            'tree' => $normalizedEntries,
        ]);

        return (string) ($payload['sha'] ?? '');
    }

    public function createCommit(GitRemoteConfig $config, CreateRemoteCommitRequest $request): string
    {
        $payload = [
            'message' => $request->message,
            'tree' => $request->treeHash,
            'parents' => $request->parentHashes,
        ];

        if ($request->authorName !== '' && $request->authorEmail !== '') {
            $payload['author'] = [
                'name' => $request->authorName,
                'email' => $request->authorEmail,
            ];
            $payload['committer'] = [
                'name' => $request->authorName,
                'email' => $request->authorEmail,
            ];
        }

        $response = $this->requestJson('POST', $config, '/repos/' . $this->repositoryPath($config) . '/git/commits', 'create_commit', [
            409 => ProviderException::EMPTY_REPOSITORY,
        ], $payload);

        return (string) ($response['sha'] ?? '');
    }

    public function updateRef(GitRemoteConfig $config, UpdateRemoteRefRequest $request): UpdateRefResult
    {
        $payload = $this->requestJson(
            'PATCH',
            $config,
            '/repos/' . $this->repositoryPath($config) . '/git/refs/' . $this->normalizeRefPath($request->refName),
            'update_ref',
            [
                404 => ProviderException::REF_NOT_FOUND,
                409 => ProviderException::CONFLICT,
                422 => ProviderException::CONFLICT,
            ],
            [
                'sha' => $request->newCommitHash,
                'force' => false,
            ]
        );

        return new UpdateRefResult(
            true,
            (string) ($payload['ref'] ?? $request->refName),
            (string) ($payload['object']['sha'] ?? $request->newCommitHash)
        );
    }

    public function initializeEmptyRepository(GitRemoteConfig $config, string $commitMessage): RemoteRef
    {
        $path = '.pushpull-initialized';
        $payload = $this->requestJson(
            'PUT',
            $config,
            '/repos/' . $this->repositoryPath($config) . '/contents/' . rawurlencode($path),
            'initialize_empty_repository',
            [],
            [
                'message' => $commitMessage,
                'content' => base64_encode("Initialized by PushPull.\n"),
                'branch' => $config->branch,
            ]
        );

        $commitHash = (string) ($payload['commit']['sha'] ?? '');

        if ($commitHash === '') {
            throw new ProviderException(
                ProviderException::UNSUPPORTED_RESPONSE,
                'GitHub did not return a commit hash for repository initialization.'
            );
        }

        return new RemoteRef('refs/heads/' . $config->branch, $commitHash);
    }

    /**
     * @param array<int, string> $statusOverrides
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        GitRemoteConfig $config,
        string $path,
        string $operation,
        array $statusOverrides = [],
        ?array $json = null
    ): array {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->http()->send(new HttpRequest(
                    $method,
                    $this->apiBaseUrl($config) . $path,
                    $this->headers($config),
                    $json
                ));
            } catch (ProviderException $exception) {
                if ($attempt < self::MAX_ATTEMPTS && $this->shouldRetryException($exception)) {
                    continue;
                }

                throw new ProviderException(
                    $exception->category,
                    $exception->getMessage(),
                    $exception->statusCode,
                    $operation,
                    array_merge($exception->context, [
                        'repository' => $config->repositoryPath(),
                        'path' => $path,
                        'method' => $method,
                        'attempt' => $attempt,
                    ])
                );
            }

            $decoded = json_decode($response->body, true);
            $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : null;

            if ($response->statusCode >= 400) {
                if ($attempt < self::MAX_ATTEMPTS && $this->shouldRetryStatus($response->statusCode, $message)) {
                    continue;
                }

                $this->throwForStatus($response->statusCode, $message, $operation, $config, $path, $method, $statusOverrides);
            }

            if (! is_array($decoded)) {
                throw new ProviderException(
                    ProviderException::UNSUPPORTED_RESPONSE,
                    'GitHub returned an unsupported response shape.',
                    $response->statusCode
                );
            }

            return $decoded;
        }

        throw new ProviderException(ProviderException::TRANSPORT, 'GitHub request could not be completed.');
    }

    private function throwForStatus(
        int $statusCode,
        ?string $message,
        string $operation,
        GitRemoteConfig $config,
        string $path,
        string $method,
        array $statusOverrides
    ): never {
        $category = $statusOverrides[$statusCode] ?? match ($statusCode) {
            401 => ProviderException::AUTHENTICATION,
            403 => $this->isRateLimitMessage($message) ? ProviderException::RATE_LIMIT : ProviderException::AUTHORIZATION,
            404 => ProviderException::REPOSITORY_NOT_FOUND,
            409 => $this->isEmptyRepositoryMessage($message) ? ProviderException::EMPTY_REPOSITORY : ProviderException::CONFLICT,
            422 => ProviderException::VALIDATION,
            default => $statusCode >= 500 ? ProviderException::SERVICE_UNAVAILABLE : ProviderException::TRANSPORT,
        };

        throw new ProviderException(
            $category,
            $message ?? 'GitHub API request failed.',
            $statusCode,
            $operation,
            [
                'repository' => $config->repositoryPath(),
                'path' => $path,
                'method' => $method,
                'branch' => $config->branch,
            ]
        );
    }

    private function assertValidConfig(GitRemoteConfig $config): void
    {
        $validation = $this->validateConfig($config);

        if (! $validation->isValid()) {
            throw new ProviderException(
                ProviderException::VALIDATION,
                implode(' ', $validation->messages)
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(GitRemoteConfig $config): array
    {
        return [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $config->apiToken,
            'X-GitHub-Api-Version' => self::API_VERSION,
            'Content-Type' => 'application/json',
            'User-Agent' => 'PushPull/' . (defined('PUSHPULL_VERSION') ? PUSHPULL_VERSION : 'dev'),
        ];
    }

    private function apiBaseUrl(GitRemoteConfig $config): string
    {
        return rtrim($config->baseUrl ?? 'https://api.github.com', '/');
    }

    private function repositoryPath(GitRemoteConfig $config): string
    {
        return rawurlencode($config->ownerOrWorkspace) . '/' . rawurlencode($config->repository);
    }

    private function normalizeRefPath(string $refName): string
    {
        return ltrim(preg_replace('#^refs/#', '', $refName) ?? $refName, '/');
    }

    private function isEmptyRepositoryMessage(?string $message): bool
    {
        return is_string($message) && str_contains(strtolower($message), 'empty');
    }

    private function isRateLimitMessage(?string $message): bool
    {
        return is_string($message) && str_contains(strtolower($message), 'rate limit');
    }

    private function shouldRetryException(ProviderException $exception): bool
    {
        return in_array($exception->category, [ProviderException::TRANSPORT, ProviderException::RATE_LIMIT, ProviderException::SERVICE_UNAVAILABLE], true);
    }

    private function shouldRetryStatus(int $statusCode, ?string $message): bool
    {
        if ($statusCode === 429) {
            return true;
        }

        if ($statusCode === 403 && $this->isRateLimitMessage($message)) {
            return true;
        }

        return $statusCode >= 500;
    }

    private function http(): HttpTransportInterface
    {
        return $this->transport ?? new WordPressHttpTransport();
    }
}
