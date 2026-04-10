<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressTagsAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class WordPressTagsApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WordPressTagsAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WordPressTagsAdapter();
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
    }

    public function testApplyCreatesAndUpdatesTags(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 21,
                'slug' => 'featured',
                'name' => 'Featured',
                'description' => 'Featured content',
                'parentSlug' => '',
                'termMeta' => [
                    ['meta_key' => 'visibility', 'meta_value' => 'public'],
                ],
            ],
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $created = $this->applyService->apply($this->settings());

        self::assertSame(1, $created->createdCount);
        $tagId = $this->adapter->findExistingWpObjectIdByLogicalKey('featured');
        self::assertNotNull($tagId);
        self::assertSame(['public'], $GLOBALS['pushpull_test_term_meta'][$tagId]['visibility']);

        $GLOBALS['pushpull_test_term_meta'][$tagId]['legacy'] = ['old'];

        $updatedSnapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 21,
                'slug' => 'featured',
                'name' => 'Featured',
                'description' => 'Updated featured content',
                'parentSlug' => '',
                'termMeta' => [
                    ['meta_key' => 'visibility', 'meta_value' => 'members'],
                ],
            ],
        ]);

        $this->committer->commitSnapshot(
            $updatedSnapshot,
            new CommitManagedSetRequest('main', 'Updated export', 'Jane Doe', 'jane@example.com')
        );

        $updated = $this->applyService->apply($this->settings());

        self::assertSame(1, $updated->updatedCount);
        self::assertSame('Updated featured content', $GLOBALS['pushpull_test_terms']['post_tag'][$tagId]->description);
        self::assertSame(['members'], $GLOBALS['pushpull_test_term_meta'][$tagId]['visibility']);
        self::assertArrayNotHasKey('legacy', $GLOBALS['pushpull_test_term_meta'][$tagId]);
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
            ['wordpress_tags']
        );
    }
}
