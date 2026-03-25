<?php

declare(strict_types=1);

namespace PushPull\Persistence\WorkingState;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are internal constants, values still use prepare().

use PushPull\Domain\Merge\MergeConflict;
use PushPull\Domain\Merge\MergeConflictState;
use PushPull\Persistence\TableNames;
use PushPull\Support\Json\CanonicalJson;
use wpdb;

final class WorkingStateRepository
{
    private readonly TableNames $tables;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->tables = new TableNames($wpdb->prefix);
    }

    public function saveConflictState(MergeConflictState $state): void
    {
        $now = current_time('mysql');
        $existingId = $this->existingRowId($state->managedSetKey, $state->branch);

        $this->wpdb->replace(
            $this->tables->repoWorkingState(),
            [
                'id' => $existingId,
                'managed_set_key' => $state->managedSetKey,
                'branch_name' => $state->branch,
                'current_branch' => $state->branch,
                'head_commit_hash' => $state->headCommitHash,
                'working_tree_json' => CanonicalJson::encode($state->workingTree),
                'index_json' => null,
                'merge_base_hash' => $state->mergeBaseHash,
                'merge_target_hash' => $state->mergeTargetHash,
                'conflict_state_json' => CanonicalJson::encode($state->toArray()),
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public function clearMergeState(string $managedSetKey, string $branch, ?string $headCommitHash): void
    {
        $existingId = $this->existingRowId($managedSetKey, $branch);
        $now = current_time('mysql');

        $this->wpdb->replace(
            $this->tables->repoWorkingState(),
            [
                'id' => $existingId,
                'managed_set_key' => $managedSetKey,
                'branch_name' => $branch,
                'current_branch' => $branch,
                'head_commit_hash' => $headCommitHash,
                'working_tree_json' => null,
                'index_json' => null,
                'merge_base_hash' => null,
                'merge_target_hash' => null,
                'conflict_state_json' => null,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public function get(string $managedSetKey, string $branch): ?WorkingStateRecord
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, managed_set_key, branch_name, current_branch, head_commit_hash, working_tree_json, merge_base_hash, merge_target_hash, conflict_state_json, updated_at
                 FROM {$this->tables->repoWorkingState()}
                 WHERE managed_set_key = %s AND branch_name = %s",
                $managedSetKey,
                $branch
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        $workingTree = json_decode((string) ($row['working_tree_json'] ?? '{}'), true);
        $conflictState = json_decode((string) ($row['conflict_state_json'] ?? 'null'), true);
        $conflicts = [];

        if (is_array($conflictState['conflicts'] ?? null)) {
            foreach ($conflictState['conflicts'] as $conflict) {
                if (! is_array($conflict)) {
                    continue;
                }

                $conflicts[] = new MergeConflict(
                    (string) ($conflict['path'] ?? ''),
                    isset($conflict['baseContent']) ? (string) $conflict['baseContent'] : null,
                    isset($conflict['oursContent']) ? (string) $conflict['oursContent'] : null,
                    isset($conflict['theirsContent']) ? (string) $conflict['theirsContent'] : null,
                    is_array($conflict['jsonPaths'] ?? null) ? $conflict['jsonPaths'] : []
                );
            }
        }

        return new WorkingStateRecord(
            (string) $row['managed_set_key'],
            (string) $row['branch_name'],
            (string) $row['current_branch'],
            $row['head_commit_hash'] !== null && $row['head_commit_hash'] !== '' ? (string) $row['head_commit_hash'] : null,
            $row['merge_base_hash'] !== null && $row['merge_base_hash'] !== '' ? (string) $row['merge_base_hash'] : null,
            $row['merge_target_hash'] !== null && $row['merge_target_hash'] !== '' ? (string) $row['merge_target_hash'] : null,
            is_array($workingTree) ? $workingTree : [],
            $conflicts,
            (string) ($row['updated_at'] ?? '')
        );
    }

    private function existingRowId(string $managedSetKey, string $branch): ?int
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->tables->repoWorkingState()} WHERE managed_set_key = %s AND branch_name = %s",
                $managedSetKey,
                $branch
            ),
            ARRAY_A
        );

        if (! is_array($row) || ! isset($row['id'])) {
            return null;
        }

        return (int) $row['id'];
    }
}
