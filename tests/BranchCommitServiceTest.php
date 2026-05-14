<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\GenerateBlocksConditionsAdapter;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\BranchCommitService;
use PushPull\Domain\Sync\CommitManagedSetRequest;

final class BranchCommitServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private GenerateBlocksGlobalStylesAdapter $stylesAdapter;
    private GenerateBlocksConditionsAdapter $conditionsAdapter;
    private BranchCommitService $service;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->stylesAdapter = new GenerateBlocksGlobalStylesAdapter();
        $this->conditionsAdapter = new GenerateBlocksConditionsAdapter();
        $this->service = new BranchCommitService($this->repository);
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(1, '.gbp-section', 'gbp-section', 'publish', 0, 'gblocks_styles'),
            new \WP_Post(2, 'is_event', 'is_event', 'publish', 0, 'gblocks_condition'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [
            1 => [
                'gb_style_selector' => '.gbp-section',
                'gb_style_data' => serialize(['paddingTop' => '7rem']),
                'gb_style_css' => '.gbp-section { color: red; }',
            ],
            2 => [
                '_gb_conditions' => serialize(['logic' => 'OR', 'groups' => []]),
            ],
        ];
    }

    public function testCommitManagedSetsCreatesSingleBranchCommitForMultipleDomains(): void
    {
        $result = $this->service->commitManagedSets([
            'generateblocks_global_styles' => $this->stylesAdapter,
            'generateblocks_conditions' => $this->conditionsAdapter,
        ], new CommitManagedSetRequest(
            'main',
            'Deploy homepage sync',
            'Jane Doe',
            'jane@example.com'
        ));

        $state = (new RepositoryStateReader($this->repository))->read('local', 'refs/heads/main');

        self::assertTrue($result->initializedRepository);
        self::assertTrue($result->createdNewCommit);
        self::assertSame('Deploy homepage sync', $result->commit?->message);
        self::assertSame(4, $result->changedPathCount);
        self::assertArrayHasKey('generateblocks/global-styles/gbp-section.json', $state->files);
        self::assertArrayHasKey('generateblocks/global-styles/manifest.json', $state->files);
        self::assertArrayHasKey('generateblocks/conditions/is_event.json', $state->files);
        self::assertArrayHasKey('generateblocks/conditions/manifest.json', $state->files);
    }

    protected function tearDown(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
    }
}
