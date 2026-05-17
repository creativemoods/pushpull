<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressCategoriesAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class WordPressCategoriesApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WordPressCategoriesAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WordPressCategoriesAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->applyService = new ManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            new ContentMapRepository($this->wpdb),
            new WorkingStateRepository($this->wpdb)
        );

        $GLOBALS['pushpull_test_terms'] = [];
        $GLOBALS['pushpull_test_term_meta'] = [];
        $GLOBALS['pushpull_test_object_terms'] = [];
        $GLOBALS['pushpull_test_next_term_id'] = 1;
        $GLOBALS['pushpull_test_options'] = [];
    }

    public function testApplyCreatesAndUpdatesHierarchicalCategories(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 11,
                'slug' => 'news',
                'name' => 'News',
                'description' => 'Latest updates',
                'parentSlug' => '',
                'termMeta' => [
                    ['meta_key' => 'color', 'meta_value' => 'blue'],
                ],
            ],
            [
                'wp_object_id' => 12,
                'slug' => 'events',
                'name' => 'Events',
                'description' => '',
                'parentSlug' => 'news',
                'termMeta' => [],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $result = $this->applyService->apply($this->settings());

        self::assertSame(2, $result->createdCount);
        self::assertCount(2, $GLOBALS['pushpull_test_terms']['category']);

        $news = $this->adapter->findExistingWpObjectIdByLogicalKey('news');
        $events = $this->adapter->findExistingWpObjectIdByLogicalKey('events');

        self::assertNotNull($news);
        self::assertNotNull($events);
        self::assertSame(['blue'], $GLOBALS['pushpull_test_term_meta'][$news]['color']);
        self::assertSame($news, $GLOBALS['pushpull_test_terms']['category'][$events]->parent);

        $GLOBALS['pushpull_test_term_meta'][$news]['legacy'] = ['keep-until-replaced'];

        $updatedSnapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 11,
                'slug' => 'news',
                'name' => 'News',
                'description' => 'Fresh updates',
                'parentSlug' => '',
                'termMeta' => [
                    ['meta_key' => 'color', 'meta_value' => 'green'],
                ],
            ],
        ]);

        $this->committer->commitSnapshot(
            $updatedSnapshot,
            new CommitManagedSetRequest('main', 'Updated export', 'Jane Doe', 'jane@example.com')
        );

        $updated = $this->applyService->apply($this->settings());

        self::assertSame(1, $updated->updatedCount);
        self::assertSame(['events'], $updated->deletedLogicalKeys);
        self::assertSame('Fresh updates', $GLOBALS['pushpull_test_terms']['category'][$news]->description);
        self::assertSame(['green'], $GLOBALS['pushpull_test_term_meta'][$news]['color']);
        self::assertArrayNotHasKey('legacy', $GLOBALS['pushpull_test_term_meta'][$news]);
    }

    public function testApplyReassignsDefaultCategoryBeforeDeletingObsoleteCategory(): void
    {
        $oldDefaultId = (int) wp_insert_term('Old default', 'category', ['slug' => 'old-default'])['term_id'];
        $keepId = (int) wp_insert_term('Keep me', 'category', ['slug' => 'keep-me'])['term_id'];
        update_option('default_category', $oldDefaultId);

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => $keepId,
                'slug' => 'keep-me',
                'name' => 'Keep me',
                'description' => '',
                'parentSlug' => '',
                'termMeta' => [],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Category update', 'Jane Doe', 'jane@example.com')
        );

        $result = $this->applyService->apply($this->settings());

        self::assertSame(['old-default'], $result->deletedLogicalKeys);
        self::assertSame($keepId, (int) get_option('default_category'));
        self::assertArrayNotHasKey($oldDefaultId, $GLOBALS['pushpull_test_terms']['category']);
    }

    public function testApplySetsDefaultCategoryFromRepositoryManifest(): void
    {
        $oldDefaultId = (int) wp_insert_term('Old default', 'category', ['slug' => 'old-default'])['term_id'];
        $keepId = (int) wp_insert_term('Keep me', 'category', ['slug' => 'keep-me'])['term_id'];
        update_option('default_category', $oldDefaultId);

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => $keepId,
                'slug' => 'keep-me',
                'name' => 'Keep me',
                'description' => '',
                'parentSlug' => '',
                'termMeta' => [],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Category update', 'Jane Doe', 'jane@example.com')
        );

        $this->applyService->apply($this->settings());

        self::assertSame($keepId, (int) get_option('default_category'));
    }

    public function testAsyncApplyFlowSetsDefaultCategoryFromRepositoryManifestBeforeDeletion(): void
    {
        $oldDefaultId = (int) wp_insert_term('Old default', 'category', ['slug' => 'old-default'])['term_id'];
        $keepId = (int) wp_insert_term('Keep me', 'category', ['slug' => 'keep-me'])['term_id'];
        update_option('default_category', $oldDefaultId);

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => $keepId,
                'slug' => 'keep-me',
                'name' => 'Keep me',
                'description' => '',
                'parentSlug' => '',
                'termMeta' => [],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Category update', 'Jane Doe', 'jane@example.com')
        );

        $prepared = $this->applyService->prepareApply($this->settings());

        foreach ($prepared['orderedLogicalKeys'] as $menuOrder => $logicalKey) {
            $this->applyService->applyLogicalKey($this->settings(), $logicalKey, $menuOrder);
        }

        $this->applyService->applyManifestState($this->settings());
        $deleted = $this->applyService->deleteMissingLogicalKeys(['keep-me' => true]);

        self::assertSame(['old-default'], $deleted);
        self::assertSame($keepId, (int) get_option('default_category'));
    }

    private function settings(): PushPullSettings
    {
        return new PushPullSettings(
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
            ['wordpress_categories']
        );
    }
}
