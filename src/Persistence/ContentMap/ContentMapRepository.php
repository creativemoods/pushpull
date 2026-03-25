<?php

declare(strict_types=1);

namespace PushPull\Persistence\ContentMap;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are internal constants, values still use prepare().

use PushPull\Persistence\TableNames;
use wpdb;

final class ContentMapRepository
{
    private readonly TableNames $tables;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->tables = new TableNames($wpdb->prefix);
    }

    public function findByLogicalKey(string $managedSetKey, string $contentType, string $logicalKey): ?ContentMapEntry
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, managed_set_key, content_type, logical_key, wp_object_id, last_known_hash, status
                 FROM {$this->tables->contentMap()}
                 WHERE managed_set_key = %s AND content_type = %s AND logical_key = %s",
                $managedSetKey,
                $contentType,
                $logicalKey
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        return new ContentMapEntry(
            (int) $row['id'],
            (string) $row['managed_set_key'],
            (string) $row['content_type'],
            (string) $row['logical_key'],
            isset($row['wp_object_id']) && $row['wp_object_id'] !== null ? (int) $row['wp_object_id'] : null,
            isset($row['last_known_hash']) && $row['last_known_hash'] !== null ? (string) $row['last_known_hash'] : null,
            (string) $row['status']
        );
    }

    public function upsert(string $managedSetKey, string $contentType, string $logicalKey, int $wpObjectId, string $lastKnownHash): void
    {
        $existing = $this->findByLogicalKey($managedSetKey, $contentType, $logicalKey);

        $this->wpdb->replace(
            $this->tables->contentMap(),
            [
                'id' => $existing?->id,
                'managed_set_key' => $managedSetKey,
                'content_type' => $contentType,
                'logical_key' => $logicalKey,
                'wp_object_id' => $wpObjectId,
                'last_known_hash' => $lastKnownHash,
                'status' => 'active',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }

    public function markDeleted(string $managedSetKey, string $contentType, string $logicalKey): void
    {
        $existing = $this->findByLogicalKey($managedSetKey, $contentType, $logicalKey);

        if ($existing === null) {
            return;
        }

        $this->wpdb->replace(
            $this->tables->contentMap(),
            [
                'id' => $existing->id,
                'managed_set_key' => $existing->managedSetKey,
                'content_type' => $existing->contentType,
                'logical_key' => $existing->logicalKey,
                'wp_object_id' => $existing->wpObjectId,
                'last_known_hash' => $existing->lastKnownHash,
                'status' => 'deleted',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }
}
