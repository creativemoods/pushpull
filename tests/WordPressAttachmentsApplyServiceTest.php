<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class WordPressAttachmentsApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WordPressAttachmentsAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ManagedSetApplyService $applyService;
    private string $uploadsBaseDir;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WordPressAttachmentsAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->applyService = new ManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            new ContentMapRepository($this->wpdb),
            new WorkingStateRepository($this->wpdb)
        );
        $this->uploadsBaseDir = '/tmp/pushpull-test-uploads';
        $this->removeDirectory($this->uploadsBaseDir);
        wp_mkdir_p($this->uploadsBaseDir . '/2026/03');

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
        file_put_contents($this->uploadsBaseDir . '/2026/03/bali.jpg', 'JPEGDATA');
        file_put_contents($this->uploadsBaseDir . '/2026/03/generated.jpg', 'GENERATED');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadsBaseDir);
    }

    public function testApplyCreatesAttachmentAndWritesBinaryFile(): void
    {
        $snapshot = $this->adapter->exportSnapshot();

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $this->removeDirectory($this->uploadsBaseDir);

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
            ['wordpress_attachments']
        ));

        self::assertSame(1, $result->createdCount);
        self::assertCount(1, $GLOBALS['pushpull_test_generateblocks_posts']);
        $post = $GLOBALS['pushpull_test_generateblocks_posts'][0];
        self::assertSame('attachment', $post->post_type);
        self::assertSame('image/jpeg', $post->post_mime_type);
        self::assertSame('2026/03/bali.jpg', $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_wp_attached_file']);
        self::assertSame('Bali beach', $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_wp_attachment_image_alt']);
        self::assertSame('1', $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['pushpull_sync_attachment']);
        self::assertSame('2026/03/bali.jpg', $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_wp_attachment_metadata']['file']);
        self::assertTrue((bool) ($GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_wp_attachment_metadata']['generated'] ?? false));
        self::assertSame('thumb-bali.jpg', $GLOBALS['pushpull_test_generateblocks_meta'][$post->ID]['_wp_attachment_metadata']['sizes']['thumbnail']['file']);
        self::assertSame('JPEGDATA', file_get_contents($this->uploadsBaseDir . '/2026/03/bali.jpg'));
    }

    public function testApplyDoesNotDeleteUnmanagedAttachments(): void
    {
        $snapshot = $this->adapter->exportSnapshot();

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
            ['wordpress_attachments']
        ));

        self::assertSame([], $result->deletedLogicalKeys);
        self::assertCount(2, $GLOBALS['pushpull_test_generateblocks_posts']);
        self::assertSame('generated', $GLOBALS['pushpull_test_generateblocks_posts'][1]->post_name);
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
