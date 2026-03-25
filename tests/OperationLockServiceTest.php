<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Support\Operations\ConcurrentOperationException;
use PushPull\Support\Operations\OperationLockService;

final class OperationLockServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option('pushpull_repo_lock');
    }

    public function testConcurrentOperationsAreRejected(): void
    {
        $locks = new OperationLockService();
        $lock = $locks->acquire('fetch', 'generateblocks_global_styles', 10);

        $this->expectException(ConcurrentOperationException::class);
        $this->expectExceptionMessage('Another PushPull operation is already running');

        try {
            $locks->acquire('push', 'generateblocks_global_styles', 11);
        } finally {
            $locks->release($lock);
        }
    }

    public function testExpiredLockCanBeReplaced(): void
    {
        update_option('pushpull_repo_lock', [
            'token' => 'stale',
            'operationType' => 'fetch',
            'managedSetKey' => 'generateblocks_global_styles',
            'operationId' => 10,
            'expiresAt' => time() - 5,
        ]);

        $locks = new OperationLockService();
        $lock = $locks->acquire('push', 'generateblocks_global_styles', 11);

        self::assertNotSame('stale', $lock->token);

        $locks->release($lock);
        self::assertNull(get_option('pushpull_repo_lock', null));
    }
}
