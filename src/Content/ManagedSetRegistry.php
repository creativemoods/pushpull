<?php

declare(strict_types=1);

namespace PushPull\Content;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use RuntimeException;

final class ManagedSetRegistry
{
    /** @var array<string, ManifestManagedContentAdapterInterface> */
    private array $adaptersByManagedSetKey;

    /**
     * @param ManifestManagedContentAdapterInterface[] $adapters
     */
    public function __construct(array $adapters)
    {
        $this->adaptersByManagedSetKey = [];

        foreach ($adapters as $adapter) {
            $this->adaptersByManagedSetKey[$adapter->getManagedSetKey()] = $adapter;
        }
    }

    /**
     * @return array<string, ManifestManagedContentAdapterInterface>
     */
    public function all(): array
    {
        return $this->adaptersByManagedSetKey;
    }

    public function get(string $managedSetKey): ManifestManagedContentAdapterInterface
    {
        if (! isset($this->adaptersByManagedSetKey[$managedSetKey])) {
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        return $this->adaptersByManagedSetKey[$managedSetKey];
    }

    public function has(string $managedSetKey): bool
    {
        return isset($this->adaptersByManagedSetKey[$managedSetKey]);
    }

    public function first(): ManifestManagedContentAdapterInterface
    {
        $first = reset($this->adaptersByManagedSetKey);

        if (! $first instanceof ManifestManagedContentAdapterInterface) {
            throw new RuntimeException('No managed content adapters are registered.');
        }

        return $first;
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        $labels = [];

        foreach ($this->adaptersByManagedSetKey as $managedSetKey => $adapter) {
            $labels[$managedSetKey] = $adapter->getManagedSetLabel();
        }

        return $labels;
    }
}
