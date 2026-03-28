<?php

declare(strict_types=1);

namespace PushPull\Provider\GitLab;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Provider\CreateRemoteCommitRequest;
use PushPull\Provider\Exception\ProviderException;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\Http\HttpRequest;
use PushPull\Provider\Http\HttpResponse;
use PushPull\Provider\Http\HttpTransportInterface;
use PushPull\Provider\Http\WordPressHttpTransport;
use PushPull\Provider\ProviderCapabilities;
use PushPull\Provider\ProviderConnectionResult;
use PushPull\Provider\ProviderValidationResult;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteRef;
use PushPull\Provider\RemoteTree;
use PushPull\Provider\UpdateRefResult;
use PushPull\Provider\UpdateRemoteRefRequest;
use PushPull\Support\Json\CanonicalJson;

final class GitLabProvider implements GitProviderInterface
{
    private const EMPTY_TREE_HASH = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';
    private const ROOT_TREE_PREFIX = 'gitlab-root-tree-';
    private const MAX_ATTEMPTS = 3;

    /** @var array<string, string> */
    private array $stagedBlobs = [];

    /** @var array<string, array<int, array<string, string>>> */
    private array $stagedTrees = [];

    /** @var array<string, CreateRemoteCommitRequest> */
    private array $stagedCommits = [];

    /** @var array<string, array<int, array<string, string>>> */
    private array $registeredTrees = [];

    /** @var array<string, array<string, string>> */
    private array $materializedTreeFiles = [];

    public function __construct(private readonly ?HttpTransportInterface $transport = null)
    {
    }

    public function getKey(): string
    {
        return 'gitlab';
    }

    public function getLabel(): string
    {
        return 'GitLab';
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(true, false, false, true);
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
        $project = $this->projectMetadata($config);
        $defaultBranch = is_string($project['default_branch'] ?? null) ? $project['default_branch'] : null;
        $resolvedBranch = $config->branch !== '' ? $config->branch : $defaultBranch;

        if ($resolvedBranch === null || $resolvedBranch === '') {
            throw new ProviderException(
                ProviderException::UNSUPPORTED_RESPONSE,
                'GitLab project metadata did not include a default branch.'
            );
        }

        if (! empty($project['empty_repo'])) {
            return new ProviderConnectionResult(
                true,
                $config->repositoryPath(),
                $defaultBranch,
                $resolvedBranch,
                true,
                ['GitLab repository is reachable but empty and needs an initial commit before fetch or push can run.']
            );
        }

        $this->getRef($config, 'refs/heads/' . $resolvedBranch);

        return new ProviderConnectionResult(
            true,
            $config->repositoryPath(),
            $defaultBranch,
            $resolvedBranch,
            false,
            ['GitLab repository metadata and target branch are accessible.']
        );
    }

    public function getRef(GitRemoteConfig $config, string $refName): ?RemoteRef
    {
        $branch = $this->branchNameFromRef($refName);
        $payload = $this->requestJson(
            'GET',
            $config,
            '/projects/' . $this->projectPath($config) . '/repository/branches/' . rawurlencode($branch),
            'get_ref',
            [
                404 => ProviderException::REF_NOT_FOUND,
            ]
        );

        return new RemoteRef(
            'refs/heads/' . (string) ($payload['name'] ?? $branch),
            (string) (($payload['commit']['id'] ?? $payload['commit']['sha']) ?? '')
        );
    }

    public function getDefaultBranch(GitRemoteConfig $config): ?string
    {
        $project = $this->projectMetadata($config);

        return is_string($project['default_branch'] ?? null) ? $project['default_branch'] : null;
    }

    public function getCommit(GitRemoteConfig $config, string $hash): ?RemoteCommit
    {
        $payload = $this->requestJson(
            'GET',
            $config,
            '/projects/' . $this->projectPath($config) . '/repository/commits/' . rawurlencode($hash),
            'get_commit',
            [
                404 => ProviderException::REPOSITORY_NOT_FOUND,
            ]
        );
        $treeHash = $this->registerCommitTrees($config, (string) ($payload['id'] ?? $hash));

        return new RemoteCommit(
            (string) ($payload['id'] ?? $hash),
            $treeHash,
            array_values(array_filter(array_map('strval', (array) ($payload['parent_ids'] ?? [])), static fn (string $parent): bool => $parent !== '')),
            (string) ($payload['message'] ?? ''),
            $this->personPayload('name', 'email', $payload, 'author_'),
            $this->personPayload('name', 'email', $payload, 'committer_')
        );
    }

