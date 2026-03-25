<?php

declare(strict_types=1);

namespace PushPull\Domain\Repository;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are internal constants, values still use prepare().

use PushPull\Persistence\TableNames;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteTree;
use PushPull\Support\Json\CanonicalJson;
use wpdb;

final class DatabaseLocalRepository implements LocalRepositoryInterface
{
    private readonly TableNames $tables;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->tables = new TableNames($wpdb->prefix);
    }

    public function init(string $defaultBranch): void
    {
        $this->upsertRef($this->branchRefName($defaultBranch), '');
        $this->upsertRef('HEAD', '');
    }

    public function hasBeenInitialized(string $branch): bool
    {
        return $this->getRef($this->branchRefName($branch)) !== null;
    }

    public function storeBlob(string $content): Blob
    {
        $hash = sha1($content);
        $existing = $this->getBlob($hash);

        if ($existing !== null) {
            return $existing;
        }

        $now = $this->now();

        $this->wpdb->insert(
            $this->tables->repoBlobs(),
            [
                'hash' => $hash,
                'content' => $content,
                'size' => strlen($content),
                'created_at' => $now,
            ],
            ['%s', '%s', '%d', '%s']
        );

        return new Blob($hash, $content, strlen($content), $now);
    }

    public function getBlob(string $hash): ?Blob
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT hash, content, size, created_at FROM {$this->tables->repoBlobs()} WHERE hash = %s",
                $hash
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        return new Blob(
            (string) $row['hash'],
            (string) $row['content'],
            (int) $row['size'],
            (string) $row['created_at']
        );
    }

    public function importRemoteBlob(RemoteBlob $remoteBlob): Blob
    {
        $existing = $this->getBlob($remoteBlob->hash);

        if ($existing !== null) {
            return $existing;
        }

        $now = $this->now();
        $this->wpdb->insert(
            $this->tables->repoBlobs(),
            [
                'hash' => $remoteBlob->hash,
                'content' => $remoteBlob->content,
                'size' => strlen($remoteBlob->content),
                'created_at' => $now,
            ],
            ['%s', '%s', '%d', '%s']
        );

        return new Blob($remoteBlob->hash, $remoteBlob->content, strlen($remoteBlob->content), $now);
    }

    public function storeTree(array $entries): Tree
    {
        usort(
            $entries,
            static fn (TreeEntry $left, TreeEntry $right): int => [$left->path, $left->type, $left->hash] <=> [$right->path, $right->type, $right->hash]
        );

        $payload = array_map(static fn (TreeEntry $entry): array => $entry->toArray(), $entries);
        $treeJson = CanonicalJson::encode($payload);
        $hash = sha1($treeJson);
        $existing = $this->getTree($hash);

        if ($existing !== null) {
            return $existing;
        }

        $now = $this->now();

        $this->wpdb->insert(
            $this->tables->repoTrees(),
            [
                'hash' => $hash,
                'tree_json' => $treeJson,
                'created_at' => $now,
            ],
            ['%s', '%s', '%s']
        );

        return new Tree($hash, $entries, $now);
    }

    public function getTree(string $hash): ?Tree
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT hash, tree_json, created_at FROM {$this->tables->repoTrees()} WHERE hash = %s",
                $hash
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        $entries = [];
        $decoded = json_decode((string) $row['tree_json'], true);

        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $entries[] = new TreeEntry(
                    (string) ($entry['path'] ?? ''),
                    (string) ($entry['type'] ?? 'blob'),
                    (string) ($entry['hash'] ?? '')
                );
            }
        }

        return new Tree(
            (string) $row['hash'],
            $entries,
            (string) $row['created_at']
        );
    }

    public function importRemoteTree(RemoteTree $remoteTree): Tree
    {
        $existing = $this->getTree($remoteTree->hash);

        if ($existing !== null) {
            return $existing;
        }

        $now = $this->now();
        $treeJson = CanonicalJson::encode($remoteTree->entries);
        $this->wpdb->insert(
            $this->tables->repoTrees(),
            [
                'hash' => $remoteTree->hash,
                'tree_json' => $treeJson,
                'created_at' => $now,
            ],
            ['%s', '%s', '%s']
        );

        $entries = [];

        foreach ($remoteTree->entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entries[] = new TreeEntry(
                (string) ($entry['path'] ?? ''),
                (string) ($entry['type'] ?? 'blob'),
                (string) ($entry['hash'] ?? '')
            );
        }

        return new Tree($remoteTree->hash, $entries, $now);
    }

    public function commit(CommitRequest $request): Commit
    {
        $now = $this->now();
        $payload = [
            'tree_hash' => $request->treeHash,
            'parent_hash' => $request->parentHash,
            'second_parent_hash' => $request->secondParentHash,
            'author_name' => $request->authorName,
            'author_email' => $request->authorEmail,
            'message' => $request->message,
            'committed_at' => $now,
            'metadata' => $request->metadata,
        ];
        $metadataJson = CanonicalJson::encode($request->metadata);
        $hash = sha1(CanonicalJson::encode($payload));
        $existing = $this->getCommit($hash);

        if ($existing !== null) {
            return $existing;
        }

        $this->wpdb->insert(
            $this->tables->repoCommits(),
            [
                'hash' => $hash,
                'tree_hash' => $request->treeHash,
                'parent_hash' => $request->parentHash,
                'second_parent_hash' => $request->secondParentHash,
                'author_name' => $request->authorName,
                'author_email' => $request->authorEmail,
                'message' => $request->message,
                'committed_at' => $now,
                'metadata_json' => $metadataJson,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return new Commit(
            $hash,
            $request->treeHash,
            $request->parentHash,
            $request->secondParentHash,
            $request->authorName,
            $request->authorEmail,
            $request->message,
            $now,
            $request->metadata
        );
    }

    public function getCommit(string $hash): ?Commit
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT hash, tree_hash, parent_hash, second_parent_hash, author_name, author_email, message, committed_at, metadata_json
                 FROM {$this->tables->repoCommits()} WHERE hash = %s",
                $hash
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        $metadata = json_decode((string) ($row['metadata_json'] ?? '[]'), true);

        return new Commit(
            (string) $row['hash'],
            (string) $row['tree_hash'],
            $row['parent_hash'] !== null ? (string) $row['parent_hash'] : null,
            $row['second_parent_hash'] !== null ? (string) $row['second_parent_hash'] : null,
            (string) $row['author_name'],
            (string) $row['author_email'],
            (string) $row['message'],
            (string) $row['committed_at'],
            is_array($metadata) ? $metadata : []
        );
    }

    public function importRemoteCommit(RemoteCommit $remoteCommit): Commit
    {
        $existing = $this->getCommit($remoteCommit->hash);

        if ($existing !== null) {
            return $existing;
        }

        $now = $this->now();
        $this->wpdb->insert(
            $this->tables->repoCommits(),
            [
                'hash' => $remoteCommit->hash,
                'tree_hash' => $remoteCommit->treeHash,
                'parent_hash' => $remoteCommit->parents[0] ?? null,
                'second_parent_hash' => $remoteCommit->parents[1] ?? null,
                'author_name' => 'Remote',
                'author_email' => '',
                'message' => $remoteCommit->message,
                'committed_at' => $now,
                'metadata_json' => CanonicalJson::encode([
                    'source' => 'remote',
                    'remoteHash' => $remoteCommit->hash,
                ]),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return new Commit(
            $remoteCommit->hash,
            $remoteCommit->treeHash,
            $remoteCommit->parents[0] ?? null,
            $remoteCommit->parents[1] ?? null,
            'Remote',
            '',
            $remoteCommit->message,
            $now,
            [
                'source' => 'remote',
                'remoteHash' => $remoteCommit->hash,
            ]
        );
    }

    public function updateRef(string $refName, string $commitHash): Ref
    {
        $now = $this->now();
        $this->upsertRef($refName, $commitHash, $now);

        return new Ref($refName, $commitHash, $now);
    }

    public function getRef(string $refName): ?Ref
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT ref_name, commit_hash, updated_at FROM {$this->tables->repoRefs()} WHERE ref_name = %s",
                $refName
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        return new Ref(
            (string) $row['ref_name'],
            (string) $row['commit_hash'],
            (string) $row['updated_at']
        );
    }

    public function getHeadCommit(string $branch): ?Commit
    {
        $ref = $this->getRef($this->branchRefName($branch));

        if ($ref === null || $ref->commitHash === '') {
            return null;
        }

        return $this->getCommit($ref->commitHash);
    }

    private function upsertRef(string $refName, string $commitHash, ?string $updatedAt = null): void
    {
        $this->wpdb->replace(
            $this->tables->repoRefs(),
            [
                'ref_name' => $refName,
                'commit_hash' => $commitHash,
                'updated_at' => $updatedAt ?? $this->now(),
            ],
            ['%s', '%s', '%s']
        );
    }

    private function branchRefName(string $branch): string
    {
        return 'refs/heads/' . $branch;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
