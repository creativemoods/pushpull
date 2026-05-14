<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Persistence\Operations\OperationLogRepository;
use PushPull\Persistence\Operations\OperationRecord;
use RuntimeException;

final class AsyncOperationEngine
{
    public function __construct(
        private readonly OperationLogRepository $operationLogRepository,
        private readonly OperationLockService $operationLockService,
        private readonly AsyncOperationHandlerInterface $handler
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{operationId: int, progressMessage: string, done: bool, status?: string, redirectUrl?: string, progress: array<string, mixed>}
     */
    public function start(string $managedSetKey, string $operationType, array $payload = []): array
    {
        if (! $this->handler->supportsAsyncOperation($operationType)) {
            throw new RuntimeException(sprintf('Async operation "%s" is not supported.', $operationType));
        }

        $record = $this->operationLogRepository->start($managedSetKey, $operationType, $payload);
        $lock = null;

        try {
            $lock = $this->operationLockService->acquire($operationType, $managedSetKey, $record->id);
            $state = $this->handler->initializeAsyncOperation($record, $payload, $lock->token);

            if (($state['phase'] ?? '') === 'complete') {
                $this->handler->finalizeAsyncOperation($state);
                $this->operationLogRepository->markSucceeded($record->id, $state);
                $this->operationLockService->release($lock);

                return [
                    'operationId' => $record->id,
                    'progressMessage' => (string) $state['summaryMessage'],
                    'done' => true,
                    'status' => (string) $state['summaryType'],
                    'redirectUrl' => (string) $state['redirectUrl'],
                    'progress' => $this->progressPayload($state),
                ];
            }

            $response = $this->handler->continueAsyncOperation($record, $state);

            if ($response['done']) {
                $this->handler->finalizeAsyncOperation($response['finalResult']);
                $this->operationLogRepository->markSucceeded($record->id, $response['finalResult']);
                $this->operationLockService->release($lock);

                return [
                    'operationId' => $record->id,
                    'progressMessage' => (string) $response['finalResult']['summaryMessage'],
                    'done' => true,
                    'status' => (string) $response['finalResult']['summaryType'],
                    'redirectUrl' => (string) ($response['finalResult']['redirectUrl'] ?? ''),
                    'progress' => $this->progressPayload($response['finalResult']),
                ];
            }

            $this->operationLogRepository->updateRunning($record->id, $response['state']);

            return [
                'operationId' => $record->id,
                'progressMessage' => (string) $response['state']['progressMessage'],
                'done' => false,
                'progress' => $this->progressPayload($response['state']),
            ];
        } catch (\Throwable $exception) {
            $this->operationLogRepository->markFailed($record->id, $this->normalizeFailure($exception));

            if ($lock !== null) {
                $this->operationLockService->release($lock);
            }

            throw $exception;
        }
    }

    /**
     * @return array{done: bool, status: string, message: string, progress: array<string, mixed>, redirectManagedSetKey?: string, redirectPageSlug?: ?string}
     */
    public function continue(int $operationId): array
    {
        $record = $this->operationLogRepository->find($operationId);

        if ($record === null) {
            throw new RuntimeException(sprintf('Operation log %d could not be found.', $operationId));
        }

        if ($record->status !== OperationLogRepository::STATUS_RUNNING) {
            $summaryType = (string) ($record->result['summaryType'] ?? ($record->status === OperationLogRepository::STATUS_SUCCEEDED ? 'success' : 'error'));
            $summaryMessage = (string) ($record->result['summaryMessage'] ?? 'Operation finished.');

            return [
                'done' => true,
                'status' => $summaryType,
                'message' => $summaryMessage,
                'progress' => $this->progressPayload($record->result),
                'redirectManagedSetKey' => is_string($record->result['redirectManagedSetKey'] ?? null) ? $record->result['redirectManagedSetKey'] : null,
                'redirectPageSlug' => is_string($record->payload['sourcePage'] ?? null) ? $record->payload['sourcePage'] : null,
            ];
        }

        $state = $record->result;
        $token = (string) ($state['lockToken'] ?? '');

        if ($token === '') {
            throw new RuntimeException(sprintf('Operation log %d is missing its lock token.', $record->id));
        }

        $lock = $this->operationLockService->restore($token);
        $this->operationLockService->refresh($lock);

        try {
            $response = $this->handler->continueAsyncOperation($record, $state);

            if (! $response['done']) {
                $this->operationLogRepository->updateRunning($record->id, $response['state']);

                return [
                    'done' => false,
                    'status' => 'running',
                    'message' => (string) $response['state']['progressMessage'],
                    'progress' => $this->progressPayload($response['state']),
                ];
            }

            $this->handler->finalizeAsyncOperation($response['finalResult']);
            $this->operationLogRepository->markSucceeded($record->id, $response['finalResult']);
            $this->operationLockService->release($lock);

            return [
                'done' => true,
                'status' => (string) $response['finalResult']['summaryType'],
                'message' => (string) $response['finalResult']['summaryMessage'],
                'progress' => $this->progressPayload($response['finalResult']),
                'redirectManagedSetKey' => is_string($response['finalResult']['redirectManagedSetKey'] ?? null) ? $response['finalResult']['redirectManagedSetKey'] : null,
                'redirectPageSlug' => is_string($record->payload['sourcePage'] ?? null) ? $record->payload['sourcePage'] : null,
            ];
        } catch (\Throwable $exception) {
            $this->operationLogRepository->markFailed($record->id, $this->normalizeFailure($exception));
            $this->operationLockService->release($lock);
            throw $exception;
        }
    }

    public function cancel(int $operationId): OperationRecord
    {
        $record = $this->operationLogRepository->find($operationId);

        if ($record === null) {
            throw new RuntimeException(sprintf('Operation log %d could not be found.', $operationId));
        }

        if ($record->status !== OperationLogRepository::STATUS_RUNNING) {
            return $record;
        }

        $lockToken = (string) ($record->result['lockToken'] ?? '');

        if ($lockToken === '') {
            throw new RuntimeException('This operation cannot be cancelled because it is not a resumable async branch action.');
        }

        $cancelled = $this->operationLogRepository->markCancelled($record->id, array_merge(
            $record->result,
            [
                'summaryType' => 'warning',
                'summaryMessage' => 'Operation cancelled. No further async steps will run.',
                'cancelled' => true,
            ]
        ));
        $this->operationLockService->releaseByToken($lockToken);

        return $cancelled;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function progressPayload(array $state): array
    {
        return [
            'mode' => (string) ($state['progressMode'] ?? 'indeterminate'),
            'current' => (int) ($state['progressCurrent'] ?? 0),
            'total' => (int) ($state['progressTotal'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFailure(\Throwable $exception): array
    {
        return [
            'summaryType' => 'error',
            'summaryMessage' => $exception->getMessage(),
            'errorClass' => $exception::class,
        ];
    }
}
