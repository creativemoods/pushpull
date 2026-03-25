<?php

declare(strict_types=1);

namespace PushPull\Domain\Diff;

use PushPull\Domain\Repository\LocalRepositoryInterface;

final class RepositoryStateReader
{
    public function __construct(private readonly LocalRepositoryInterface $localRepository)
    {
    }

    public function read(string $source, string $refName): CanonicalManagedState
    {
        $ref = $this->localRepository->getRef($refName);

        if ($ref === null || $ref->commitHash === '') {
            return new CanonicalManagedState($source, $refName, null, null, []);
        }

        $commit = $this->localRepository->getCommit($ref->commitHash);

        if ($commit === null) {
            return new CanonicalManagedState($source, $refName, $ref->commitHash, null, []);
        }

        return $this->readCommitState($source, $refName, $commit->hash, $commit->treeHash);
    }

    public function readCommit(string $source, string $commitHash): CanonicalManagedState
    {
        $commit = $this->localRepository->getCommit($commitHash);

        if ($commit === null) {
            return new CanonicalManagedState($source, null, $commitHash, null, []);
        }

        return $this->readCommitState($source, null, $commit->hash, $commit->treeHash);
    }

    private function readCommitState(string $source, ?string $refName, string $commitHash, string $treeHash): CanonicalManagedState
    {
        $tree = $this->localRepository->getTree($treeHash);

        if ($tree === null) {
            return new CanonicalManagedState($source, $refName, $commitHash, $treeHash, []);
        }

        $files = $this->readTreeFiles($treeHash);

        ksort($files);

        return new CanonicalManagedState($source, $refName, $commitHash, $treeHash, $files);
    }

    /**
     * @return array<string, CanonicalManagedFile>
     */
    private function readTreeFiles(string $treeHash, string $prefix = ''): array
    {
        $tree = $this->localRepository->getTree($treeHash);

        if ($tree === null) {
            return [];
        }

        $files = [];

        foreach ($tree->entries as $entry) {
            $path = $prefix !== '' ? $prefix . '/' . $entry->path : $entry->path;

            if ($entry->type === 'tree') {
                foreach ($this->readTreeFiles($entry->hash, $path) as $childPath => $file) {
                    $files[$childPath] = $file;
                }

                continue;
            }

            if ($entry->type !== 'blob') {
                continue;
            }

            $blob = $this->localRepository->getBlob($entry->hash);

            if ($blob === null) {
                continue;
            }

            $files[$path] = new CanonicalManagedFile($path, $blob->content);
        }

        return $files;
    }
}
