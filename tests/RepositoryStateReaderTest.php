<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteTree;

final class RepositoryStateReaderTest extends TestCase
{
    public function testReadCommitRecursivelyResolvesNestedRemoteTrees(): void
    {
        $repository = new DatabaseLocalRepository(new \wpdb());
        $repository->importRemoteBlob(new RemoteBlob('blob-style', "{\n  \"type\": \"generateblocks_global_style\"\n}\n"));
        $repository->importRemoteBlob(new RemoteBlob('blob-manifest', "{\n  \"type\": \"generateblocks_global_styles_manifest\"\n}\n"));
        $repository->importRemoteTree(new RemoteTree('tree-global-styles', [
            ['path' => 'gbp-section.json', 'type' => 'blob', 'hash' => 'blob-style'],
            ['path' => 'manifest.json', 'type' => 'blob', 'hash' => 'blob-manifest'],
        ]));
        $repository->importRemoteTree(new RemoteTree('tree-generateblocks', [
            ['path' => 'global-styles', 'type' => 'tree', 'hash' => 'tree-global-styles'],
        ]));
        $repository->importRemoteTree(new RemoteTree('tree-root', [
            ['path' => 'generateblocks', 'type' => 'tree', 'hash' => 'tree-generateblocks'],
        ]));
        $repository->importRemoteCommit(new RemoteCommit('commit-1', 'tree-root', [], 'Remote commit'));

        $state = (new RepositoryStateReader($repository))->readCommit('local', 'commit-1');

        self::assertArrayHasKey('generateblocks/global-styles/gbp-section.json', $state->files);
        self::assertArrayHasKey('generateblocks/global-styles/manifest.json', $state->files);
        self::assertSame(
            "{\n  \"type\": \"generateblocks_global_styles_manifest\"\n}\n",
            $state->files['generateblocks/global-styles/manifest.json']->content
        );
    }

    public function testReadCommitIgnoresBootstrapMarkerFiles(): void
    {
        $repository = new DatabaseLocalRepository(new \wpdb());
        $repository->importRemoteBlob(new RemoteBlob('blob-init', "Initialized by PushPull.\n"));
        $repository->importRemoteBlob(new RemoteBlob('blob-style', "{\n  \"type\": \"generateblocks_global_style\"\n}\n"));
        $repository->importRemoteTree(new RemoteTree('tree-root', [
            ['path' => '.pushpull-initialized', 'type' => 'blob', 'hash' => 'blob-init'],
            ['path' => 'generateblocks/global-styles/gbp-section.json', 'type' => 'blob', 'hash' => 'blob-style'],
        ]));
        $repository->importRemoteCommit(new RemoteCommit('commit-1', 'tree-root', [], 'Remote commit'));

        $state = (new RepositoryStateReader($repository))->readCommit('local', 'commit-1');

        self::assertArrayNotHasKey('.pushpull-initialized', $state->files);
        self::assertArrayHasKey('generateblocks/global-styles/gbp-section.json', $state->files);
    }
}
