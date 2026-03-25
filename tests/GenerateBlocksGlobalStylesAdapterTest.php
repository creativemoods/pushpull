<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Content\ManagedCollectionManifest;

final class GenerateBlocksGlobalStylesAdapterTest extends TestCase
{
    public function testSelectorNormalizationProducesLogicalKey(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();

        self::assertSame('gbp-section', $adapter->computeLogicalKey(['gb_style_selector' => '.gbp-section']));
    }

    public function testWhitespaceAndCasingNormalizeDeterministically(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();

        self::assertSame('gbp-section', $adapter->computeLogicalKey(['gb_style_selector' => '  .GBP Section  ']));
    }

    public function testBEMElementSelectorsAreAllowed(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();

        self::assertSame('gbp-section__inner', $adapter->computeLogicalKey(['gb_style_selector' => '.gbp-section__inner']));
    }

    public function testBEMModifierSelectorsAreAllowed(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();

        self::assertSame('gbp-button--primary', $adapter->computeLogicalKey(['gb_style_selector' => '.gbp-button--primary']));
    }

    public function testInvalidKeysAreRejected(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->computeLogicalKey(['gb_style_selector' => '...']);
    }

    public function testCollisionsAreDetected(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0),
            $this->runtimeRecord('.GBP Section', 'gbp-section-duplicate', 1),
        ]);
    }

    public function testEquivalentSemanticPayloadsSerializeIdentically(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();

        $itemA = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('.gbp-section', 'gbp-section', 0, [
            'spacing' => ['bottom' => '2rem', 'top' => '1rem'],
            'color' => 'red',
        ]));
        $itemB = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('.gbp-section', 'gbp-section', 0, [
            'color' => 'red',
            'spacing' => ['top' => '1rem', 'bottom' => '2rem'],
        ]));

        self::assertSame($adapter->serialize($itemA), $adapter->serialize($itemB));
    }

    public function testSourceKeyOrderDoesNotAffectItemHash(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();

        $itemA = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('.gbp-card', 'gbp-card', 0, [
            'b' => 2,
            'a' => 1,
        ]));
        $itemB = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('.gbp-card', 'gbp-card', 0, [
            'a' => 1,
            'b' => 2,
        ]));

        self::assertSame($adapter->hashItem($itemA), $adapter->hashItem($itemB));
    }

    public function testForbiddenRuntimeFieldsDoNotAppearInOutput(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('.gbp-section', 'gbp-section', 0));
        $json = $adapter->serialize($item);

        self::assertStringNotContainsString('"wp_object_id"', $json);
        self::assertStringNotContainsString('"guid"', $json);
        self::assertStringNotContainsString('"post_author"', $json);
    }

    public function testSerializedPhpBecomesCanonicalJsonPayload(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('.gbp-section', 'gbp-section', 0, [
            'color' => ['text' => 'black'],
            'spacing' => ['top' => '1rem'],
        ]));
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"payload"', $json);
        self::assertStringContainsString('"color"', $json);
        self::assertStringNotContainsString('a:2:{', $json);
    }

    public function testAlreadyUnserializedMetaArrayIsAccepted(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $item = $adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 2161859,
            'post_title' => '.gbp-section',
            'post_name' => 'gbp-section',
            'post_status' => 'publish',
            'menu_order' => 0,
            'gb_style_selector' => '.gbp-section',
            'gb_style_data' => [
                'paddingTop' => '7rem',
                'paddingBottom' => '7rem',
            ],
            'gb_style_css' => '.gbp-section { padding: 7rem 0; }',
        ]);

        self::assertSame('gbp-section', $item->logicalKey);
        self::assertSame('7rem', $item->payload['paddingTop']);
    }

    public function testOrderIsRepresentedOnlyInManifest(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $snapshot = $adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 2),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 1),
        ]);

        $itemJson = $adapter->serialize($snapshot->items[0]);
        $manifestJson = $adapter->serializeManifest($snapshot->manifest);

        self::assertStringNotContainsString('menuOrder', $itemJson);
        self::assertStringContainsString('"orderedLogicalKeys"', $manifestJson);
    }

    public function testReorderOnlyChangesAffectManifestButNotUnchangedItems(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();

        $snapshotA = $adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 1),
        ]);
        $snapshotB = $adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 1),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 0),
        ]);

        $itemsA = $this->indexItemsByKey($snapshotA->items);
        $itemsB = $this->indexItemsByKey($snapshotB->items);

        self::assertSame($adapter->serialize($itemsA['gbp-section']), $adapter->serialize($itemsB['gbp-section']));
        self::assertSame($adapter->serialize($itemsA['gbp-card']), $adapter->serialize($itemsB['gbp-card']));
        self::assertNotSame($adapter->serializeManifest($snapshotA->manifest), $adapter->serializeManifest($snapshotB->manifest));
    }

    public function testManifestOrderIsDeterministic(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $manifest = $adapter->buildManifest([
            $this->runtimeRecord('.gbp-button', 'gbp-button', 10),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 0),
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0),
        ]);

        self::assertSame(['gbp-card', 'gbp-section', 'gbp-button'], $manifest->orderedLogicalKeys);
    }

    public function testManifestReferencesMustBeKnown(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $snapshot = $adapter->snapshotFromRuntimeRecords([$this->runtimeRecord('.gbp-section', 'gbp-section', 0)]);
        $brokenManifest = new ManagedCollectionManifest(
            'generateblocks_global_styles',
            'generateblocks_global_styles_manifest',
            ['gbp-missing']
        );

        $this->expectException(ManagedContentExportException::class);
        $adapter->validateManifest($brokenManifest, $snapshot->items);
    }

    public function testItemAndManifestPathsAreDeterministicAndIdFree(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('.gbp-section', 'gbp-section', 0));

        self::assertSame('generateblocks/global-styles/gbp-section.json', $adapter->getRepositoryPath($item));
        self::assertSame('generateblocks/global-styles/manifest.json', $adapter->getManifestPath());
        self::assertStringNotContainsString('2161859', $adapter->getRepositoryPath($item));
    }

    public function testIdenticalSemanticItemsHashIdentically(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $itemA = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['x' => 1, 'y' => 2]));
        $itemB = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['y' => 2, 'x' => 1]));

        self::assertSame($adapter->hashItem($itemA), $adapter->hashItem($itemB));
    }

    public function testReorderOnlyChangesAffectManifestHash(): void
    {
        $adapter = new GenerateBlocksGlobalStylesAdapter();
        $manifestA = $adapter->buildManifest([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 1),
        ]);
        $manifestB = $adapter->buildManifest([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 1),
            $this->runtimeRecord('.gbp-card', 'gbp-card', 0),
        ]);

        self::assertNotSame($adapter->hashManifest($manifestA), $adapter->hashManifest($manifestB));
    }

    /**
     * @param array<string, mixed> $styleData
     * @return array<string, mixed>
     */
    private function runtimeRecord(string $selector, string $slug, int $menuOrder, array $styleData = []): array
    {
        return [
            'wp_object_id' => 2161859,
            'post_title' => $selector,
            'post_name' => $slug,
            'post_status' => 'publish',
            'menu_order' => $menuOrder,
            'gb_style_selector' => $selector,
            'gb_style_data' => serialize($styleData),
            'gb_style_css' => $selector . ' { color: red; }',
        ];
    }

    /**
     * @param array<int, \PushPull\Content\ManagedContentItem> $items
     * @return array<string, \PushPull\Content\ManagedContentItem>
     */
    private function indexItemsByKey(array $items): array
    {
        $indexed = [];

        foreach ($items as $item) {
            $indexed[$item->logicalKey] = $item;
        }

        return $indexed;
    }
}
