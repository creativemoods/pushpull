<?php

declare(strict_types=1);

namespace PushPull\Domain\Sync;

use PushPull\Domain\Apply\ApplyManagedSetResult;
use PushPull\Domain\Diff\ManagedSetDiffResult;
use PushPull\Domain\Merge\MergeManagedSetResult;
use PushPull\Domain\Push\PushManagedSetResult;
use PushPull\Domain\Push\ResetRemoteBranchResult;

interface SyncServiceInterface
{
    public function commitManagedSet(string $managedSetKey, CommitManagedSetRequest $request): CommitManagedSetResult;

    public function fetch(string $managedSetKey): FetchManagedSetResult;

    public function diff(string $managedSetKey): ManagedSetDiffResult;

    public function merge(string $managedSetKey): MergeManagedSetResult;

    public function apply(string $managedSetKey): ApplyManagedSetResult;

    public function push(string $managedSetKey): PushManagedSetResult;

    public function resetRemote(string $managedSetKey): ResetRemoteBranchResult;
}
