<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Domain\Repository\CommitRequest;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Repository\TreeEntry;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteTree;

final class DatabaseLocalRepositoryTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
    }

    public function testInitCreatesHeadAndBranchRefs(): void
    {
        $this->repository->init('main');

        self::assertTrue($this->repository->hasBeenInitialized('main'));
        self::assertNotNull($this->repository->getRef('HEAD'));
        self::assertSame('', $this->repository->getRef('refs/heads/main')?->commitHash);
    }

    public function testSameBlobContentIsDeduplicatedByHash(): void
    {
        $left = $this->repository->storeBlob('hello');
        $right = $this->repository->storeBlob('hello');

        self::assertSame($left->hash, $right->hash);
        self::assertSame('hello', $this->repository->getBlob($left->hash)?->content);
    }

    public function testTreeHashIgnoresInputOrderForEquivalentEntries(): void
    {
        $left = $this->repository->storeTree([
            new TreeEntry('b.json', 'blob', 'hash-b'),
            new TreeEntry('a.json', 'blob', 'hash-a'),
        ]);
        $right = $this->repository->storeTree([
            new TreeEntry('a.json', 'blob', 'hash-a'),
            new TreeEntry('b.json', 'blob', 'hash-b'),
        ]);

        self::assertSame($left->hash, $right->hash);
        self::assertSame(['a.json', 'b.json'], array_map(static fn (TreeEntry $entry): string => $entry->path, $left->entries));
    }

    public function testCommitPersistsAndCanBeResolvedFromBranchHead(): void
    {
        $this->repository->init('main');
        $tree = $this->repository->storeTree([
            new TreeEntry('generateblocks/global-styles/gbp-section.json', 'blob', 'blob-hash'),
        ]);
        $commit = $this->repository->commit(new CommitRequest(
            $tree->hash,
            null,
            null,
            'Jane Doe',
            'jane@example.com',
            'Initial import',
            ['managedSet' => 'generateblocks_global_styles']
        ));

        $this->repository->updateRef('refs/heads/main', $commit->hash);

        self::assertSame($commit->hash, $this->repository->getHeadCommit('main')?->hash);
        self::assertSame('Initial import', $this->repository->getCommit($commit->hash)?->message);
        self::assertSame(['managedSet' => 'generateblocks_global_styles'], $this->repository->getCommit($commit->hash)?->metadata);
    }

    public function testHeadCommitReturnsNullForEmptyBranch(): void
    {
        $this->repository->init('main');

        self::assertNull($this->repository->getHeadCommit('main'));
    }

    public function testRemoteObjectsCanBeImportedPreservingRemoteHashes(): void
    {
        $blob = $this->repository->importRemoteBlob(new RemoteBlob('remote-blob', 'remote-content'));
        $tree = $this->repository->importRemoteTree(new RemoteTree('remote-tree', [
            ['path' => 'generateblocks/global-styles/manifest.json', 'type' => 'blob', 'hash' => 'remote-blob'],
        ]));
        $commit = $this->repository->importRemoteCommit(new RemoteCommit('remote-commit', 'remote-tree', [], 'Fetched commit'));

        self::assertSame('remote-blob', $blob->hash);
        self::assertSame('remote-tree', $tree->hash);
        self::assertSame('remote-commit', $commit->hash);
        self::assertSame('remote-content', $this->repository->getBlob('remote-blob')?->content);
        self::assertSame('remote-tree', $this->repository->getCommit('remote-commit')?->treeHash);
    }
}
