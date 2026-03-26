<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Exception\ManagedContentExportException;
use PushPull\Content\GenerateBlocks\GenerateBlocksConditionsAdapter;
use PushPull\Content\ManagedCollectionManifest;

final class GenerateBlocksConditionsAdapterTest extends TestCase
{
    public function testSlugProducesLogicalKey(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();

        self::assertSame('is_event', $adapter->computeLogicalKey(['post_name' => 'is_event']));
    }

    public function testTitleFallsBackWhenSlugIsMissing(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();

        self::assertSame('is-location', $adapter->computeLogicalKey(['post_title' => ' Is Location ']));
    }

    public function testInvalidKeysAreRejected(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->computeLogicalKey(['post_name' => '...']);
    }

    public function testCollisionsAreDetected(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();

        $this->expectException(ManagedContentExportException::class);
        $adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('is_event', 'is_event', 0),
            $this->runtimeRecord('is_event duplicate', 'is_event', 1),
        ]);
    }

    public function testSerializedPhpBecomesCanonicalJsonPayload(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('is_event', 'is_event', 0, [
            'logic' => 'OR',
            'groups' => [
                [
                    'logic' => 'AND',
                    'conditions' => [
                        [
                            'type' => 'location',
                            'rule' => 'post:le_event',
                            'operator' => 'is',
                            'value' => '',
                        ],
                    ],
                ],
            ],
        ]));
        $json = $adapter->serialize($item);

        self::assertStringContainsString('"payload"', $json);
        self::assertStringContainsString('"logic": "OR"', $json);
        self::assertStringNotContainsString('a:2:{', $json);
    }

    public function testAlreadyUnserializedMetaArrayIsAccepted(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 22404,
            'post_title' => 'is_event',
            'post_name' => 'is_event',
            'post_status' => 'publish',
            'menu_order' => 0,
            '_gb_conditions' => [
                'logic' => 'OR',
                'groups' => [],
            ],
        ]);

        self::assertSame('is_event', $item->logicalKey);
        self::assertSame('OR', $item->payload['logic']);
    }

    public function testCategoriesAreIncludedInCanonicalMetadata(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord([
            'wp_object_id' => 22404,
            'post_title' => 'is_event',
            'post_name' => 'is_event',
            'post_status' => 'publish',
            'menu_order' => 0,
            '_gb_conditions' => [
                'logic' => 'OR',
                'groups' => [],
            ],
            'categories' => [
                ['slug' => 'events', 'name' => 'Events'],
                ['slug' => 'homepage', 'name' => 'Homepage'],
            ],
        ]);

        self::assertSame(
            [
                ['slug' => 'events', 'name' => 'Events'],
                ['slug' => 'homepage', 'name' => 'Homepage'],
            ],
            $item->metadata['categories']
        );
    }

    public function testOrderIsRepresentedOnlyInManifest(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();
        $snapshot = $adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('is_event', 'is_event', 2),
            $this->runtimeRecord('is_camp', 'is_camp', 1),
        ]);

        $itemJson = $adapter->serialize($snapshot->items[0]);
        $manifestJson = $adapter->serializeManifest($snapshot->manifest);

        self::assertStringNotContainsString('menuOrder', $itemJson);
        self::assertStringContainsString('"orderedLogicalKeys"', $manifestJson);
    }

    public function testManifestOrderIsDeterministic(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();
        $manifest = $adapter->buildManifest([
            $this->runtimeRecord('is_event', 'is_event', 10),
            $this->runtimeRecord('is_camp', 'is_camp', 0),
            $this->runtimeRecord('is_location', 'is_location', 0),
        ]);

        self::assertSame(['is_camp', 'is_location', 'is_event'], $manifest->orderedLogicalKeys);
    }

    public function testManifestReferencesMustBeKnown(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();
        $snapshot = $adapter->snapshotFromRuntimeRecords([$this->runtimeRecord('is_event', 'is_event', 0)]);
        $brokenManifest = new ManagedCollectionManifest(
            'generateblocks_conditions',
            'generateblocks_conditions_manifest',
            ['is_missing']
        );

        $this->expectException(ManagedContentExportException::class);
        $adapter->validateManifest($brokenManifest, $snapshot->items);
    }

    public function testItemAndManifestPathsAreDeterministicAndIdFree(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('is_event', 'is_event', 0));

        self::assertSame('generateblocks/conditions/is_event.json', $adapter->getRepositoryPath($item));
        self::assertSame('generateblocks/conditions/manifest.json', $adapter->getManifestPath());
        self::assertStringNotContainsString('22404', $adapter->getRepositoryPath($item));
    }

    public function testDeserializationRoundTripsCanonicalItem(): void
    {
        $adapter = new GenerateBlocksConditionsAdapter();
        $item = $adapter->buildItemFromRuntimeRecord($this->runtimeRecord('is_event', 'is_event', 0));
        $serialized = $adapter->serialize($item);
        $deserialized = $adapter->deserialize($adapter->getRepositoryPath($item), $serialized);

        self::assertSame($item->logicalKey, $deserialized->logicalKey);
        self::assertSame($item->payload, $deserialized->payload);
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>
     */
    private function runtimeRecord(string $title, string $slug, int $menuOrder, array $conditions = []): array
    {
        return [
            'wp_object_id' => 22404,
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => 'publish',
            'menu_order' => $menuOrder,
            '_gb_conditions' => serialize($conditions),
        ];
    }
}
