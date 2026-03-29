<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Media\RmlMediaOrganizationAdapter;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;
use PushPull\Settings\SettingsRepository;

final class RmlMediaOrganizationAdapterTest extends TestCase
{
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

    public function testExportScopesFolderAssignmentsToManagedAttachments(): void
    {
        update_option(SettingsRepository::OPTION_KEY, [
            'enabled_managed_sets' => ['wordpress_attachments', 'media_organization'],
        ]);

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Hero', 'hero', 'inherit', 0, 'attachment', '', '2026-03-24 09:00:00', '2026-03-24 09:00:00', '', 'image/jpeg'),
            new \WP_Post(11, 'Ignored', 'ignored', 'inherit', 0, 'attachment', '', '2026-03-24 09:00:00', '2026-03-24 09:00:00', '', 'image/jpeg'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'][10] = [
            WordPressAttachmentsAdapter::SYNC_META_KEY => ['1'],
            '_wp_attached_file' => ['2026/03/hero.jpg'],
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'][11] = [
            '_wp_attached_file' => ['2026/03/ignored.jpg'],
        ];
        $GLOBALS['pushpull_test_rml_folders'] = [
            2 => '/Marketing',
            3 => '/Marketing/Heroes',
        ];
        $GLOBALS['pushpull_test_rml_attachment_folders'] = [
            10 => 3,
            11 => 2,
        ];

        $adapter = new RmlMediaOrganizationAdapter(new SettingsRepository());
        $snapshot = $adapter->exportSnapshot();

        self::assertSame(['wordpress_attachments:2026/03/hero-jpg'], $snapshot->orderedLogicalKeys);
        self::assertCount(1, $snapshot->items);
        self::assertSame('Marketing/Heroes', $snapshot->items[0]->payload['folderPath']);
        self::assertSame('2026/03/hero-jpg', $snapshot->items[0]->payload['contentLogicalKey']);
    }
}
