<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\GeneratePressElementsAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class GeneratePressElementsApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private GeneratePressElementsAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new GeneratePressElementsAdapter();
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

    public function testApplyCreatesGeneratePressElementWithOwnedMeta(): void
    {
        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 42,
                'post_title' => 'Header CTA',
                'post_name' => 'header-cta',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>CTA</p><!-- /wp:paragraph -->',
                'post_meta' => [
                    ['meta_key' => '_generate_element_type', 'meta_value' => 'block'],
                    ['meta_key' => '_generate_hook', 'meta_value' => 'generate_after_header'],
                    ['meta_key' => '_generate_element_display_conditions', 'meta_value' => [['location' => 'entire-site']]],
                    ['meta_key' => '_generate_block_type', 'meta_value' => 'hook'],
                    ['meta_key' => '_wpml_word_count', 'meta_value' => '44'],
                ],
                'terms' => [],
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
            ['generatepress_elements']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);

        $post = $GLOBALS['pushpull_test_generateblocks_posts'][0];
        self::assertSame('gp_elements', $post->post_type);
        self::assertSame('header-cta', $post->post_name);
        self::assertSame(['block'], $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_generate_element_type']);
        self::assertSame(['generate_after_header'], $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_generate_hook']);
        self::assertArrayNotHasKey('_wpml_word_count', $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]);
    }

    public function testApplyPreservesUnmanagedMetaOnExistingGeneratePressElement(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'][] = new \WP_Post(
            42,
            'Header CTA',
            'header-cta',
            'publish',
            0,
            'gp_elements',
            '<!-- wp:paragraph --><p>Old CTA</p><!-- /wp:paragraph -->'
        );
        $GLOBALS['pushpull_test_generateblocks_meta'][42] = [
            '_wpml_word_count' => ['44'],
        ];

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            [
                'wp_object_id' => 42,
                'post_title' => 'Header CTA',
                'post_name' => 'header-cta',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:paragraph --><p>CTA</p><!-- /wp:paragraph -->',
                'post_meta' => [
                    ['meta_key' => '_generate_element_type', 'meta_value' => 'block'],
                ],
                'terms' => [],
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
            ['generatepress_elements']
        ));

        self::assertSame(1, $result->updatedCount);
        self::assertSame(['44'], $GLOBALS['pushpull_test_generateblocks_meta'][42]['_wpml_word_count']);
        self::assertSame(['block'], $GLOBALS['pushpull_test_generateblocks_meta'][42]['_generate_element_type']);
    }
}
