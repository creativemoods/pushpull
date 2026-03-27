<?php

declare(strict_types=1);

namespace PushPull\Persistence\Operations;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are internal constants, values still use prepare().

use PushPull\Persistence\TableNames;
use wpdb;

final class OperationLogRepository
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    private readonly string $tableName;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->tableName = (new TableNames($wpdb->prefix))->repoOperations();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function start(string $managedSetKey, string $operationType, array $payload = []): OperationRecord
    {
        $this->wpdb->insert($this->tableName, [
            'managed_set_key' => $managedSetKey,
            'operation_type' => $operationType,
            'status' => self::STATUS_RUNNING,
            'payload' => $payload === [] ? null : wp_json_encode($payload),
            'result' => null,
            'created_by' => $this->currentUserId(),
            'created_at' => current_time('mysql', true),
            'finished_at' => null,
        ]);

        return $this->find((int) ($this->wpdb->insert_id ?? 0))
            ?? throw new \RuntimeException('Operation log record could not be created.');
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markSucceeded(int $operationId, array $result = []): OperationRecord
    {
        return $this->updateStatus($operationId, self::STATUS_SUCCEEDED, $result);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markFailed(int $operationId, array $result = []): OperationRecord
    {
        return $this->updateStatus($operationId, self::STATUS_FAILED, $result);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function updateRunning(int $operationId, array $result = []): OperationRecord
    {
        return $this->replaceRecord($operationId, self::STATUS_RUNNING, $result, null);
    }

    public function find(int $operationId): ?OperationRecord
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->tableName} WHERE id = %s", (string) $operationId),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return OperationRecord[]
     */
    public function all(): array
    {
        $rows = $this->wpdb->get_results("SELECT * FROM {$this->tableName}", ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map(fn (array $row): OperationRecord => $this->hydrate($row), $rows);
    }

    /**
     * @return OperationRecord[]
     */
    public function recent(int $limit = 50): array
    {
        $records = $this->all();

        usort($records, static function (OperationRecord $left, OperationRecord $right): int {
            if ($left->id === $right->id) {
                return 0;
            }

            return $left->id > $right->id ? -1 : 1;
        });

        return array_slice($records, 0, max(1, $limit));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function updateStatus(int $operationId, string $status, array $result): OperationRecord
    {
        return $this->replaceRecord($operationId, $status, $result, current_time('mysql', true));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function replaceRecord(int $operationId, string $status, array $result, ?string $finishedAt): OperationRecord
    {
        $record = $this->find($operationId);

        if ($record === null) {
            throw new \RuntimeException(sprintf('Operation log %d could not be found.', $operationId));
        }

        $this->wpdb->replace($this->tableName, [
            'id' => $record->id,
            'managed_set_key' => $record->managedSetKey,
            'operation_type' => $record->operationType,
            'status' => $status,
            'payload' => $record->payload === [] ? null : wp_json_encode($record->payload),
            'result' => $result === [] ? null : wp_json_encode($result),
            'created_by' => $record->createdBy,
            'created_at' => $record->createdAt,
            'finished_at' => $finishedAt,
        ]);

        return $this->find($operationId)
            ?? throw new \RuntimeException(sprintf('Operation log %d could not be updated.', $operationId));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OperationRecord
    {
        return new OperationRecord(
            (int) ($row['id'] ?? 0),
            (string) ($row['managed_set_key'] ?? ''),
            (string) ($row['operation_type'] ?? ''),
            (string) ($row['status'] ?? ''),
            $this->decodeJson($row['payload'] ?? null),
            $this->decodeJson($row['result'] ?? null),
            isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
            (string) ($row['created_at'] ?? ''),
            isset($row['finished_at']) && $row['finished_at'] !== null ? (string) $row['finished_at'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function currentUserId(): ?int
    {
        if (! function_exists('get_current_user_id')) {
            return null;
        }

        $userId = get_current_user_id();

        return $userId > 0 ? (int) $userId : null;
    }
}
