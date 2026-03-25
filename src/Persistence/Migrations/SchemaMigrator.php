<?php

declare(strict_types=1);

namespace PushPull\Persistence\Migrations;

use PushPull\Persistence\TableNames;

final class SchemaMigrator
{
    public const SCHEMA_VERSION = '1';
    private const VERSION_OPTION = 'pushpull_schema_version';

    public function maybeMigrate(): void
    {
        if ($this->installedVersion() === self::SCHEMA_VERSION) {
            return;
        }

        $this->install();
    }

    public function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = new TableNames($wpdb->prefix);
        $collate = $wpdb->get_charset_collate();

        dbDelta($this->repoBlobsSql($tables, $collate));
        dbDelta($this->repoTreesSql($tables, $collate));
        dbDelta($this->repoCommitsSql($tables, $collate));
        dbDelta($this->repoRefsSql($tables, $collate));
        dbDelta($this->repoWorkingStateSql($tables, $collate));
        dbDelta($this->repoOperationsSql($tables, $collate));
        dbDelta($this->contentMapSql($tables, $collate));

        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
    }

    public function installedVersion(): string
    {
        return (string) get_option(self::VERSION_OPTION, '');
    }

    private function repoBlobsSql(TableNames $tables, string $collate): string
    {
        return "CREATE TABLE {$tables->repoBlobs()} (
            hash varchar(191) NOT NULL,
            content longtext NOT NULL,
            size bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (hash)
        ) {$collate};";
    }

    private function repoTreesSql(TableNames $tables, string $collate): string
    {
        return "CREATE TABLE {$tables->repoTrees()} (
            hash varchar(191) NOT NULL,
            tree_json longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (hash)
        ) {$collate};";
    }

    private function repoCommitsSql(TableNames $tables, string $collate): string
    {
        return "CREATE TABLE {$tables->repoCommits()} (
            hash varchar(191) NOT NULL,
            tree_hash varchar(191) NOT NULL,
            parent_hash varchar(191) NULL,
            second_parent_hash varchar(191) NULL,
            author_name varchar(255) NOT NULL DEFAULT '',
            author_email varchar(255) NOT NULL DEFAULT '',
            message longtext NOT NULL,
            committed_at datetime NOT NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (hash),
            KEY tree_hash (tree_hash),
            KEY parent_hash (parent_hash),
            KEY second_parent_hash (second_parent_hash),
            KEY committed_at (committed_at)
        ) {$collate};";
    }

    private function repoRefsSql(TableNames $tables, string $collate): string
    {
        return "CREATE TABLE {$tables->repoRefs()} (
            ref_name varchar(191) NOT NULL,
            commit_hash varchar(191) NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (ref_name),
            KEY commit_hash (commit_hash)
        ) {$collate};";
    }

    private function repoWorkingStateSql(TableNames $tables, string $collate): string
    {
        return "CREATE TABLE {$tables->repoWorkingState()} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            managed_set_key varchar(100) NOT NULL,
            branch_name varchar(191) NOT NULL,
            current_branch varchar(191) NOT NULL,
            head_commit_hash varchar(191) NULL,
            working_tree_json longtext NULL,
            index_json longtext NULL,
            merge_base_hash varchar(191) NULL,
            merge_target_hash varchar(191) NULL,
            conflict_state_json longtext NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY managed_branch (managed_set_key, branch_name),
            KEY head_commit_hash (head_commit_hash)
        ) {$collate};";
    }

    private function repoOperationsSql(TableNames $tables, string $collate): string
    {
        return "CREATE TABLE {$tables->repoOperations()} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            managed_set_key varchar(100) NOT NULL DEFAULT '',
            operation_type varchar(100) NOT NULL,
            status varchar(50) NOT NULL,
            payload longtext NULL,
            result longtext NULL,
            created_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            finished_at datetime NULL,
            PRIMARY KEY  (id),
            KEY operation_type (operation_type),
            KEY status (status),
            KEY managed_set_key (managed_set_key),
            KEY created_by (created_by)
        ) {$collate};";
    }

    private function contentMapSql(TableNames $tables, string $collate): string
    {
        return "CREATE TABLE {$tables->contentMap()} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            managed_set_key varchar(100) NOT NULL,
            content_type varchar(100) NOT NULL,
            logical_key varchar(191) NOT NULL,
            wp_object_id bigint(20) unsigned NULL,
            last_known_hash varchar(191) NULL,
            status varchar(50) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY logical_identity (managed_set_key, content_type, logical_key),
            KEY wp_object_id (wp_object_id),
            KEY status (status)
        ) {$collate};";
    }
}
