<?php

declare(strict_types=1);

namespace PushPull\Content;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use RuntimeException;

final class ManagedSetRegistry
{
    /** @var array<string, ManifestManagedContentAdapterInterface> */
    private array $adaptersByManagedSetKey;
    /** @var array<int, callable(): array<int, ManifestManagedContentAdapterInterface>> */
    private array $dynamicAdapterResolvers;

    /**
     * @param ManifestManagedContentAdapterInterface[] $adapters
     * @param array<int, callable(): array<int, ManifestManagedContentAdapterInterface>> $dynamicAdapterResolvers
     */
    public function __construct(array $adapters, array $dynamicAdapterResolvers = [])
    {
        $this->adaptersByManagedSetKey = [];
        $this->dynamicAdapterResolvers = $dynamicAdapterResolvers;

        foreach ($adapters as $adapter) {
            $this->adaptersByManagedSetKey[$adapter->getManagedSetKey()] = $adapter;
        }
    }

    /**
     * @return array<string, ManifestManagedContentAdapterInterface>
     */
    public function all(): array
    {
        return $this->resolvedAdapters();
    }

    /**
     * @return array<string, ManifestManagedContentAdapterInterface>
     */
    public function allInDependencyOrder(): array
    {
        $resolved = $this->resolvedAdapters();
        $ordered = [];

        foreach ($this->sortManagedSetKeysInDependencyOrder(array_keys($resolved)) as $managedSetKey) {
            $ordered[$managedSetKey] = $resolved[$managedSetKey];
        }

        return $ordered;
    }

    public function get(string $managedSetKey): ManifestManagedContentAdapterInterface
    {
        if (! isset($this->adaptersByManagedSetKey[$managedSetKey])) {
            $this->refreshDynamicAdapters();
        }

        if (! isset($this->adaptersByManagedSetKey[$managedSetKey])) {
            throw new RuntimeException(sprintf('Managed set "%s" is not supported.', $managedSetKey));
        }

        return $this->adaptersByManagedSetKey[$managedSetKey];
    }

    public function has(string $managedSetKey): bool
    {
        $this->refreshDynamicAdapters();

        return isset($this->adaptersByManagedSetKey[$managedSetKey]);
    }

    public function first(): ManifestManagedContentAdapterInterface
    {
        $first = reset($this->allInDependencyOrder());

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

        foreach ($this->allInDependencyOrder() as $managedSetKey => $adapter) {
            $labels[$managedSetKey] = $adapter->getManagedSetLabel();
        }

        return $labels;
    }

    /**
     * @param string[] $managedSetKeys
     * @return string[]
     */
    public function sortManagedSetKeysInDependencyOrder(array $managedSetKeys): array
    {
        $managedSetKeys = array_values(array_unique(array_filter(array_map('strval', $managedSetKeys))));
        $knownKeys = [];

        foreach ($managedSetKeys as $managedSetKey) {
            if ($this->has($managedSetKey)) {
                $knownKeys[] = $managedSetKey;
            }
        }

        $adapterOrder = array_flip(array_keys($this->adaptersByManagedSetKey));
        $inDegree = array_fill_keys($knownKeys, 0);
        $dependents = array_fill_keys($knownKeys, []);

        foreach ($knownKeys as $managedSetKey) {
            $adapter = $this->adaptersByManagedSetKey[$managedSetKey];
            $dependencies = $adapter instanceof ManagedSetDependencyAwareInterface
                ? $adapter->getManagedSetDependencies()
                : [];

            foreach ($dependencies as $dependencyKey) {
                if (! isset($inDegree[$dependencyKey])) {
                    continue;
                }

                $dependents[$dependencyKey][] = $managedSetKey;
                $inDegree[$managedSetKey]++;
            }
        }

        $queue = array_values(array_filter(
            $knownKeys,
            static fn (string $managedSetKey): bool => $inDegree[$managedSetKey] === 0
        ));
        usort(
            $queue,
            static fn (string $left, string $right): int => ($adapterOrder[$left] ?? PHP_INT_MAX) <=> ($adapterOrder[$right] ?? PHP_INT_MAX)
        );

        $resolved = [];

        while ($queue !== []) {
            $managedSetKey = array_shift($queue);

            if ($managedSetKey === null) {
                continue;
            }

            $resolved[] = $managedSetKey;

            foreach ($dependents[$managedSetKey] as $dependentKey) {
                $inDegree[$dependentKey]--;

                if ($inDegree[$dependentKey] === 0) {
                    $queue[] = $dependentKey;
                }
            }

            usort(
                $queue,
                static fn (string $left, string $right): int => ($adapterOrder[$left] ?? PHP_INT_MAX) <=> ($adapterOrder[$right] ?? PHP_INT_MAX)
            );
        }

        if (count($resolved) !== count($knownKeys)) {
            $cyclicKeys = array_values(array_filter(
                $knownKeys,
                static fn (string $managedSetKey): bool => ! in_array($managedSetKey, $resolved, true)
            ));
            sort($cyclicKeys);

            throw new RuntimeException(sprintf(
                'Managed set dependency cycle detected: %s',
                implode(', ', $cyclicKeys)
            ));
        }

        return $resolved;
    }

    /**
     * @return array<string, ManifestManagedContentAdapterInterface>
     */
    private function resolvedAdapters(): array
    {
        $this->refreshDynamicAdapters();

        return $this->adaptersByManagedSetKey;
    }

    private function refreshDynamicAdapters(): void
    {
        foreach ($this->dynamicAdapterResolvers as $resolver) {
            foreach ($resolver() as $adapter) {
                if (! $adapter instanceof ManifestManagedContentAdapterInterface) {
                    continue;
                }

                $this->adaptersByManagedSetKey[$adapter->getManagedSetKey()] = $adapter;
            }
        }
    }
}
