<?php

declare(strict_types=1);

namespace PushPull\Domain\Merge;

final class JsonThreeWayMerger
{
    /**
     * @return array{content: ?string, conflictPaths: string[]}
     */
    public function merge(?string $baseContent, ?string $oursContent, ?string $theirsContent): array
    {
        if ($oursContent === $theirsContent) {
            return ['content' => $oursContent, 'conflictPaths' => []];
        }

        if ($baseContent === $oursContent) {
            return ['content' => $theirsContent, 'conflictPaths' => []];
        }

        if ($baseContent === $theirsContent) {
            return ['content' => $oursContent, 'conflictPaths' => []];
        }

        if ($baseContent === null) {
            if ($oursContent === null || $theirsContent === null) {
                return ['content' => null, 'conflictPaths' => ['$']];
            }

            if ($oursContent === $theirsContent) {
                return ['content' => $oursContent, 'conflictPaths' => []];
            }
        }

        if ($oursContent === null || $theirsContent === null) {
            return ['content' => null, 'conflictPaths' => ['$']];
        }

        $baseJson = json_decode($baseContent ?? 'null', true);
        $oursJson = json_decode($oursContent, true);
        $theirsJson = json_decode($theirsContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['content' => null, 'conflictPaths' => ['$']];
        }

        $merged = $this->mergeNode($baseJson, $oursJson, $theirsJson, '$');

        if ($merged['conflictPaths'] !== []) {
            return ['content' => null, 'conflictPaths' => $merged['conflictPaths']];
        }

        $encoded = wp_json_encode($merged['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            return ['content' => null, 'conflictPaths' => ['$']];
        }

        return ['content' => $encoded . "\n", 'conflictPaths' => []];
    }

    /**
     * @return array{value: mixed, conflictPaths: string[]}
     */
    private function mergeNode(mixed $base, mixed $ours, mixed $theirs, string $path): array
    {
        if ($ours === $theirs) {
            return ['value' => $ours, 'conflictPaths' => []];
        }

        if ($base === $ours) {
            return ['value' => $theirs, 'conflictPaths' => []];
        }

        if ($base === $theirs) {
            return ['value' => $ours, 'conflictPaths' => []];
        }

        if ($this->isAssociativeArray($base) && $this->isAssociativeArray($ours) && $this->isAssociativeArray($theirs)) {
            $keys = array_unique(array_merge(array_keys($base), array_keys($ours), array_keys($theirs)));
            sort($keys);
            $result = [];
            $conflicts = [];

            foreach ($keys as $key) {
                $child = $this->mergeNode(
                    $base[$key] ?? null,
                    $ours[$key] ?? null,
                    $theirs[$key] ?? null,
                    $path . '.' . $key
                );

                if ($child['conflictPaths'] !== []) {
                    $conflicts = array_merge($conflicts, $child['conflictPaths']);
                    continue;
                }

                if ($child['value'] !== null || array_key_exists($key, $ours) || array_key_exists($key, $theirs)) {
                    $result[$key] = $child['value'];
                }
            }

            return ['value' => $result, 'conflictPaths' => $conflicts];
        }

        if ($this->isListArray($base) && $this->isListArray($ours) && $this->isListArray($theirs)) {
            return ['value' => null, 'conflictPaths' => [$path]];
        }

        return ['value' => null, 'conflictPaths' => [$path]];
    }

    private function isAssociativeArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) === false;
    }

    private function isListArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }
}
