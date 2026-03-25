<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Domain\Merge\ManagedSetConflictResolutionService;
use PushPull\Domain\Merge\MergeConflict;
use PushPull\Domain\Merge\MergeConflictState;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;

final class ManagedSetConflictResolutionServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WorkingStateRepository $workingStateRepository;
    private ManagedSetConflictResolutionService $service;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->workingStateRepository = new WorkingStateRepository($this->wpdb);
        $this->service = new ManagedSetConflictResolutionService($this->repository, $this->workingStateRepository);
        $this->repository->init('main');
    }

    public function testResolveUsingOursUpdatesWorkingTreeAndRemovesConflict(): void
    {
        $this->workingStateRepository->saveConflictState(new MergeConflictState(
            'generateblocks_global_styles',
            'main',
            'ours-commit',
            'base-commit',
            'theirs-commit',
            ['generateblocks/global-styles/gbp-section.json' => "{\n  \"from\": \"merge\"\n}\n"],
            [
                new MergeConflict(
                    'generateblocks/global-styles/gbp-section.json',
                    "{\n  \"value\": \"base\"\n}\n",
                    "{\n  \"value\": \"ours\"\n}\n",
                    "{\n  \"value\": \"theirs\"\n}\n",
                    ['$.value']
                ),
            ]
        ));

        $result = $this->service->resolveUsingOurs('generateblocks_global_styles', 'main', 'generateblocks/global-styles/gbp-section.json');

        self::assertSame(0, $result->remainingConflictCount);
        $state = $this->workingStateRepository->get('generateblocks_global_styles', 'main');
        self::assertNotNull($state);
        self::assertFalse($state->hasConflicts());
        self::assertSame("{\n  \"value\": \"ours\"\n}\n", $state->workingTree['generateblocks/global-styles/gbp-section.json']);
    }

    public function testResolveUsingManualRequiresValidJsonAndPersistsManualContent(): void
    {
        $this->workingStateRepository->saveConflictState(new MergeConflictState(
            'generateblocks_global_styles',
            'main',
            'ours-commit',
            'base-commit',
            'theirs-commit',
            ['generateblocks/global-styles/gbp-section.json' => "{\n  \"from\": \"merge\"\n}\n"],
            [
                new MergeConflict(
                    'generateblocks/global-styles/gbp-section.json',
                    "{\n  \"value\": \"base\"\n}\n",
                    "{\n  \"value\": \"ours\"\n}\n",
                    "{\n  \"value\": \"theirs\"\n}\n",
                    ['$.value']
                ),
            ]
        ));

        $result = $this->service->resolveUsingManual(
            'generateblocks_global_styles',
            'main',
            'generateblocks/global-styles/gbp-section.json',
            "{\n  \"value\": \"manual\"\n}"
        );

        self::assertSame(0, $result->remainingConflictCount);
        $state = $this->workingStateRepository->get('generateblocks_global_styles', 'main');
        self::assertNotNull($state);
        self::assertSame("{\n  \"value\": \"manual\"\n}\n", $state->workingTree['generateblocks/global-styles/gbp-section.json']);
    }

    public function testFinalizeCreatesMergeCommitAndClearsWorkingState(): void
    {
        $oursBlob = $this->repository->storeBlob("{\n  \"ours\": true\n}\n");
        $oursTree = $this->repository->storeTree([
            new \PushPull\Domain\Repository\TreeEntry('generateblocks/global-styles/gbp-section.json', 'blob', $oursBlob->hash),
        ]);
        $oursCommit = $this->repository->commit(new \PushPull\Domain\Repository\CommitRequest(
            $oursTree->hash,
            null,
            null,
            'Jane Doe',
            'jane@example.com',
            'Ours',
            []
        ));
        $this->repository->updateRef('refs/heads/main', $oursCommit->hash);
        $this->repository->updateRef('HEAD', $oursCommit->hash);

        $theirsBlob = $this->repository->storeBlob("{\n  \"theirs\": true\n}\n");
        $theirsTree = $this->repository->storeTree([
            new \PushPull\Domain\Repository\TreeEntry('generateblocks/global-styles/gbp-section.json', 'blob', $theirsBlob->hash),
        ]);
        $theirsCommit = $this->repository->commit(new \PushPull\Domain\Repository\CommitRequest(
            $theirsTree->hash,
            null,
            null,
            'Jane Doe',
            'jane@example.com',
            'Theirs',
            []
        ));
        $this->repository->updateRef('refs/remotes/origin/main', $theirsCommit->hash);

        $this->workingStateRepository->saveConflictState(new MergeConflictState(
            'generateblocks_global_styles',
            'main',
            $oursCommit->hash,
            null,
            $theirsCommit->hash,
            ['generateblocks/global-styles/gbp-section.json' => "{\n  \"resolved\": true\n}\n"],
            []
        ));

        $result = $this->service->finalize('generateblocks_global_styles', 'main');

        self::assertSame('main', $result->branch);
        self::assertSame($result->commit->hash, $this->repository->getRef('refs/heads/main')?->commitHash);
        self::assertSame($oursCommit->hash, $result->commit->parentHash);
        self::assertSame($theirsCommit->hash, $result->commit->secondParentHash);
        self::assertFalse((bool) $this->workingStateRepository->get('generateblocks_global_styles', 'main')?->mergeTargetHash);
    }
}
