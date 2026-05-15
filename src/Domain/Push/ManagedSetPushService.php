<?php

declare(strict_types=1);

namespace PushPull\Domain\Push;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Domain\Repository\LocalRepositoryInterface;
use PushPull\Provider\GitLab\GitLabProvider;
use PushPull\Provider\CreateRemoteCommitRequest;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteTree;
use PushPull\Provider\UpdateRemoteRefRequest;
use PushPull\Settings\PushPullSettings;
use RuntimeException;

final class ManagedSetPushService
{
    public function __construct(
        private readonly LocalRepositoryInterface $localRepository,
        private readonly GitProviderFactoryInterface $providerFactory
    ) {
    }

    public function push(string $managedSetKey, PushPullSettings $settings): PushManagedSetResult
    {
        $state = $this->initializePushState($settings);

        while (($state['phase'] ?? '') !== 'complete') {
            $state = $this->continuePushState($settings, $state, PHP_INT_MAX);
        }

        return $this->resultFromState($managedSetKey, $settings, $state);
    }

    /**
     * @return array<string, mixed>
     */
    public function initializePushState(PushPullSettings $settings): array
    {
        $localRef = $this->localRepository->getRef('refs/heads/' . $settings->branch);
        $trackingRef = $this->localRepository->getRef('refs/remotes/origin/' . $settings->branch);

        if ($localRef === null || $localRef->commitHash === '') {
            throw new RuntimeException(sprintf('Local branch %s does not have a commit to push.', $settings->branch));
        }

        if ($trackingRef === null || $trackingRef->commitHash === '') {
            throw new RuntimeException(sprintf('Remote tracking branch %s has not been fetched yet.', $settings->branch));
        }

        $relationship = $this->determineRelationship($localRef->commitHash, $trackingRef->commitHash);

        if ($relationship === 'in_sync') {
            return [
                'phase' => 'complete',
                'status' => 'already_up_to_date',
                'remoteCommitHash' => $trackingRef->commitHash,
                'localHeadHash' => $localRef->commitHash,
                'pushedCommitHashes' => [],
                'pushedTreeHashes' => [],
                'pushedBlobHashes' => [],
                'progressMode' => 'determinate',
                'progressCurrent' => 1,
                'progressTotal' => 1,
                'progressMessage' => sprintf('Local branch %s is already up to date on the provider.', $settings->branch),
            ];
        }

        if ($relationship !== 'ahead') {
            throw new RuntimeException(sprintf(
                'Local branch %s cannot be pushed because it is %s relative to the fetched remote state.',
                $settings->branch,
                str_replace('_', ' ', $relationship)
            ));
        }

        [$provider, $remoteConfig] = $this->resolveProvider($settings);
        $remoteRefName = 'refs/heads/' . $settings->branch;
        $currentRemoteRef = $provider->getRef($remoteConfig, $remoteRefName);

        if ($currentRemoteRef === null || $currentRemoteRef->commitHash === '') {
            throw new RuntimeException(sprintf('Remote branch %s does not exist or cannot be updated safely.', $settings->branch));
        }

        if ($currentRemoteRef->commitHash !== $trackingRef->commitHash) {
            throw new RuntimeException(sprintf(
                'Remote branch %s has changed since the last fetch. Fetch again before pushing.',
                $settings->branch
            ));
        }

        if ($provider instanceof GitLabProvider) {
            $provider->primeCurrentFiles(
                $trackingRef->commitHash,
                $this->materializeLocalCommitFiles($trackingRef->commitHash)
            );
        }

        $commitOrder = [];
        $this->collectCommitPushOrder($localRef->commitHash, $trackingRef->commitHash, [], $commitOrder);
        $treeOrder = [];
        $blobHashes = [];
        $seenTrees = [];
        $treeMap = [];
        $blobMap = [];
        $processedCommits = [];
        $remoteBaseCommit = $this->localRepository->getCommit($trackingRef->commitHash);

        foreach ($commitOrder as $commitHash) {
            $commit = $this->localRepository->getCommit($commitHash);

            if ($commit === null) {
                throw new RuntimeException(sprintf('Local commit %s could not be found for push planning.', $commitHash));
            }

            if ($commit->secondParentHash === null && $commit->parentHash === $trackingRef->commitHash && $remoteBaseCommit !== null) {
                $this->collectTreePushPlanAgainstRemote(
                    $commit->treeHash,
                    $remoteBaseCommit->treeHash,
                    $seenTrees,
                    $treeOrder,
                    $blobHashes,
                    $treeMap,
                    $blobMap
                );
                $processedCommits[$commitHash] = true;
                continue;
            }

            if (
                $commit->secondParentHash === null
                && is_string($commit->parentHash)
                && $commit->parentHash !== ''
                && isset($processedCommits[$commit->parentHash])
            ) {
                $parentCommit = $this->localRepository->getCommit($commit->parentHash);

                if ($parentCommit !== null) {
                    $this->collectTreePushPlanAgainstLocal(
                        $commit->treeHash,
                        $parentCommit->treeHash,
                        $seenTrees,
                        $treeOrder,
                        $blobHashes
                    );
                    $processedCommits[$commitHash] = true;
                    continue;
                }
            }

            $this->collectTreePushPlan($commit->treeHash, $seenTrees, $treeOrder, $blobHashes);
            $processedCommits[$commitHash] = true;
        }

        $blobOrder = array_values(array_keys($blobHashes));
        $totalSteps = count($blobOrder) + count($treeOrder) + count($commitOrder) + 1;

        return [
            'phase' => 'push_blobs',
            'stopAtRemoteHash' => $trackingRef->commitHash,
            'remoteRefName' => $remoteRefName,
            'localHeadHash' => $localRef->commitHash,
            'blobOrder' => $blobOrder,
            'treeOrder' => $treeOrder,
            'commitOrder' => $commitOrder,
            'blobMap' => $blobMap,
            'treeMap' => $treeMap,
            'commitMap' => [],
            'pushedBlobHashes' => [],
            'pushedTreeHashes' => [],
            'pushedCommitHashes' => [],
            'blobIndex' => 0,
            'treeIndex' => 0,
            'commitIndex' => 0,
            'progressMode' => 'determinate',
            'progressCurrent' => 0,
            'progressTotal' => max(1, $totalSteps),
            'progressMessage' => sprintf(
                'Prepared push plan for branch %s: %d blob(s), %d tree(s), and %d commit(s).',
                $settings->branch,
                count($blobOrder),
                count($treeOrder),
                count($commitOrder)
            ),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function continuePushState(PushPullSettings $settings, array $state, int $budget): array
    {
        [$provider, $remoteConfig] = $this->resolveProvider($settings);
        $this->rehydrateGitLabPushState($provider, $remoteConfig, $state);

        while ($budget > 0 && ($state['phase'] ?? '') !== 'complete') {
            if ((int) $state['blobIndex'] < count($state['blobOrder'])) {
                $localBlobHash = (string) $state['blobOrder'][(int) $state['blobIndex']];
                $blob = $this->localRepository->getBlob($localBlobHash);

                if ($blob === null) {
                    throw new RuntimeException(sprintf('Local blob %s could not be found for push.', $localBlobHash));
                }

                $remoteBlobHash = $provider->createBlob($remoteConfig, $blob->content);
                $state['blobMap'][$localBlobHash] = $remoteBlobHash;
                $state['pushedBlobHashes'][] = $remoteBlobHash;
                $this->localRepository->importRemoteBlob(new RemoteBlob($remoteBlobHash, $blob->content));
                $state['blobIndex']++;
                $state['progressCurrent']++;
                $state['progressMessage'] = $this->pushProgressMessage($state, sprintf('Uploaded blob %s.', $localBlobHash));
                $budget--;
                continue;
            }

            if ((int) $state['treeIndex'] < count($state['treeOrder'])) {
                $localTreeHash = (string) $state['treeOrder'][(int) $state['treeIndex']];
                $tree = $this->localRepository->getTree($localTreeHash);

                if ($tree === null) {
                    throw new RuntimeException(sprintf('Local tree %s could not be found for push.', $localTreeHash));
                }

                $entries = [];

                foreach ($tree->entries as $entry) {
                    $remoteHash = $entry->type === 'blob'
                        ? (string) ($state['blobMap'][$entry->hash] ?? '')
                        : (string) ($state['treeMap'][$entry->hash] ?? '');

                    if ($remoteHash === '') {
                        throw new RuntimeException(sprintf('Dependent object %s was not uploaded before tree %s.', $entry->hash, $localTreeHash));
                    }

                    $entries[] = [
                        'path' => $entry->path,
                        'type' => $entry->type,
                        'hash' => $remoteHash,
                    ];
                }

                $remoteTreeHash = $provider->createTree($remoteConfig, $entries);
                $state['treeMap'][$localTreeHash] = $remoteTreeHash;
                $state['pushedTreeHashes'][] = $remoteTreeHash;
                $this->localRepository->importRemoteTree(new RemoteTree($remoteTreeHash, $entries));
                $state['treeIndex']++;
                $state['progressCurrent']++;
                $state['progressMessage'] = $this->pushProgressMessage($state, sprintf('Uploaded tree %s.', $localTreeHash));
                $budget--;
                continue;
            }

            if ((int) $state['commitIndex'] < count($state['commitOrder'])) {
                $localCommitHash = (string) $state['commitOrder'][(int) $state['commitIndex']];
                $localCommit = $this->localRepository->getCommit($localCommitHash);

                if ($localCommit === null) {
                    throw new RuntimeException(sprintf('Local commit %s could not be found for push.', $localCommitHash));
                }

                $remoteParentHashes = [];

                foreach ([$localCommit->parentHash, $localCommit->secondParentHash] as $parentHash) {
                    if ($parentHash === null || $parentHash === '') {
                        continue;
                    }

                    if ($parentHash === $state['stopAtRemoteHash']) {
                        $remoteParentHashes[] = $parentHash;
                        continue;
                    }

                    $remoteParentHash = (string) ($state['commitMap'][$parentHash] ?? '');

                    if ($remoteParentHash === '') {
                        throw new RuntimeException(sprintf('Parent commit for %s was not uploaded before child commit.', $localCommitHash));
                    }

                    $remoteParentHashes[] = $remoteParentHash;
                }

                $remoteTreeHash = (string) ($state['treeMap'][$localCommit->treeHash] ?? '');

                if ($remoteTreeHash === '') {
                    throw new RuntimeException(sprintf('Tree %s was not uploaded before commit %s.', $localCommit->treeHash, $localCommitHash));
                }

                $remoteCommitHash = $provider->createCommit($remoteConfig, new CreateRemoteCommitRequest(
                    $remoteTreeHash,
                    $remoteParentHashes,
                    $localCommit->message,
                    $localCommit->authorName !== '' ? $localCommit->authorName : 'PushPull',
                    $localCommit->authorEmail
                ));
                $state['commitMap'][$localCommitHash] = $remoteCommitHash;
                $state['pushedCommitHashes'][] = $remoteCommitHash;
                $this->localRepository->importRemoteCommit(new RemoteCommit(
                    $remoteCommitHash,
                    $remoteTreeHash,
                    $remoteParentHashes,
                    $localCommit->message
                ));

                if ($provider instanceof GitLabProvider) {
                    $provider->primeCommitFiles($remoteCommitHash, $this->materializeLocalTreeFiles($localCommit->treeHash));
                }

                $state['commitIndex']++;
                $state['progressCurrent']++;
                $state['progressMessage'] = $this->pushProgressMessage($state, sprintf('Uploaded commit %s.', $localCommitHash));
                $budget--;
                continue;
            }

            $remoteHeadHash = (string) ($state['commitMap'][$state['localHeadHash']] ?? '');

            if ($remoteHeadHash === '') {
                throw new RuntimeException('Remote head hash could not be resolved for branch push.');
            }

            $update = $provider->updateRef($remoteConfig, new UpdateRemoteRefRequest(
                (string) $state['remoteRefName'],
                $remoteHeadHash,
                (string) $state['stopAtRemoteHash']
            ));

            if (! $update->success) {
                throw new RuntimeException(sprintf('Remote branch %s could not be updated.', $settings->branch));
            }

            $finalRemoteHeadHash = $update->commitHash !== '' ? $update->commitHash : $remoteHeadHash;

            if (
                $finalRemoteHeadHash === (string) $state['stopAtRemoteHash']
                && ! empty($state['pushedCommitHashes'])
            ) {
                throw new RuntimeException(sprintf(
                    'Remote branch %s did not advance after PushPull uploaded commits. The provider likely rejected an empty or no-op branch update; local refs were left unchanged.',
                    $settings->branch
                ));
            }

            $this->aliasRemoteCommitHash($remoteHeadHash, $finalRemoteHeadHash);
            $this->localRepository->updateRef('refs/heads/' . $settings->branch, $finalRemoteHeadHash);
            $this->localRepository->updateRef('refs/remotes/origin/' . $settings->branch, $finalRemoteHeadHash);
            $this->localRepository->updateRef('HEAD', $finalRemoteHeadHash);
            $state['progressCurrent']++;
            $state['status'] = 'pushed';
            $state['phase'] = 'complete';
            $state['remoteCommitHash'] = $finalRemoteHeadHash;
            $state['progressMessage'] = sprintf(
                'Pushed local branch %s to remote commit %s. Uploaded %d commit(s), %d tree(s), and %d blob(s).',
                $settings->branch,
                $finalRemoteHeadHash,
                count($state['pushedCommitHashes']),
                count($state['pushedTreeHashes']),
                count($state['pushedBlobHashes'])
            );
        }

        return $state;
    }

    private function resultFromState(string $managedSetKey, PushPullSettings $settings, array $state): PushManagedSetResult
    {
        return new PushManagedSetResult(
            $managedSetKey,
            $settings->branch,
            (string) ($state['status'] ?? 'pushed'),
            (string) ($state['localHeadHash'] ?? ''),
            (string) ($state['remoteCommitHash'] ?? ''),
            array_values(array_unique((array) ($state['pushedCommitHashes'] ?? []))),
            array_values(array_unique((array) ($state['pushedTreeHashes'] ?? []))),
            array_values(array_unique((array) ($state['pushedBlobHashes'] ?? [])))
        );
    }

    /**
     * @return array{0: GitProviderInterface, 1: GitRemoteConfig}
     */
    private function resolveProvider(PushPullSettings $settings): array
    {
        $remoteConfig = GitRemoteConfig::fromSettings($settings);

        return [$this->providerFactory->make($remoteConfig->providerKey), $remoteConfig];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function rehydrateGitLabPushState(GitProviderInterface $provider, GitRemoteConfig $remoteConfig, array $state): void
    {
        if (! $provider instanceof GitLabProvider) {
            return;
        }

        $stopAtRemoteHash = (string) ($state['stopAtRemoteHash'] ?? '');

        if ($stopAtRemoteHash !== '') {
            $provider->primeCurrentFiles($stopAtRemoteHash, $this->materializeLocalCommitFiles($stopAtRemoteHash));
        }

        foreach ((array) ($state['commitMap'] ?? []) as $localCommitHash => $remoteCommitHash) {
            if (! is_string($localCommitHash) || $localCommitHash === '' || ! is_string($remoteCommitHash) || $remoteCommitHash === '') {
                continue;
            }

            $localCommit = $this->localRepository->getCommit($localCommitHash);

            if ($localCommit === null) {
                continue;
            }

            $provider->primeCommitFiles($remoteCommitHash, $this->materializeLocalTreeFiles($localCommit->treeHash));
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function pushProgressMessage(array $state, string $prefix): string
    {
        return sprintf(
            '%s Uploaded %d of %d planned objects.',
            $prefix,
            (int) ($state['progressCurrent'] ?? 0),
            (int) ($state['progressTotal'] ?? 0)
        );
    }

    /**
     * @param array<string, bool> $seen
     * @param array<int, string> $order
     * @return array<string, bool>
     */
    private function collectCommitPushOrder(string $commitHash, string $stopAtRemoteHash, array $seen, array &$order): array
    {
        if ($commitHash === $stopAtRemoteHash || isset($seen[$commitHash])) {
            return $seen;
        }

        $seen[$commitHash] = true;
        $commit = $this->localRepository->getCommit($commitHash);

        if ($commit === null) {
            throw new RuntimeException(sprintf('Local commit %s could not be found for push planning.', $commitHash));
        }

        foreach ([$commit->parentHash, $commit->secondParentHash] as $parentHash) {
            if (is_string($parentHash) && $parentHash !== '') {
                $seen = $this->collectCommitPushOrder($parentHash, $stopAtRemoteHash, $seen, $order);
            }
        }

        $order[] = $commitHash;

        return $seen;
    }

    /**
     * @param array<string, bool> $seenTrees
     * @param array<int, string> $treeOrder
     * @param array<string, bool> $blobHashes
     */
    private function collectTreePushPlan(string $treeHash, array &$seenTrees, array &$treeOrder, array &$blobHashes): void
    {
        if (isset($seenTrees[$treeHash])) {
            return;
        }

        $seenTrees[$treeHash] = true;
        $tree = $this->localRepository->getTree($treeHash);

        if ($tree === null) {
            throw new RuntimeException(sprintf('Local tree %s could not be found for push planning.', $treeHash));
        }

        foreach ($tree->entries as $entry) {
            if ($entry->type === 'tree') {
                $this->collectTreePushPlan($entry->hash, $seenTrees, $treeOrder, $blobHashes);
                continue;
            }

            $blobHashes[$entry->hash] = true;
        }

        $treeOrder[] = $treeHash;
    }

    /**
     * @param array<string, bool> $seenTrees
     * @param array<int, string> $treeOrder
     * @param array<string, bool> $blobHashes
     * @param array<string, string> $treeMap
     * @param array<string, string> $blobMap
     */
    private function collectTreePushPlanAgainstRemote(
        string $localTreeHash,
        string $remoteTreeHash,
        array &$seenTrees,
        array &$treeOrder,
        array &$blobHashes,
        array &$treeMap,
        array &$blobMap
    ): void {
        if (isset($treeMap[$localTreeHash]) || isset($seenTrees[$localTreeHash])) {
            return;
        }

        $localTree = $this->localRepository->getTree($localTreeHash);
        $remoteTree = $this->localRepository->getTree($remoteTreeHash);

        if ($localTree === null || $remoteTree === null) {
            $this->collectTreePushPlan($localTreeHash, $seenTrees, $treeOrder, $blobHashes);
            return;
        }

        $remoteEntriesByPath = [];

        foreach ($remoteTree->entries as $entry) {
            $remoteEntriesByPath[$entry->path] = $entry;
        }

        $canReuseTree = count($localTree->entries) === count($remoteTree->entries);

        foreach ($localTree->entries as $entry) {
            $remoteEntry = $remoteEntriesByPath[$entry->path] ?? null;

            if ($remoteEntry === null || $remoteEntry->type !== $entry->type) {
                $canReuseTree = false;

                if ($entry->type === 'tree') {
                    $this->collectTreePushPlan($entry->hash, $seenTrees, $treeOrder, $blobHashes);
                } else {
                    $blobHashes[$entry->hash] = true;
                }

                continue;
            }

            if ($entry->type === 'tree') {
                $beforeMapped = isset($treeMap[$entry->hash]);
                $this->collectTreePushPlanAgainstRemote($entry->hash, $remoteEntry->hash, $seenTrees, $treeOrder, $blobHashes, $treeMap, $blobMap);

                if (! $beforeMapped && ! isset($treeMap[$entry->hash])) {
                    $canReuseTree = false;
                }

                continue;
            }

            $localBlob = $this->localRepository->getBlob($entry->hash);
            $remoteBlob = $this->localRepository->getBlob($remoteEntry->hash);

            if ($localBlob !== null && $remoteBlob !== null && $localBlob->content === $remoteBlob->content) {
                $blobMap[$entry->hash] = $remoteEntry->hash;
                continue;
            }

            $canReuseTree = false;
            $blobHashes[$entry->hash] = true;
        }

        if ($canReuseTree) {
            $treeMap[$localTreeHash] = $remoteTreeHash;

            return;
        }

        $this->collectTreePushPlan($localTreeHash, $seenTrees, $treeOrder, $blobHashes);
    }

    /**
     * @param array<string, bool> $seenTrees
     * @param array<int, string> $treeOrder
     * @param array<string, bool> $blobHashes
     */
    private function collectTreePushPlanAgainstLocal(
        string $localTreeHash,
        string $baselineTreeHash,
        array &$seenTrees,
        array &$treeOrder,
        array &$blobHashes
    ): void {
        if (isset($seenTrees[$localTreeHash])) {
            return;
        }

        $localTree = $this->localRepository->getTree($localTreeHash);
        $baselineTree = $this->localRepository->getTree($baselineTreeHash);

        if ($localTree === null || $baselineTree === null) {
            $this->collectTreePushPlan($localTreeHash, $seenTrees, $treeOrder, $blobHashes);
            return;
        }

        $baselineEntriesByPath = [];

        foreach ($baselineTree->entries as $entry) {
            $baselineEntriesByPath[$entry->path] = $entry;
        }

        $treeChanged = count($localTree->entries) !== count($baselineTree->entries);

        foreach ($localTree->entries as $entry) {
            $baselineEntry = $baselineEntriesByPath[$entry->path] ?? null;

            if ($baselineEntry !== null && $baselineEntry->type === $entry->type && $baselineEntry->hash === $entry->hash) {
                continue;
            }

            $treeChanged = true;

            if ($entry->type === 'tree' && $baselineEntry !== null && $baselineEntry->type === 'tree') {
                $this->collectTreePushPlanAgainstLocal($entry->hash, $baselineEntry->hash, $seenTrees, $treeOrder, $blobHashes);
                continue;
            }

            if ($entry->type === 'tree') {
                $this->collectTreePushPlan($entry->hash, $seenTrees, $treeOrder, $blobHashes);
                continue;
            }

            $blobHashes[$entry->hash] = true;
        }

        if ($treeChanged) {
            $seenTrees[$localTreeHash] = true;
            $treeOrder[] = $localTreeHash;
        }
    }

    private function determineRelationship(string $localCommitHash, string $remoteCommitHash): string
    {
        if ($localCommitHash === $remoteCommitHash) {
            return 'in_sync';
        }

        $localAncestors = $this->ancestorSet($localCommitHash);
        $remoteAncestors = $this->ancestorSet($remoteCommitHash);

        if (isset($localAncestors[$remoteCommitHash])) {
            return 'ahead';
        }

        if (isset($remoteAncestors[$localCommitHash])) {
            return 'behind';
        }

        foreach ($localAncestors as $hash => $_) {
            if (isset($remoteAncestors[$hash])) {
                return 'diverged';
            }
        }

        return 'unrelated';
    }

    /**
     * @return array<string, true>
     */
    private function ancestorSet(string $startingHash): array
    {
        $seen = [];
        $queue = [$startingHash];

        while ($queue !== []) {
            $hash = array_shift($queue);

            if (! is_string($hash) || $hash === '' || isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $commit = $this->localRepository->getCommit($hash);

            if ($commit === null) {
                continue;
            }

            foreach ([$commit->parentHash, $commit->secondParentHash] as $parentHash) {
                if (is_string($parentHash) && $parentHash !== '') {
                    $queue[] = $parentHash;
                }
            }
        }

        return $seen;
    }

    private function aliasRemoteCommitHash(string $stagedRemoteHash, string $finalRemoteHash): void
    {
        if ($stagedRemoteHash === $finalRemoteHash) {
            return;
        }

        $stagedCommit = $this->localRepository->getCommit($stagedRemoteHash);

        if ($stagedCommit === null) {
            return;
        }

        $this->localRepository->importRemoteCommit(new RemoteCommit(
            $finalRemoteHash,
            $stagedCommit->treeHash,
            array_values(array_filter([$stagedCommit->parentHash, $stagedCommit->secondParentHash])),
            $stagedCommit->message
        ));
    }

    /**
     * @return array<string, string>
     */
    private function materializeLocalCommitFiles(string $commitHash): array
    {
        $commit = $this->localRepository->getCommit($commitHash);

        if ($commit === null) {
            throw new RuntimeException(sprintf('Local commit %s could not be found for file materialization.', $commitHash));
        }

        return $this->materializeLocalTreeFiles($commit->treeHash);
    }

    /**
     * @return array<string, string>
     */
    private function materializeLocalTreeFiles(string $treeHash): array
    {
        $tree = $this->localRepository->getTree($treeHash);

        if ($tree === null) {
            throw new RuntimeException(sprintf('Local tree %s could not be found for file materialization.', $treeHash));
        }

        $files = [];

        foreach ($tree->entries as $entry) {
            if ($entry->type === 'tree') {
                foreach ($this->materializeLocalTreeFiles($entry->hash) as $childPath => $content) {
                    $files[$entry->path . '/' . $childPath] = $content;
                }

                continue;
            }

            $blob = $this->localRepository->getBlob($entry->hash);

            if ($blob === null) {
                throw new RuntimeException(sprintf('Local blob %s could not be found for file materialization.', $entry->hash));
            }

            $files[$entry->path] = $blob->content;
        }

        ksort($files);

        return $files;
    }
}
