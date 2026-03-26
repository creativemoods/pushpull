<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\GenerateBlocksConditionsAdapter;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;

final class GenerateBlocksRepositoryCommitterTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private GenerateBlocksGlobalStylesAdapter $adapter;
    private GenerateBlocksConditionsAdapter $conditionsAdapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetRepositoryCommitter $conditionsCommitter;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new GenerateBlocksGlobalStylesAdapter();
        $this->conditionsAdapter = new GenerateBlocksConditionsAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->conditionsCommitter = new ManagedSetRepositoryCommitter($this->repository, $this->conditionsAdapter);
    }

    public function testCommitSnapshotCreatesTreeCommitAndRefs(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 1, ['borderTopWidth' => '1px']),
        ]);

        $result = $this->committer->commitSnapshot($snapshot, new CommitManagedSetRequest(
            'main',
            'Export GenerateBlocks global styles',
            'Jane Doe',
            'jane@example.com'
        ));

        self::assertTrue($result->initializedRepository);
        self::assertTrue($result->createdNewCommit);
        self::assertNotNull($result->commit);
        self::assertNotNull($result->tree);
        self::assertCount(3, $result->pathHashes);
        self::assertArrayHasKey('generateblocks/global-styles/gbp-section.json', $result->pathHashes);
        self::assertArrayHasKey('generateblocks/global-styles/gbp-card.json', $result->pathHashes);
        self::assertArrayHasKey('generateblocks/global-styles/manifest.json', $result->pathHashes);
        self::assertSame($result->commit?->hash, $this->repository->getRef('refs/heads/main')?->commitHash);
        self::assertSame($result->commit?->hash, $this->repository->getRef('HEAD')?->commitHash);
        self::assertSame($result->commit?->hash, $this->repository->getHeadCommit('main')?->hash);
    }

    public function testNoOpCommitDoesNotCreateDuplicateCommit(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);

        $first = $this->committer->commitSnapshot($snapshot, new CommitManagedSetRequest(
            'main',
            'Initial export',
            'Jane Doe',
            'jane@example.com'
        ));
        $second = $this->committer->commitSnapshot($snapshot, new CommitManagedSetRequest(
            'main',
            'Repeat export',
            'Jane Doe',
            'jane@example.com'
        ));

        self::assertTrue($first->createdNewCommit);
        self::assertFalse($second->createdNewCommit);
        self::assertSame($first->commit?->hash, $second->commit?->hash);
        self::assertFalse($second->initializedRepository);
    }

    public function testReorderedSnapshotProducesNewCommitBecauseManifestChanges(): void
    {
        $firstSnapshot = $this->adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 1),
        ]);
        $secondSnapshot = $this->adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 1),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 0),
        ]);

        $first = $this->committer->commitSnapshot($firstSnapshot, new CommitManagedSetRequest(
            'main',
            'Initial export',
            'Jane Doe',
            'jane@example.com'
        ));
        $second = $this->committer->commitSnapshot($secondSnapshot, new CommitManagedSetRequest(
            'main',
            'Reordered export',
            'Jane Doe',
            'jane@example.com'
        ));

        self::assertTrue($second->createdNewCommit);
        self::assertNotSame($first->commit?->hash, $second->commit?->hash);
        self::assertNotSame($first->pathHashes['generateblocks/global-styles/manifest.json'], $second->pathHashes['generateblocks/global-styles/manifest.json']);
    }

    public function testCommittingDifferentManagedSetPreservesExistingBranchFiles(): void
    {
        $stylesSnapshot = $this->adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);
        $conditionsSnapshot = $this->conditionsAdapter->snapshotFromRuntimeRecords([
            $this->conditionsRuntimeRecord('is_event', 'is_event', 0, ['logic' => 'OR', 'groups' => []]),
        ]);

        $this->committer->commitSnapshot($stylesSnapshot, new CommitManagedSetRequest(
            'main',
            'Commit styles',
            'Jane Doe',
            'jane@example.com'
        ));
        $this->conditionsCommitter->commitSnapshot($conditionsSnapshot, new CommitManagedSetRequest(
            'main',
            'Commit conditions',
            'Jane Doe',
            'jane@example.com'
        ));

        $state = (new RepositoryStateReader($this->repository))->read('local', 'refs/heads/main');

        self::assertArrayHasKey('generateblocks/global-styles/gbp-section.json', $state->files);
        self::assertArrayHasKey('generateblocks/global-styles/manifest.json', $state->files);
        self::assertArrayHasKey('generateblocks/conditions/is_event.json', $state->files);
        self::assertArrayHasKey('generateblocks/conditions/manifest.json', $state->files);
    }

    /**
     * @param array<string, mixed> $styleData
     * @return array<string, mixed>
     */
    private function runtimeRecord(string $selector, string $slug, int $menuOrder, array $styleData = []): array
    {
        return [
            'wp_object_id' => 2161859,
            'post_title' => $selector,
            'post_name' => $slug,
            'post_status' => 'publish',
            'menu_order' => $menuOrder,
            'gb_style_selector' => $selector,
            'gb_style_data' => serialize($styleData),
            'gb_style_css' => $selector . ' { color: red; }',
        ];
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>
     */
    private function conditionsRuntimeRecord(string $title, string $slug, int $menuOrder, array $conditions = []): array
    {
        return [
            'wp_object_id' => 22404,
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => 'publish',
            'menu_order' => $menuOrder,
            '_gb_conditions' => serialize($conditions),
        ];
    }
}
