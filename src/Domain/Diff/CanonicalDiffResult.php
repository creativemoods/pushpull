<?php

declare(strict_types=1);

namespace PushPull\Domain\Diff;

final class CanonicalDiffResult
{
    /**
     * @param CanonicalDiffEntry[] $entries
     */
    public function __construct(public readonly array $entries)
    {
    }

    public function hasChanges(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->status !== 'unchanged') {
                return true;
            }
        }

        return false;
    }

    public function changedCount(): int
    {
        $count = 0;

        foreach ($this->entries as $entry) {
            if ($entry->status !== 'unchanged') {
                $count++;
            }
        }

        return $count;
    }
}
