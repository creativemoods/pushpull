<?php

declare(strict_types=1);

namespace PushPull\Domain\Diff;

final class ManagedSetDiffResult
{
    public function __construct(
        public readonly string $managedSetKey,
        public readonly CanonicalManagedState $live,
        public readonly CanonicalManagedState $local,
        public readonly CanonicalManagedState $remote,
        public readonly CanonicalDiffResult $liveToLocal,
        public readonly CanonicalDiffResult $localToRemote,
        public readonly RepositoryRelationship $repositoryRelationship
    ) {
    }
}
