<?php

declare(strict_types=1);

namespace PushPull\Domain\Merge;

final class MergeConflictState
{
    /**
     * @param MergeConflict[] $conflicts
     * @param array<string, string> $workingTree
     */
    public function __construct(
        public readonly string $managedSetKey,
        public readonly string $branch,
        public readonly ?string $headCommitHash,
        public readonly ?string $mergeBaseHash,
        public readonly ?string $mergeTargetHash,
        public readonly array $workingTree,
        public readonly array $conflicts,
        public readonly int $schemaVersion = 1
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'managedSetKey' => $this->managedSetKey,
            'branch' => $this->branch,
            'headCommitHash' => $this->headCommitHash,
            'mergeBaseHash' => $this->mergeBaseHash,
            'mergeTargetHash' => $this->mergeTargetHash,
            'workingTree' => $this->workingTree,
            'conflicts' => array_map(
                static fn (MergeConflict $conflict): array => $conflict->toArray(),
                $this->conflicts
            ),
        ];
    }
}
