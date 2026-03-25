<?php

declare(strict_types=1);

namespace PushPull\Provider;

final class ProviderCapabilities
{
    public function __construct(
        public readonly bool $atomicRefUpdates,
        public readonly bool $batchObjectReads,
        public readonly bool $mergeCommitSupport,
        public readonly bool $defaultBranchDiscovery
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'atomic_ref_updates' => $this->atomicRefUpdates,
            'batch_object_reads' => $this->batchObjectReads,
            'merge_commit_support' => $this->mergeCommitSupport,
            'default_branch_discovery' => $this->defaultBranchDiscovery,
        ];
    }
}
