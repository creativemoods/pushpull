<?php

declare(strict_types=1);

namespace PushPull\Persistence;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names come from internal plugin metadata only.

use wpdb;

final class LocalRepositoryResetService
{
    private readonly TableNames $tables;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->tables = new TableNames($wpdb->prefix);
    }

    public function reset(): void
    {
        foreach ($this->tablesToClear() as $table) {
            $this->wpdb->query("DELETE FROM {$table}");
        }
    }

    /**
     * @return string[]
     */
    private function tablesToClear(): array
    {
        return [
            $this->tables->repoBlobs(),
            $this->tables->repoTrees(),
            $this->tables->repoCommits(),
            $this->tables->repoRefs(),
            $this->tables->repoWorkingState(),
            $this->tables->contentMap(),
        ];
    }
}
