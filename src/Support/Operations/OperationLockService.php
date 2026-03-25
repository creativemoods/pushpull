<?php

declare(strict_types=1);

namespace PushPull\Support\Operations;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

final class OperationLockService
{
    private const LOCK_OPTION = 'pushpull_repo_lock';

    public function __construct(private readonly int $ttlSeconds = 300)
    {
    }

    public function acquire(string $operationType, string $managedSetKey, int $operationId): OperationLock
    {
        $existing = get_option(self::LOCK_OPTION, null);

        if (is_array($existing) && ! $this->isExpired($existing)) {
            throw new ConcurrentOperationException($this->lockMessage($existing));
        }

        if (is_array($existing) && $this->isExpired($existing)) {
            delete_option(self::LOCK_OPTION);
        }

        $payload = [
            'token' => $this->generateToken($operationType, $managedSetKey, $operationId),
            'operationType' => $operationType,
            'managedSetKey' => $managedSetKey,
            'operationId' => $operationId,
            'expiresAt' => time() + $this->ttlSeconds,
        ];

        if (! add_option(self::LOCK_OPTION, $payload)) {
            $existing = get_option(self::LOCK_OPTION, null);
            throw new ConcurrentOperationException($this->lockMessage(is_array($existing) ? $existing : []));
        }

        return new OperationLock(self::LOCK_OPTION, (string) $payload['token']);
    }

    public function release(OperationLock $lock): void
    {
        $existing = get_option($lock->optionKey, null);

        if (! is_array($existing)) {
            return;
        }

        if (($existing['token'] ?? null) !== $lock->token) {
            return;
        }

        delete_option($lock->optionKey);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isExpired(array $payload): bool
    {
        $expiresAt = (int) ($payload['expiresAt'] ?? 0);

        return $expiresAt > 0 && $expiresAt < time();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function lockMessage(array $payload): string
    {
        $operationType = (string) ($payload['operationType'] ?? 'unknown');
        $managedSetKey = (string) ($payload['managedSetKey'] ?? '');

        if ($managedSetKey !== '') {
            return sprintf(
                'Another PushPull operation is already running for %s (%s).',
                $managedSetKey,
                $operationType
            );
        }

        return sprintf('Another PushPull repository operation is already running (%s).', $operationType);
    }

    private function generateToken(string $operationType, string $managedSetKey, int $operationId): string
    {
        return sha1($operationType . '|' . $managedSetKey . '|' . $operationId . '|' . microtime(true));
    }
}
