<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\WordPressBlockPatternsAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class WordPressBlockPatternsApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WordPressBlockPatternsAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WordPressBlockPatternsAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->applyService = new ManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            new ContentMapRepository($this->wpdb),
            new WorkingStateRepository($this->wpdb)
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_terms'] = [];
        $GLOBALS['pushpull_test_term_meta'] = [];
        $GLOBALS['pushpull_test_object_terms'] = [];
        $GLOBALS['pushpull_test_next_post_id'] = 1;
        $GLOBALS['pushpull_test_next_term_id'] = 1;
    }

    public function testApplyCreatesPatternMetaAndTerms(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 22458,
                'post_title' => 'LockedCard',
                'post_name' => 'lockedcard',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>Pattern content</p><!-- /wp:paragraph -->',
                'post_date' => '2025-12-03 11:35:25',
                'post_modified' => '2025-12-03 11:37:24',
                'post_meta' => [
                    ['meta_key' => '_generateblocks_dynamic_css_version', 'meta_value' => '2.1.2'],
                    ['meta_key' => 'generateblocks_patterns_tree', 'meta_value' => ['id' => 'pattern-22458', 'label' => 'LockedCard']],
                ],
                'terms' => [
                    [
                        'taxonomy' => 'gblocks_pattern_collections',
                        'term_slug' => 'local-patterns',
                        'term_name' => 'Local',
                        'taxonomy_description' => '',
                        'term_meta' => [],
                    ],
                    [
                        'taxonomy' => 'language',
                        'term_slug' => 'en',
                        'term_name' => 'English',
                        'taxonomy_description' => 'a:3:{s:6:"locale";s:5:"en_US";}',
                        'term_meta' => [
                            ['meta_key' => 'locale', 'meta_value' => 'en_US'],
                        ],
                    ],
                ],
            ],
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
            ['wordpress_block_patterns']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);

        $post = $GLOBALS['pushpull_test_generateblocks_posts'][0];
        self::assertSame('wp_block', $post->post_type);
        self::assertSame('lockedcard', $post->post_name);
        self::assertSame('<!-- wp:paragraph --><p>Pattern content</p><!-- /wp:paragraph -->', $post->post_content);
        self::assertSame('2.1.2', $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_generateblocks_dynamic_css_version'][0]);
        self::assertSame(['id' => 'pattern-22458', 'label' => 'LockedCard'], $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['generateblocks_patterns_tree'][0]);

        self::assertCount(1, $GLOBALS['pushpull_test_terms']['gblocks_pattern_collections'] ?? []);
        self::assertCount(1, $GLOBALS['pushpull_test_terms']['language'] ?? []);
        self::assertSame([1], $GLOBALS['pushpull_test_object_terms'][$post->ID]['gblocks_pattern_collections'] ?? []);
        self::assertSame([2], $GLOBALS['pushpull_test_object_terms'][$post->ID]['language'] ?? []);
        self::assertSame(['en_US'], $GLOBALS['pushpull_test_term_meta'][2]['locale'] ?? []);
    }

    public function testApplyPreservesEscapedUnicodeSequencesInBlockMarkup(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 22458,
                'post_title' => 'LockedCard',
                'post_name' => 'lockedcard',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:generateblocks/container {"backgroundColor":"var(\\u002d\\u002dbase-3)"} /-->',
                'post_date' => '2025-12-03 11:35:25',
                'post_modified' => '2025-12-03 11:37:24',
                'post_meta' => [
                    [
                        'meta_key' => 'generateblocks_patterns_tree',
                        'meta_value' => [
                            [
                                'id' => 'pattern-22458',
                                'label' => 'LockedCard',
                                'pattern' => '<!-- wp:generateblocks/container {"backgroundColor":"var(\\u002d\\u002dbase-3)"} /-->',
                            ],
                        ],
                    ],
                ],
                'terms' => [],
            ],
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
            ['wordpress_block_patterns']
        ));

        $post = $GLOBALS['pushpull_test_generateblocks_posts'][0];
        self::assertStringContainsString('var(\\u002d\\u002dbase-3)', $post->post_content);
        self::assertStringNotContainsString('var(u002du002dbase-3)', $post->post_content);
        self::assertStringContainsString(
            'var(\\u002d\\u002dbase-3)',
            $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['generateblocks_patterns_tree'][0][0]['pattern']
        );
        self::assertStringNotContainsString(
            'var(u002du002dbase-3)',
            $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['generateblocks_patterns_tree'][0][0]['pattern']
        );
    }
}
