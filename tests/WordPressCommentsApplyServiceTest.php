<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressCommentsAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class WordPressCommentsApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WordPressCommentsAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WordPressCommentsAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->applyService = new ManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            new ContentMapRepository($this->wpdb),
            new WorkingStateRepository($this->wpdb)
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(7, 'About Us', 'about-us', 'publish', 0, 'page'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_comments'] = [];
        $GLOBALS['pushpull_test_comment_meta'] = [];
        $GLOBALS['pushpull_test_next_comment_id'] = 1;
    }

    public function testApplyCreatesCommentAndResolvesParentPostReference(): void
    {
        $snapshot = $this->adapter->readSnapshotFromRepositoryFiles([
            'wordpress/comments/manifest.json' => json_encode([
                'schemaVersion' => 1,
                'type' => 'wordpress_comments_manifest',
                'orderedLogicalKeys' => ['about-us-2026-04-06-10-00-00-jane-example-com'],
            ], JSON_THROW_ON_ERROR),
            'wordpress/comments/about-us-2026-04-06-10-00-00-jane-example-com.json' => json_encode([
                'schemaVersion' => 1,
                'adapterVersion' => 1,
                'type' => 'wordpress_comment',
                'logicalKey' => 'about-us-2026-04-06-10-00-00-jane-example-com',
                'displayName' => 'Comment by Jane Doe',
                'selector' => 'about-us-2026-04-06-10-00-00-jane-example-com',
                'slug' => 'about-us-2026-04-06-10-00-00-jane-example-com',
                'postStatus' => '1',
                'payload' => [
                    'postRef' => [
                        'objectRef' => [
                            'managedSetKey' => 'wordpress_pages',
                            'contentType' => 'wordpress_page',
                            'logicalKey' => 'about-us',
                            'postType' => 'page',
                        ],
                    ],
                    'parentCommentLogicalKey' => '',
                    'commentAuthor' => 'Jane Doe',
                    'commentAuthorEmail' => 'jane@example.com',
                    'commentAuthorUrl' => 'https://example.com',
                    'commentDate' => '2026-04-06 10:00:00',
                    'commentDateGmt' => '2026-04-06 10:00:00',
                    'commentType' => '',
                    'commentContent' => 'Helpful comment',
                ],
                'metadata' => [
                    'restoration' => ['objectType' => 'comment'],
                    'commentMeta' => [
                        ['key' => 'editorial_note', 'value' => 'reviewed'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $result = $this->applyService->apply(new PushPullSettings(
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
            ['wordpress_comments']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertCount(1, $GLOBALS['pushpull_test_comments']);
        self::assertSame(7, $GLOBALS['pushpull_test_comments'][0]->comment_post_ID);
        self::assertSame('Helpful comment', $GLOBALS['pushpull_test_comments'][0]->comment_content);
        self::assertSame(['reviewed'], $GLOBALS['pushpull_test_comment_meta'][1]['editorial_note']);
    }

    public function testApplyUpdatesExistingCommentAndPreservesUnmanagedCommentMeta(): void
    {
        $GLOBALS['pushpull_test_comments'][] = new \WP_Comment(
            3,
            7,
            0,
            'Jane Doe',
            'jane@example.com',
            'https://example.com',
            '2026-04-06 10:00:00',
            '2026-04-06 10:00:00',
            'Old comment',
            '1',
            ''
        );
        $GLOBALS['pushpull_test_comment_meta'][3] = [
            'editorial_note' => ['old'],
            'plugin_only' => ['keep'],
        ];

        $snapshot = $this->adapter->readSnapshotFromRepositoryFiles([
            'wordpress/comments/manifest.json' => json_encode([
                'schemaVersion' => 1,
                'type' => 'wordpress_comments_manifest',
                'orderedLogicalKeys' => ['about-us-2026-04-06-10-00-00-jane-example-com'],
            ], JSON_THROW_ON_ERROR),
            'wordpress/comments/about-us-2026-04-06-10-00-00-jane-example-com.json' => json_encode([
                'schemaVersion' => 1,
                'adapterVersion' => 1,
                'type' => 'wordpress_comment',
                'logicalKey' => 'about-us-2026-04-06-10-00-00-jane-example-com',
                'displayName' => 'Comment by Jane Doe',
                'selector' => 'about-us-2026-04-06-10-00-00-jane-example-com',
                'slug' => 'about-us-2026-04-06-10-00-00-jane-example-com',
                'postStatus' => '1',
                'payload' => [
                    'postRef' => [
                        'objectRef' => [
                            'managedSetKey' => 'wordpress_pages',
                            'contentType' => 'wordpress_page',
                            'logicalKey' => 'about-us',
                            'postType' => 'page',
                        ],
                    ],
                    'parentCommentLogicalKey' => '',
                    'commentAuthor' => 'Jane Doe',
                    'commentAuthorEmail' => 'jane@example.com',
                    'commentAuthorUrl' => 'https://example.com',
                    'commentDate' => '2026-04-06 10:00:00',
                    'commentDateGmt' => '2026-04-06 10:00:00',
                    'commentType' => '',
                    'commentContent' => 'Updated comment',
                ],
                'metadata' => [
                    'restoration' => ['objectType' => 'comment'],
                    'commentMeta' => [
                        ['key' => 'editorial_note', 'value' => 'reviewed'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $this->applyService->apply(new PushPullSettings(
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
            ['wordpress_comments']
        ));

        self::assertCount(1, $GLOBALS['pushpull_test_comments']);
        self::assertSame('Updated comment', $GLOBALS['pushpull_test_comments'][0]->comment_content);
        self::assertSame(['keep'], $GLOBALS['pushpull_test_comment_meta'][3]['plugin_only']);
        self::assertSame(['reviewed'], $GLOBALS['pushpull_test_comment_meta'][3]['editorial_note']);
    }
}
