<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\GenerateBlocks\WordPressBlockPatternsAdapter;

final class WordPressBlockPatternsAdapterTest extends TestCase
{
    public function testSlugProducesLogicalKey(): void
    {
        $adapter = new WordPressBlockPatternsAdapter();

        self::assertSame('lockedcard', $adapter->computeLogicalKey(['post_name' => 'lockedcard']));
    }

    public function testItemSerializationIncludesContentMetaAndTerms(): void
    {
        $adapter = new WordPressBlockPatternsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"postContent"', $json);
        self::assertStringContainsString('"postMeta"', $json);
        self::assertStringContainsString('"terms"', $json);
        self::assertStringContainsString('"generateblocks_patterns_tree"', $json);
        self::assertStringContainsString('"taxonomy": "language"', $json);
        self::assertStringContainsString('"type": "wordpress_block_pattern"', $json);
    }

    public function testSerializationNormalizesCurrentEnvironmentUrlsToPlaceholders(): void
    {
        $adapter = new WordPressBlockPatternsAdapter();
        $json = $adapter->serialize($adapter->buildItemFromRuntimeRecord($this->runtimeRecordWithEnvironmentUrls()));

        self::assertStringNotContainsString('https://source.example.test', $json);
        self::assertStringContainsString('{{pushpull.home_url}}/premium-membership/', $json);
        self::assertStringContainsString('{{pushpull.home_url}}/app/plugins/generateblocks-pro/dist/accordion.js', $json);
        self::assertStringContainsString('https://external.example.test/login/', $json);
    }

    public function testManifestAndItemPathsAreDeterministic(): void
    {
        $adapter = new WordPressBlockPatternsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());

        self::assertSame('wordpress/block-patterns/lockedcard.json', $adapter->getRepositoryPath($item));
        self::assertSame('wordpress/block-patterns/manifest.json', $adapter->getManifestPath());
    }

    public function testDeserializationRoundTripsCanonicalItem(): void
    {
        $adapter = new WordPressBlockPatternsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $deserialized = $adapter->deserialize($adapter->getRepositoryPath($item), $adapter->serialize($item));

        self::assertSame($adapter->serialize($item), $adapter->serialize($deserialized));
    }

    public function testEmptyLogicalKeyIsRejected(): void
    {
        $adapter = new WordPressBlockPatternsAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->computeLogicalKey(['post_name' => '']);
    }

    public function testDeserializationRestoresCurrentEnvironmentUrlsFromPlaceholders(): void
    {
        $adapter = new WordPressBlockPatternsAdapter();
        $item = $adapter->deserialize(
            'wordpress/block-patterns/lockedcard.json',
            <<<'JSON'
{"adapterVersion":1,"derived":{"postDate":"2025-12-03 11:35:25","postModified":"2025-12-03 11:37:24"},"displayName":"LockedCard","logicalKey":"lockedcard","metadata":{"postMeta":[{"key":"generateblocks_patterns_tree","value":[{"label":"LockedCard","preview":"<a href=\"{{pushpull.home_url}}/preview/\">Preview</a>","scripts":["{{pushpull.home_url}}/app/plugins/generateblocks-pro/dist/accordion.js","https://external.example.test/app/plugins/generateblocks-pro/dist/tabs.js"]}]}],"restoration":{"postType":"wp_block"},"terms":[]},"payload":{"postContent":"<a href=\"{{pushpull.home_url}}/premium-membership/\">Unlock</a>"},"postStatus":"publish","schemaVersion":1,"selector":"lockedcard","slug":"lockedcard","type":"wordpress_block_pattern"}
JSON
        );

        self::assertSame('<a href="https://source.example.test/premium-membership/">Unlock</a>', $item->payload['postContent']);
        self::assertSame('<a href="https://source.example.test/preview/">Preview</a>', $item->metadata['postMeta'][0]['value'][0]['preview']);
        self::assertSame(
            'https://source.example.test/app/plugins/generateblocks-pro/dist/accordion.js',
            $item->metadata['postMeta'][0]['value'][0]['scripts'][0]
        );
        self::assertSame(
            'https://external.example.test/app/plugins/generateblocks-pro/dist/tabs.js',
            $item->metadata['postMeta'][0]['value'][0]['scripts'][1]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeRecord(): array
    {
        return [
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
                    'term_meta' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeRecordWithEnvironmentUrls(): array
    {
        return [
            'wp_object_id' => 22458,
            'post_title' => 'LockedCard',
            'post_name' => 'lockedcard',
            'post_status' => 'publish',
            'post_content' => '<a href="https://source.example.test/premium-membership/">Unlock</a><a href="https://external.example.test/login/">Login</a>',
            'post_date' => '2025-12-03 11:35:25',
            'post_modified' => '2025-12-03 11:37:24',
            'post_meta' => [
                [
                    'meta_key' => 'generateblocks_patterns_tree',
                    'meta_value' => [
                        [
                            'id' => 'pattern-22458',
                            'label' => 'LockedCard',
                            'preview' => '<a href="https://source.example.test/preview/">Preview</a>',
                            'scripts' => [
                                'https://source.example.test/app/plugins/generateblocks-pro/dist/accordion.js',
                                'https://external.example.test/app/plugins/generateblocks-pro/dist/tabs.js',
                            ],
                        ],
                    ],
                ],
            ],
            'terms' => [],
        ];
    }
}
