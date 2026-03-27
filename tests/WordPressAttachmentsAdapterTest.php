<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;

final class WordPressAttachmentsAdapterTest extends TestCase
{
    private string $uploadsBaseDir;

    protected function setUp(): void
    {
        $this->uploadsBaseDir = '/tmp/pushpull-test-uploads';
        $this->removeDirectory($this->uploadsBaseDir);
        wp_mkdir_p($this->uploadsBaseDir . '/2026/03');
        file_put_contents($this->uploadsBaseDir . '/2026/03/bali.jpg', 'JPEGDATA');

        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(7, 'Bali', 'bali', 'inherit', 0, 'attachment', '', '2026-03-27 09:00:00', '2026-03-27 09:00:00', 'Beach view', 'image/jpeg', 0),
            new \WP_Post(8, 'Generated', 'generated', 'inherit', 0, 'attachment', '', '2026-03-27 09:00:00', '2026-03-27 09:00:00', '', 'image/jpeg', 0),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [
            7 => [
                '_wp_attached_file' => ['2026/03/bali.jpg'],
                '_wp_attachment_metadata' => [[
                    'width' => 1200,
                    'height' => 800,
                    'file' => '2026/03/bali.jpg',
                ]],
                '_wp_attachment_image_alt' => ['Bali beach'],
                'pushpull_sync_attachment' => ['1'],
            ],
            8 => [
                '_wp_attached_file' => ['2026/03/generated.jpg'],
            ],
        ];
        file_put_contents($this->uploadsBaseDir . '/2026/03/generated.jpg', 'GENERATED');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadsBaseDir);
    }

    public function testExportSnapshotBuildsAttachmentDirectoryFiles(): void
    {
        $adapter = new WordPressAttachmentsAdapter();
        $snapshot = $adapter->exportSnapshot();

        self::assertSame(['2026/03/bali-jpg'], $snapshot->orderedLogicalKeys);
        self::assertArrayHasKey('wordpress/attachments/2026/03/bali-jpg/attachment.json', $snapshot->files);
        self::assertArrayHasKey('wordpress/attachments/2026/03/bali-jpg/bali.jpg', $snapshot->files);
        self::assertArrayNotHasKey('wordpress/attachments/2026/03/generated-jpg/attachment.json', $snapshot->files);
        self::assertStringContainsString('"uploadsPath": "2026/03/bali.jpg"', $snapshot->files['wordpress/attachments/2026/03/bali-jpg/attachment.json']);
        self::assertSame('JPEGDATA', $snapshot->files['wordpress/attachments/2026/03/bali-jpg/bali.jpg']);
        self::assertArrayNotHasKey('wordpress/attachments/manifest.json', $snapshot->files);
    }

    public function testReadSnapshotFromRepositoryFilesRoundTripsAttachment(): void
    {
        $adapter = new WordPressAttachmentsAdapter();
        $snapshot = $adapter->exportSnapshot();
        $roundTrip = $adapter->readSnapshotFromRepositoryFiles($snapshot->files);

        self::assertSame($snapshot->orderedLogicalKeys, $roundTrip->orderedLogicalKeys);
        self::assertSame($snapshot->files, $roundTrip->files);
        self::assertSame($adapter->serialize($snapshot->items[0]), $adapter->serialize($roundTrip->items[0]));
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
