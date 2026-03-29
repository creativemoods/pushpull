<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Media\RmlMediaOrganizationAdapter;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;
use PushPull\Domain\Apply\OverlayManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;
use PushPull\Settings\SettingsRepository;

final class RmlMediaOrganizationApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_rml_folders'] = [];
        $GLOBALS['pushpull_test_rml_attachment_folders'] = [];
        $GLOBALS['pushpull_test_next_rml_folder_id'] = 2;
    }

    public function testApplyMovesDestinationAttachmentIntoResolvedFolderPath(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_attachments', 'media_organization'],
        ]);

        $this->wpdb = new \wpdb();
        $repository = new DatabaseLocalRepository($this->wpdb);
        $adapter = new RmlMediaOrganizationAdapter(new SettingsRepository());
        $committer = new ManagedSetRepositoryCommitter($repository, $adapter);
        $applyService = new OverlayManagedSetApplyService(
            $adapter,
            new RepositoryStateReader($repository),
            new WorkingStateRepository($this->wpdb)
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Hero', 'hero', 'inherit', 0, 'attachment', '', '2026-03-24 09:00:00', '2026-03-24 09:00:00', '', 'image/jpeg'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'][10] = [
            WordPressAttachmentsAdapter::SYNC_META_KEY => ['1'],
            '_wp_attached_file' => ['2026/03/hero.jpg'],
        ];
        $GLOBALS['pushpull_test_rml_folders'] = [
            2 => '/Marketing',
            3 => '/Marketing/Heroes',
        ];
        $GLOBALS['pushpull_test_rml_attachment_folders'] = [
            10 => 3,
        ];

        $snapshot = $adapter->exportSnapshot();
        $committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Hero', 'hero', 'inherit', 0, 'attachment', '', '2026-03-24 09:00:00', '2026-03-24 09:00:00', '', 'image/jpeg'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'][91] = [
            WordPressAttachmentsAdapter::SYNC_META_KEY => ['1'],
            '_wp_attached_file' => ['2026/03/hero.jpg'],
        ];
        $GLOBALS['pushpull_test_rml_folders'] = [];
        $GLOBALS['pushpull_test_rml_attachment_folders'] = [];
        $GLOBALS['pushpull_test_next_rml_folder_id'] = 2;

        $result = $applyService->apply(new PushPullSettings(
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
            ['wordpress_attachments', 'media_organization']
        ));

        self::assertSame(0, $result->createdCount);
        self::assertSame(1, $result->updatedCount);
        self::assertSame('/Marketing', $GLOBALS['pushpull_test_rml_folders'][2] ?? null);
        self::assertSame('/Marketing/Heroes', $GLOBALS['pushpull_test_rml_folders'][3] ?? null);
        self::assertSame(3, $GLOBALS['pushpull_test_rml_attachment_folders'][91] ?? null);
    }
}
