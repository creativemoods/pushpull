<?php

declare(strict_types=1);

namespace PushPull\Secrets;

final class SecretEnvelopeStore
{
    private const OPTION_NAME = 'pushpull_secret_envelopes';

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $managedSetKey, string $logicalKey, string $binding): ?array
    {
        $all = $this->all();
        $envelope = $all[$managedSetKey][$logicalKey][$binding] ?? null;

        return is_array($envelope) ? $envelope : null;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function put(string $managedSetKey, string $logicalKey, string $binding, array $envelope): void
    {
        $all = $this->all();
        $all[$managedSetKey][$logicalKey][$binding] = $envelope;
        update_option(self::OPTION_NAME, $all, false);
    }

    public function forget(string $managedSetKey, string $logicalKey, string $binding): void
    {
        $all = $this->all();

        unset($all[$managedSetKey][$logicalKey][$binding]);

        if (($all[$managedSetKey][$logicalKey] ?? []) === []) {
            unset($all[$managedSetKey][$logicalKey]);
        }

        if (($all[$managedSetKey] ?? []) === []) {
            unset($all[$managedSetKey]);
        }

        update_option(self::OPTION_NAME, $all, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $stored = get_option(self::OPTION_NAME, []);

        return is_array($stored) ? $stored : [];
    }
}
