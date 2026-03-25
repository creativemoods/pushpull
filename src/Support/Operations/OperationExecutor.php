<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

use PushPull\Persistence\Operations\OperationLogRepository;
use Throwable;

final class OperationExecutor
{
    public function __construct(
        private readonly OperationLogRepository $operationLogRepository,
        private readonly OperationLockService $operationLockService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(): mixed $callback
     */
    public function run(string $managedSetKey, string $operationType, array $payload, callable $callback): mixed
    {
        $record = $this->operationLogRepository->start($managedSetKey, $operationType, $payload);

        try {
            $lock = $this->operationLockService->acquire($operationType, $managedSetKey, $record->id);
        } catch (Throwable $exception) {
            $this->operationLogRepository->markFailed($record->id, $this->normalizeThrowable($exception));
            throw $exception;
        }

        try {
            $result = $callback();
            $this->operationLogRepository->markSucceeded($record->id, $this->normalizeValue($result));

            return $result;
        } catch (Throwable $exception) {
            $this->operationLogRepository->markFailed($record->id, $this->normalizeThrowable($exception));
            throw $exception;
        } finally {
            $this->operationLockService->release($lock);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeThrowable(Throwable $throwable): array
    {
        $result = [
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
        ];

        if (property_exists($throwable, 'category') && is_string($throwable->category)) {
            $result['category'] = $throwable->category;
        }

        if (property_exists($throwable, 'statusCode') && is_int($throwable->statusCode)) {
            $result['statusCode'] = $throwable->statusCode;
        }

        if (property_exists($throwable, 'operation') && is_string($throwable->operation)) {
            $result['providerOperation'] = $throwable->operation;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeValue(mixed $value, int $depth = 0): array
    {
        if ($depth > 5) {
            return ['summary' => 'depth_limited'];
        }

        if (is_scalar($value) || $value === null) {
            return ['value' => $value];
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[(string) $key] = $this->normalizeMixed($item, $depth + 1);
            }

            return $normalized;
        }

        if (is_object($value)) {
            $normalized = ['class' => $value::class];

            foreach (get_object_vars($value) as $key => $item) {
                $normalized[(string) $key] = $this->normalizeMixed($item, $depth + 1);
            }

            return $normalized;
        }

        return ['value' => (string) $value];
    }

    private function normalizeMixed(mixed $value, int $depth): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return $this->normalizeValue($value, $depth);
    }
}
