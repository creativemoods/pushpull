<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressCoreConfigurationAdapter;
use PushPull\Domain\Apply\ConfigManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class WordPressCoreConfigurationApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WordPressCoreConfigurationAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ConfigManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];

        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WordPressCoreConfigurationAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->applyService = new ConfigManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            new WorkingStateRepository($this->wpdb)
        );
    }

    public function testApplyRestoresReadingSettingsUsingDestinationPageIds(): void
    {
        update_option('show_on_front', 'page');
        update_option('page_on_front', 10);
        update_option('page_for_posts', 11);
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(11, 'Blog', 'blog', 'publish', 0, 'page'),
        ];

        $snapshot = $this->adapter->exportSnapshot();
        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        update_option('show_on_front', 'posts');
        update_option('page_on_front', 0);
        update_option('page_for_posts', 0);
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(92, 'Blog', 'blog', 'publish', 0, 'page'),
        ];

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
            ['wordpress_pages', 'wordpress_core_configuration']
        ));

        self::assertSame(0, $result->createdCount);
        self::assertSame(1, $result->updatedCount);
        self::assertSame('page', get_option('show_on_front'));
        self::assertSame(91, get_option('page_on_front'));
        self::assertSame(92, get_option('page_for_posts'));
    }
}