    public function getTree(GitRemoteConfig $config, string $hash): ?RemoteTree
    {
        if ($hash === self::EMPTY_TREE_HASH) {
            return new RemoteTree(self::EMPTY_TREE_HASH, []);
        }

        if (! isset($this->registeredTrees[$hash]) && str_starts_with($hash, self::ROOT_TREE_PREFIX)) {
            $this->registerCommitTrees($config, substr($hash, strlen(self::ROOT_TREE_PREFIX)));
        }

        if (! isset($this->registeredTrees[$hash])) {
            throw new ProviderException(
                ProviderException::REPOSITORY_NOT_FOUND,
                sprintf('Remote tree "%s" could not be loaded.', $hash)
            );
        }

        return new RemoteTree($hash, $this->registeredTrees[$hash]);
    }

    public function getBlob(GitRemoteConfig $config, string $hash): ?RemoteBlob
    {
        $response = $this->requestResponse(
            'GET',
            $config,
            '/projects/' . $this->projectPath($config) . '/repository/blobs/' . rawurlencode($hash) . '/raw',
            'get_blob',
            [
                404 => ProviderException::REPOSITORY_NOT_FOUND,
            ]
        );

        return new RemoteBlob($hash, $response->body);
    }

    public function createBlob(GitRemoteConfig $config, string $content): string
    {
        $hash = 'gitlab-staged-blob-' . sha1($content);
        $this->stagedBlobs[$hash] = $content;

        return $hash;
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
                'type' => $type,
                'hash' => (string) ($entry['hash'] ?? $entry['sha'] ?? ''),
                'mode' => (string) ($entry['mode'] ?? ($type === 'tree' ? '040000' : '100644')),
            ];
        }

        usort(
            $normalizedEntries,
            static fn (array $left, array $right): int => strcmp(($left['path'] ?? '') . ':' . ($left['type'] ?? ''), ($right['path'] ?? '') . ':' . ($right['type'] ?? ''))
        );

        $hash = 'gitlab-staged-tree-' . sha1(CanonicalJson::encode($normalizedEntries));
        $this->stagedTrees[$hash] = $normalizedEntries;

        return $hash;
    }

    public function createCommit(GitRemoteConfig $config, CreateRemoteCommitRequest $request): string
    {
        $hash = 'gitlab-staged-commit-' . sha1(CanonicalJson::encode([
            'tree' => $request->treeHash,
            'parents' => $request->parentHashes,
            'message' => $request->message,
            'authorName' => $request->authorName,
            'authorEmail' => $request->authorEmail,
        ]));
        $this->stagedCommits[$hash] = $request;

        return $hash;
    }

    public function updateRef(GitRemoteConfig $config, UpdateRemoteRefRequest $request): UpdateRefResult
    {
        $branch = $this->branchNameFromRef($request->refName);
        $currentRemoteRef = $this->getRef($config, 'refs/heads/' . $branch);

        if ($currentRemoteRef === null || $currentRemoteRef->commitHash === '') {
            throw new ProviderException(
                ProviderException::REF_NOT_FOUND,
                sprintf('Remote branch %s does not exist or cannot be updated safely.', $branch)
            );
        }

        if ($request->expectedOldCommitHash !== null && $request->expectedOldCommitHash !== '' && $currentRemoteRef->commitHash !== $request->expectedOldCommitHash) {
            throw new ProviderException(
                ProviderException::CONFLICT,
                sprintf('Remote branch %s has changed since the last fetch.', $branch)
            );
        }

        $commitOrder = [];
        $this->collectStagedCommitOrder($request->newCommitHash, $request->expectedOldCommitHash, [], $commitOrder);
        $currentHeadHash = $currentRemoteRef->commitHash;
        $currentFiles = $this->readFilesForCommit($config, $currentHeadHash);

        foreach ($commitOrder as $stagedCommitHash) {
            $stagedCommit = $this->stagedCommits[$stagedCommitHash] ?? null;

            if ($stagedCommit === null) {
                throw new ProviderException(
                    ProviderException::UNSUPPORTED_RESPONSE,
                    sprintf('GitLab push staging is missing commit %s.', $stagedCommitHash)
                );
            }

            $targetFiles = $this->materializeTreeFiles($config, $stagedCommit->treeHash);
            $actions = $this->buildCommitActions($currentFiles, $targetFiles);

            if ($actions === []) {
                $this->stagedCommits[$stagedCommitHash] = $stagedCommit;
                continue;
            }

            $payload = [
                'branch' => $branch,
                'commit_message' => $stagedCommit->message,
                'actions' => $actions,
                'force' => false,
                'stats' => false,
            ];

            if ($stagedCommit->authorName !== '') {
                $payload['author_name'] = $stagedCommit->authorName;
            }

            if ($stagedCommit->authorEmail !== '') {
                $payload['author_email'] = $stagedCommit->authorEmail;
            }

            $response = $this->requestJson(
                'POST',
                $config,
                '/projects/' . $this->projectPath($config) . '/repository/commits',
                'update_ref',
                [
                    400 => ProviderException::VALIDATION,
                    404 => ProviderException::REF_NOT_FOUND,
                    409 => ProviderException::CONFLICT,
                ],
                $payload
            );
            $currentHeadHash = (string) ($response['id'] ?? $response['sha'] ?? '');

            if ($currentHeadHash === '') {
                throw new ProviderException(
                    ProviderException::UNSUPPORTED_RESPONSE,
                    'GitLab did not return a commit hash after creating a commit.'
                );
            }

            $currentFiles = $targetFiles;
        }

        return new UpdateRefResult(true, 'refs/heads/' . $branch, $currentHeadHash);
    }

    public function initializeEmptyRepository(GitRemoteConfig $config, string $commitMessage): RemoteRef
    {
        $path = '.pushpull-initialized';
        $payload = $this->requestJson(
            'POST',
            $config,
            '/projects/' . $this->projectPath($config) . '/repository/commits',
            'initialize_empty_repository',
            [
                400 => ProviderException::VALIDATION,
            ],
            [
                'branch' => $config->branch,
                'commit_message' => $commitMessage,
                'actions' => [[
                    'action' => 'create',
                    'file_path' => $path,
                    'content' => "Initialized by PushPull.\n",
                    'encoding' => 'text',
                ]],
                'stats' => false,
            ]
        );
        $commitHash = (string) ($payload['id'] ?? $payload['sha'] ?? '');

        if ($commitHash === '') {
            throw new ProviderException(
                ProviderException::UNSUPPORTED_RESPONSE,
                'GitLab did not return a commit hash for repository initialization.'
            );
        }

        return new RemoteRef('refs/heads/' . $config->branch, $commitHash);
    }

    /**
     * @param array<string, string> $payload
     * @return array<string, string>|null
     */
    private function personPayload(string $nameKey, string $emailKey, array $payload, string $prefix): ?array
    {
        $name = (string) ($payload[$prefix . $nameKey] ?? '');
        $email = (string) ($payload[$prefix . $emailKey] ?? '');

        if ($name === '' && $email === '') {
            return null;
        }

        return array_filter([
            'name' => $name,
            'email' => $email,
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @param array<string, string> $currentFiles
     * @param array<string, string> $targetFiles
     * @return array<int, array<string, string>>
     */
    private function buildCommitActions(array $currentFiles, array $targetFiles): array
    {
        $actions = [];

        foreach ($currentFiles as $path => $content) {
            if (! array_key_exists($path, $targetFiles)) {
                $actions[] = [
                    'action' => 'delete',
                    'file_path' => $path,
                ];
            }
        }

        foreach ($targetFiles as $path => $content) {
            $action = array_key_exists($path, $currentFiles) ? 'update' : 'create';

            if ($action === 'update' && $currentFiles[$path] === $content) {
                continue;
            }

            $actions[] = [
                'action' => $action,
                'file_path' => $path,
                'content' => base64_encode($content),
                'encoding' => 'base64',
            ];
        }

        usort(
            $actions,
            static fn (array $left, array $right): int => strcmp(($left['file_path'] ?? '') . ':' . ($left['action'] ?? ''), ($right['file_path'] ?? '') . ':' . ($right['action'] ?? ''))
        );

        return $actions;
    }

    /**
     * @param array<string, true> $seen
     * @param string[] $order
     */
    private function collectStagedCommitOrder(string $hash, ?string $stopAtHash, array $seen, array &$order): void
    {
        if ($hash === $stopAtHash || isset($seen[$hash])) {
            return;
        }

        $seen[$hash] = true;
        $commit = $this->stagedCommits[$hash] ?? null;

        if ($commit === null) {
            return;
        }

        $parentHash = $commit->parentHashes[0] ?? null;

        if (is_string($parentHash) && $parentHash !== '') {
            $this->collectStagedCommitOrder($parentHash, $stopAtHash, $seen, $order);
        }

        $order[] = $hash;
    }

    /**
     * @return array<string, string>
     */
    private function materializeTreeFiles(GitRemoteConfig $config, string $treeHash): array
    {
        if (isset($this->materializedTreeFiles[$treeHash])) {
            return $this->materializedTreeFiles[$treeHash];
        }

        if ($treeHash === self::EMPTY_TREE_HASH) {
            $this->materializedTreeFiles[$treeHash] = [];

            return [];
        }

        if (isset($this->stagedTrees[$treeHash])) {
            $files = [];

            foreach ($this->stagedTrees[$treeHash] as $entry) {
                $path = (string) ($entry['path'] ?? '');
                $entryHash = (string) ($entry['hash'] ?? '');

                if (($entry['type'] ?? 'blob') === 'tree') {
                    foreach ($this->materializeTreeFiles($config, $entryHash) as $childPath => $content) {
                        $files[$path . '/' . $childPath] = $content;
                    }

                    continue;
                }

                $files[$path] = $this->materializeBlobContent($config, $entryHash);
            }

            ksort($files);
            $this->materializedTreeFiles[$treeHash] = $files;

            return $files;
        }

        $files = [];
        $tree = $this->getTree($config, $treeHash);

        if ($tree === null) {
            throw new ProviderException(
                ProviderException::REPOSITORY_NOT_FOUND,
                sprintf('Remote tree %s could not be loaded.', $treeHash)
            );
        }

        foreach ($tree->entries as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $entryHash = (string) ($entry['hash'] ?? '');

            if (($entry['type'] ?? 'blob') === 'tree') {
                foreach ($this->materializeTreeFiles($config, $entryHash) as $childPath => $content) {
                    $files[$path . '/' . $childPath] = $content;
                }

                continue;
            }

            $blob = $this->getBlob($config, $entryHash);
            $files[$path] = $blob?->content ?? '';
        }

        ksort($files);
        $this->materializedTreeFiles[$treeHash] = $files;

        return $files;
    }

    private function materializeBlobContent(GitRemoteConfig $config, string $hash): string
    {
        if (isset($this->stagedBlobs[$hash])) {
            return $this->stagedBlobs[$hash];
        }

        $blob = $this->getBlob($config, $hash);

        if ($blob === null) {
            throw new ProviderException(
                ProviderException::REPOSITORY_NOT_FOUND,
                sprintf('Remote blob %s could not be loaded.', $hash)
            );
        }

        return $blob->content;
    }

    /**
     * @return array<string, string>
     */
    private function readFilesForCommit(GitRemoteConfig $config, string $commitHash): array
    {
        $commit = $this->getCommit($config, $commitHash);

        if ($commit === null) {
            throw new ProviderException(
                ProviderException::REPOSITORY_NOT_FOUND,
                sprintf('Remote commit %s could not be loaded.', $commitHash)
            );
        }

        return $this->materializeTreeFiles($config, $commit->treeHash);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectMetadata(GitRemoteConfig $config): array
    {
        return $this->requestJson(
            'GET',
            $config,
            '/projects/' . $this->projectPath($config),
            'get_project',
            [
                404 => ProviderException::REPOSITORY_NOT_FOUND,
            ]
        );
    }

    /**
     * @param array<int, string> $statusOverrides
     * @param array<string, mixed>|null $json
     */
    private function requestResponse(
        string $method,
        GitRemoteConfig $config,
        string $path,
        string $operation,
        array $statusOverrides = [],
        ?array $json = null
    ): HttpResponse {
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

            $message = $this->extractErrorMessage($response->body);

            if ($response->statusCode >= 400) {
                if ($attempt < self::MAX_ATTEMPTS && $this->shouldRetryStatus($response->statusCode, $message)) {
                    continue;
                }

                $this->throwForStatus($response->statusCode, $message, $operation, $config, $path, $method, $statusOverrides);
            }

            return $response;
        }

        throw new ProviderException(ProviderException::TRANSPORT, 'GitLab request could not be completed.');
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
        $response = $this->requestResponse($method, $config, $path, $operation, $statusOverrides, $json);
        $decoded = json_decode($response->body, true);

        if (! is_array($decoded)) {
            throw new ProviderException(
                ProviderException::UNSUPPORTED_RESPONSE,
                'GitLab returned an unsupported response shape.',
                $response->statusCode
            );
        }

        return $decoded;
    }

    /**
     * @param array<int, string> $statusOverrides
     */
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
            400 => $this->isEmptyRepositoryMessage($message) ? ProviderException::EMPTY_REPOSITORY : ProviderException::VALIDATION,
            401 => ProviderException::AUTHENTICATION,
            403 => $this->isRateLimitMessage($message) ? ProviderException::RATE_LIMIT : ProviderException::AUTHORIZATION,
            404 => ProviderException::REPOSITORY_NOT_FOUND,
            409 => ProviderException::CONFLICT,
            default => $statusCode >= 500 ? ProviderException::SERVICE_UNAVAILABLE : ProviderException::TRANSPORT,
        };

        throw new ProviderException(
            $category,
            $message ?? 'GitLab API request failed.',
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
            'Accept' => 'application/json',
            'PRIVATE-TOKEN' => $config->apiToken,
            'Content-Type' => 'application/json',
            'User-Agent' => 'PushPull/' . (defined('PUSHPULL_VERSION') ? PUSHPULL_VERSION : 'dev'),
        ];
    }

    private function apiBaseUrl(GitRemoteConfig $config): string
    {
        $baseUrl = rtrim($config->baseUrl ?? 'https://gitlab.com', '/');

        if (preg_match('#/api/v\d+$#', $baseUrl) === 1) {
            return $baseUrl;
        }

        return $baseUrl . '/api/v4';
    }

    private function projectPath(GitRemoteConfig $config): string
    {
        return rawurlencode($config->repositoryPath());
    }

    private function branchNameFromRef(string $refName): string
    {
        return preg_replace('#^refs/heads/#', '', $refName) ?? $refName;
    }

    private function registerCommitTrees(GitRemoteConfig $config, string $commitHash): string
    {
        $rootTreeHash = self::ROOT_TREE_PREFIX . $commitHash;

        if (isset($this->registeredTrees[$rootTreeHash])) {
            return $rootTreeHash;
        }

        $this->registerTreeSnapshot($config, $commitHash, $rootTreeHash);

        return $rootTreeHash;
    }

    private function registerTreeSnapshot(GitRemoteConfig $config, string $ref, string $rootTreeHash): void
    {
        if (isset($this->registeredTrees[$rootTreeHash])) {
            return;
        }

        $entries = $this->requestRecursiveTreeEntries($config, $ref);
        $flattenedEntries = [];

        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') !== 'blob') {
                continue;
            }

            $flattenedEntries[] = [
                'path' => (string) ($entry['path'] ?? ''),
                'type' => 'blob',
                'hash' => (string) ($entry['id'] ?? ''),
                'mode' => (string) ($entry['mode'] ?? ''),
            ];
        }

        usort(
            $flattenedEntries,
            static fn (array $left, array $right): int => strcmp(($left['path'] ?? '') . ':' . ($left['type'] ?? ''), ($right['path'] ?? '') . ':' . ($right['type'] ?? ''))
        );

        $this->registeredTrees[$rootTreeHash] = $flattenedEntries;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function requestRecursiveTreeEntries(GitRemoteConfig $config, string $ref): array
    {
        $entries = [];
        $page = 1;

        do {
            $response = $this->requestResponse(
                'GET',
                $config,
                '/projects/' . $this->projectPath($config) . '/repository/tree?ref=' . rawurlencode($ref) . '&recursive=true&per_page=100&page=' . $page,
                'get_tree',
                [
                    404 => ProviderException::REPOSITORY_NOT_FOUND,
                ]
            );
            $decoded = json_decode($response->body, true);

            if (! is_array($decoded)) {
                throw new ProviderException(
                    ProviderException::UNSUPPORTED_RESPONSE,
                    'GitLab returned an unsupported tree response shape.'
                );
            }

            foreach ($decoded as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entries[] = [
                    'path' => (string) ($entry['path'] ?? ''),
                    'type' => (string) ($entry['type'] ?? 'blob'),
                    'id' => (string) ($entry['id'] ?? ''),
                    'mode' => (string) ($entry['mode'] ?? ''),
                ];
            }

            $page = (int) ($response->headers['X-Next-Page'] ?? $response->headers['x-next-page'] ?? 0);
        } while ($page > 0);

        return $entries;
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

    private function extractErrorMessage(string $body): ?string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded) && is_string($decoded['message'] ?? null)) {
            return $decoded['message'];
        }

        $plainText = trim(wp_strip_all_tags($body));

        if ($plainText === '') {
            return null;
        }

        return preg_replace('/\s+/', ' ', $plainText) ?: null;
    }

    private function http(): HttpTransportInterface
    {
        return $this->transport ?? new WordPressHttpTransport();
    }
}
