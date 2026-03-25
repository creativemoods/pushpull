<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Persistence\Operations\OperationLogRepository;
use PushPull\Support\Operations\OperationExecutor;
use PushPull\Support\Operations\OperationLockService;
use RuntimeException;

final class OperationExecutorTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new \wpdb();
        delete_option('pushpull_repo_lock');
    }

    public function testExecutorLogsSuccessfulOperationAndReleasesLock(): void
    {
        $executor = new OperationExecutor(
            new OperationLogRepository($this->wpdb),
            new OperationLockService()
        );

        $result = $executor->run('generateblocks_global_styles', 'fetch', ['branch' => 'main'], fn (): array => [
            'remoteCommitHash' => 'abc123',
        ]);

        self::assertSame(['remoteCommitHash' => 'abc123'], $result);

        $records = (new OperationLogRepository($this->wpdb))->all();
        self::assertCount(1, $records);
        self::assertSame(OperationLogRepository::STATUS_SUCCEEDED, $records[0]->status);
        self::assertNull(get_option('pushpull_repo_lock', null));
    }

    public function testExecutorLogsFailureAndReleasesLock(): void
    {
        $executor = new OperationExecutor(
            new OperationLogRepository($this->wpdb),
            new OperationLockService()
        );

        try {
            $executor->run('generateblocks_global_styles', 'merge', ['branch' => 'main'], function (): void {
                throw new RuntimeException('boom');
            });
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        $records = (new OperationLogRepository($this->wpdb))->all();
        self::assertCount(1, $records);
        self::assertSame(OperationLogRepository::STATUS_FAILED, $records[0]->status);
        self::assertSame('boom', $records[0]->result['message']);
        self::assertNull(get_option('pushpull_repo_lock', null));
    }
}
