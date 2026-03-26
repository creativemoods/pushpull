<?php

declare(strict_types=1);

namespace PushPull\Content\GenerateBlocks;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Content\Exception\ManagedContentExportException;

final class GenerateBlocksConditionKeyGenerator
{
    public function fromIdentifier(string $identifier): string
    {
        $normalized = $this->normalize($identifier);

        if ($normalized === '') {
            throw new ManagedContentExportException('GenerateBlocks condition produced an empty logical key.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $normalized)) {
            throw new ManagedContentExportException(
                sprintf('GenerateBlocks condition logical key "%s" is not path-safe.', $normalized)
            );
        }

        return $normalized;
    }

    /**
     * @param string[] $logicalKeys
     */
    public function assertUnique(array $logicalKeys): void
    {
        $seen = [];

        foreach ($logicalKeys as $logicalKey) {
            if (isset($seen[$logicalKey])) {
                throw new ManagedContentExportException(
                    sprintf('Duplicate GenerateBlocks condition logical key detected: %s', $logicalKey)
                );
            }

            $seen[$logicalKey] = true;
        }
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', '-', $value) ?? '';
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';

        return trim($value, '-_');
    }
}
