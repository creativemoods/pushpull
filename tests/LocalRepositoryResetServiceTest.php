<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\GenerateBlocks\GenerateBlocksGlobalStylesAdapter;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\LocalRepositoryResetService;
use PushPull\Persistence\TableNames;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\SettingsRepository;

final class LocalRepositoryResetServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $localRepository;
    private GenerateBlocksGlobalStylesAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private WorkingStateRepository $workingStateRepository;
    private LocalRepositoryResetService $resetService;

    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $this->wpdb = new \wpdb();
        $this->localRepository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new GenerateBlocksGlobalStylesAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->localRepository, $this->adapter);
        $this->workingStateRepository = new WorkingStateRepository($this->wpdb);
        $this->resetService = new LocalRepositoryResetService($this->wpdb);
    }

    public function testResetClearsLocalTablesButKeepsSettings(): void
    {
        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'provider_key' => 'github',
            'owner_or_workspace' => 'creativemoods',
            'repository' => 'pushpulltestrepo',
            'branch' => 'main',
            'api_token' => 'secret-token',
            'enabled_managed_sets' => ['generateblocks_global_styles'],
        ]));

        $snapshot = $this->adapter->snapshotFromRuntimeRecords([
            $this->runtimeRecord('.gbp-section', 'gbp-section', 0, ['paddingTop' => '7rem']),
        ]);

        $commit = $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        )->commit;

        self::assertNotNull($commit);

        $tables = new TableNames($this->wpdb->prefix);
        $this->wpdb->insert($tables->repoOperations(), [
            'managed_set_key' => 'generateblocks_global_styles',
            'operation_type' => 'merge',
            'status' => 'failed',
            'payload' => '{}',
            'result' => '{}',
            'created_by' => 1,
            'created_at' => '2026-03-24 09:00:00',
            'finished_at' => '2026-03-24 09:00:00',
        ]);
        $this->wpdb->insert($tables->contentMap(), [
            'managed_set_key' => 'generateblocks_global_styles',
            'content_type' => 'generateblocks_global_style',
            'logical_key' => 'gbp-section',
            'wp_object_id' => 123,
            'last_known_hash' => 'abc',
            'status' => 'active',
            'created_at' => '2026-03-24 09:00:00',
            'updated_at' => '2026-03-24 09:00:00',
        ]);
        $this->wpdb->insert($tables->repoWorkingState(), [
            'managed_set_key' => 'generateblocks_global_styles',
            'branch_name' => 'main',
            'current_branch' => 'main',
            'head_commit_hash' => $commit->hash,
            'working_tree_json' => '{}',
            'index_json' => null,
            'merge_base_hash' => null,
            'merge_target_hash' => 'remote-commit',
            'conflict_state_json' => '{"conflicts":[]}',
            'updated_at' => '2026-03-24 09:00:00',
        ]);

        $this->resetService->reset();

        self::assertFalse($this->localRepository->hasBeenInitialized('main'));
        self::assertNull($this->localRepository->getRef('HEAD'));
        self::assertNull($this->localRepository->getRef('refs/heads/main'));
        self::assertNull($this->localRepository->getCommit($commit->hash));
        self::assertNull($this->workingStateRepository->get('generateblocks_global_styles', 'main'));
        self::assertSame(1, count((new \PushPull\Persistence\Operations\OperationLogRepository($this->wpdb))->all()));

        $settings = $settingsRepository->get();
        self::assertSame('creativemoods', $settings->ownerOrWorkspace);
        self::assertSame('pushpulltestrepo', $settings->repository);
        self::assertSame('secret-token', $settings->apiToken);
        self::assertTrue($settings->isManagedSetEnabled('generateblocks_global_styles'));
    }

    /**
     * @param array<string, mixed> $styleData
     * @return array<string, mixed>
     */
    private function runtimeRecord(string $selector, string $slug, int $menuOrder, array $styleData = []): array
    {
        return [
            'wp_object_id' => 1,
            'post_title' => $selector,
            'post_name' => $slug,
            'post_status' => 'publish',
            'menu_order' => $menuOrder,
            'gb_style_selector' => $selector,
            'gb_style_data' => serialize($styleData),
            'gb_style_css' => $selector . ' { color: red; }',
        ];
    }
}
