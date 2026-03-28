<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\WordPress\GeneratePressElementsAdapter;

final class GeneratePressElementsAdapterTest extends TestCase
{
    public function testSerializationKeepsOwnedGeneratePressMetaAndDropsNoise(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(17, 'Share an event', 'share-an-event', 'publish', 0, 'page'),
        ];

        $adapter = new GeneratePressElementsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"type": "generatepress_element"', $json);
        self::assertStringContainsString('"_generate_element_type"', $json);
        self::assertStringContainsString('"_generate_element_display_conditions"', $json);
        self::assertStringContainsString('"_generate_block_type"', $json);
        self::assertStringContainsString('"objectRef"', $json);
        self::assertStringContainsString('"logicalKey": "share-an-event"', $json);
        self::assertStringNotContainsString('"object": "17"', $json);
        self::assertStringNotContainsString('"_wpml_word_count"', $json);
        self::assertStringNotContainsString('"copied_media_ids"', $json);
        self::assertStringNotContainsString('"pmpro_default_level"', $json);
    }

    public function testManifestAndItemPathsAreDeterministic(): void
    {
        $adapter = new GeneratePressElementsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());

        self::assertSame('generatepress/elements/header-cta.json', $adapter->getRepositoryPath($item));
        self::assertSame('generatepress/elements/manifest.json', $adapter->getManifestPath());
    }

    public function testDeserializationRoundTripsCanonicalItem(): void
    {
        $adapter = new GeneratePressElementsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord());
        $deserialized = $adapter->deserialize($adapter->getRepositoryPath($item), $adapter->serialize($item));

        self::assertSame($adapter->serialize($item), $adapter->serialize($deserialized));
    }

    public function testSerializationNormalizesMixedConditionArrays(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(42, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(64, 'Blog', 'blog', 'publish', 0, 'page'),
        ];

        $adapter = new GeneratePressElementsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 43,
            'post_title' => 'Other hero',
            'post_name' => 'other-hero',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
            'post_meta' => [
                ['meta_key' => '_generate_element_exclude_conditions', 'meta_value' => [
                    ['object' => '0', 'rule' => 'general:front_page'],
                    ['object' => '64', 'rule' => 'post:page'],
                    ['object' => '42', 'rule' => 'post:page'],
                ]],
                ['meta_key' => '_generate_element_type', 'meta_value' => 'block'],
            ],
            'terms' => [],
        ]);

        $excludeConditions = null;

        foreach ($item->metadata['postMeta'] as $entry) {
            if ($entry['key'] === '_generate_element_exclude_conditions') {
                $excludeConditions = $entry['value'];
                break;
            }
        }

        self::assertIsArray($excludeConditions);
        self::assertSame('general:front_page', $excludeConditions[0]['rule']);
        self::assertSame('0', $excludeConditions[0]['object']);
        self::assertSame('blog', $excludeConditions[1]['objectRef']['logicalKey']);
        self::assertSame('home', $excludeConditions[2]['objectRef']['logicalKey']);
    }

    public function testEmptyLogicalKeyIsRejected(): void
    {
        $adapter = new GeneratePressElementsAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->computeLogicalKey(['post_name' => '']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeRecord(): array
    {
        return [
            'wp_object_id' => 42,
            'post_title' => 'Header CTA',
            'post_name' => 'header-cta',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:paragraph --><p>CTA</p><!-- /wp:paragraph -->',
            'post_meta' => [
                ['meta_key' => '_generate_element_type', 'meta_value' => 'block'],
                ['meta_key' => '_generate_hook', 'meta_value' => 'generate_after_header'],
                ['meta_key' => '_generate_element_display_conditions', 'meta_value' => [['object' => '17', 'rule' => 'post:page']]],
                ['meta_key' => '_generate_block_type', 'meta_value' => 'hook'],
                ['meta_key' => '_generateblocks_dynamic_css_version', 'meta_value' => '2.1.2'],
                ['meta_key' => '_wpml_word_count', 'meta_value' => '44'],
                ['meta_key' => 'copied_media_ids', 'meta_value' => []],
                ['meta_key' => 'pmpro_default_level', 'meta_value' => '2'],
            ],
            'terms' => [],
        ];
    }
}
