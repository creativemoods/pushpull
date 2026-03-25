<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Persistence\Operations\OperationLogRepository;

final class OperationLogRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new \wpdb();
    }

    public function testStartAndFinishOperationArePersisted(): void
    {
        $repository = new OperationLogRepository($this->wpdb);

        $record = $repository->start('generateblocks_global_styles', 'fetch', ['branch' => 'main']);

        self::assertSame(OperationLogRepository::STATUS_RUNNING, $record->status);
        self::assertSame('generateblocks_global_styles', $record->managedSetKey);
        self::assertSame(['branch' => 'main'], $record->payload);
        self::assertSame(1, $record->createdBy);
        self::assertNull($record->finishedAt);

        $completed = $repository->markSucceeded($record->id, ['remoteCommitHash' => 'abc123']);

        self::assertSame(OperationLogRepository::STATUS_SUCCEEDED, $completed->status);
        self::assertSame(['remoteCommitHash' => 'abc123'], $completed->result);
        self::assertNotNull($completed->finishedAt);
    }

    public function testFailedOperationPersistsFailurePayload(): void
    {
        $repository = new OperationLogRepository($this->wpdb);
        $record = $repository->start('generateblocks_global_styles', 'push', ['branch' => 'main']);
        $failed = $repository->markFailed($record->id, ['message' => 'conflict', 'category' => 'validation']);

        self::assertSame(OperationLogRepository::STATUS_FAILED, $failed->status);
        self::assertSame('conflict', $failed->result['message']);
        self::assertSame('validation', $failed->result['category']);
    }

    public function testRecentReturnsNewestFirst(): void
    {
        $repository = new OperationLogRepository($this->wpdb);
        $first = $repository->start('generateblocks_global_styles', 'fetch', []);
        $second = $repository->start('generateblocks_global_styles', 'merge', []);

        $recent = $repository->recent();

        self::assertSame($second->id, $recent[0]->id);
        self::assertSame($first->id, $recent[1]->id);
    }
}
