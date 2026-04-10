<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\WordPress\WordPressCommentsAdapter;

final class WordPressCommentsAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(7, 'About Us', 'about-us', 'publish', 0, 'page'),
        ];
        $GLOBALS['pushpull_test_comments'] = [];
        $GLOBALS['pushpull_test_comment_meta'] = [];
    }

    public function testSerializationCapturesCommentPayloadAndMeta(): void
    {
        $adapter = new WordPressCommentsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"type": "wordpress_comment"', $json);
        self::assertStringContainsString('"commentContent"', $json);
        self::assertStringContainsString('"commentMeta"', $json);
        self::assertStringContainsString('"postRef"', $json);
        self::assertStringContainsString('Helpful comment', $json);
        self::assertStringContainsString('"editorial_note"', $json);
    }

    public function testManifestAndItemPathsAreDeterministic(): void
    {
        $adapter = new WordPressCommentsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());

        self::assertSame('wordpress/comments/' . $item->logicalKey . '.json', $adapter->getRepositoryPath($item));
        self::assertSame('wordpress/comments/manifest.json', $adapter->getManifestPath());
    }

    public function testEmptyLogicalKeyIsRejected(): void
    {
        $adapter = new WordPressCommentsAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->computeLogicalKey([
            'post_logical_key' => '',
            'comment_date_gmt' => '',
            'comment_author_email' => '',
            'comment_author' => '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeRecord(): array
    {
        return [
            'wp_object_id' => 11,
            'comment_post_ID' => 7,
            'comment_parent' => 0,
            'comment_author' => 'Jane Doe',
            'comment_author_email' => 'jane@example.com',
            'comment_author_url' => 'https://example.com',
            'comment_date' => '2026-04-06 10:00:00',
            'comment_date_gmt' => '2026-04-06 10:00:00',
            'comment_type' => '',
            'comment_content' => 'Helpful comment',
            'comment_approved' => '1',
            'postRef' => [
                'objectRef' => [
                    'managedSetKey' => 'wordpress_pages',
                    'contentType' => 'wordpress_page',
                    'logicalKey' => 'about-us',
                    'postType' => 'page',
                ],
            ],
            'comment_meta' => [
                ['key' => 'editorial_note', 'value' => 'reviewed'],
            ],
            '_logical_key' => 'about-us-2026-04-06-10-00-00-jane-example-com',
            '_logical_key_suffix' => 1,
            'parent_comment_logical_key' => '',
        ];
    }
}
