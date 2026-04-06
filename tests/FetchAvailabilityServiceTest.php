<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Provider\CreateRemoteCommitRequest;
use PushPull\Provider\GitProviderFactoryInterface;
use PushPull\Provider\GitProviderInterface;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Provider\ProviderCapabilities;
use PushPull\Provider\ProviderConnectionResult;
use PushPull\Provider\ProviderValidationResult;
use PushPull\Provider\RemoteBlob;
use PushPull\Provider\RemoteCommit;
use PushPull\Provider\RemoteRef;
use PushPull\Provider\RemoteTree;
use PushPull\Provider\UpdateRefResult;
use PushPull\Provider\UpdateRemoteRefRequest;
use PushPull\Settings\SettingsRepository;
use PushPull\Support\FetchAvailability\FetchAvailabilityService;

final class FetchAvailabilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        global $pushpull_test_options;
        global $wpdb;

        $pushpull_test_options = [];
        $wpdb = new \wpdb();
    }

    public function testCheckAndStoreMarksUpdatesAvailableWhenRemoteHeadDiffersFromTrackingHead(): void
    {
        global $wpdb;

        update_option(SettingsRepository::OPTION_KEY, [
            'provider_key' => 'github',
            'owner_or_workspace' => 'creativemoods',
            'repository' => 'pushpull',
            'branch' => 'main',
            'enabled_managed_sets' => ['wordpress_pages'],
        ]);

        $localRepository = new DatabaseLocalRepository($wpdb);
        $localRepository->updateRef('refs/remotes/origin/main', 'commit-1');

        $service = new FetchAvailabilityService(
            new SettingsRepository(),
            new class () implements GitProviderFactoryInterface {
                public function make(string $providerKey): GitProviderInterface
                {
                    return new class () implements GitProviderInterface {
                        public function getKey(): string { return 'github'; }
                        public function getLabel(): string { return 'GitHub'; }
                        public function getCapabilities(): ProviderCapabilities { return new ProviderCapabilities(true, true, true); }
                        public function validateConfig(GitRemoteConfig $config): ProviderValidationResult { return ProviderValidationResult::success(); }
                        public function testConnection(GitRemoteConfig $config): ProviderConnectionResult { return ProviderConnectionResult::success('ok'); }
                        public function getRef(GitRemoteConfig $config, string $refName): ?RemoteRef { return new RemoteRef($refName, 'commit-2'); }
                        public function getDefaultBranch(GitRemoteConfig $config): ?string { return 'main'; }
                        public function getCommit(GitRemoteConfig $config, string $hash): ?RemoteCommit { return null; }
                        public function getTree(GitRemoteConfig $config, string $hash): ?RemoteTree { return null; }
                        public function getBlob(GitRemoteConfig $config, string $hash): ?RemoteBlob { return null; }
                        public function createBlob(GitRemoteConfig $config, string $content): string { return ''; }
                        public function createTree(GitRemoteConfig $config, array $entries): string { return ''; }
                        public function createCommit(GitRemoteConfig $config, CreateRemoteCommitRequest $request): string { return ''; }
                        public function updateRef(GitRemoteConfig $config, UpdateRemoteRefRequest $request): UpdateRefResult { throw new \RuntimeException('not used'); }
                        public function initializeEmptyRepository(GitRemoteConfig $config, string $commitMessage): RemoteRef { throw new \RuntimeException('not used'); }
                    };
                }
            },
            $localRepository
        );

        $service->checkAndStore();

        $state = $service->getCachedState((new SettingsRepository())->get());

        self::assertSame(FetchAvailabilityService::STATUS_UPDATES_AVAILABLE, $state['status']);
        self::assertTrue($state['updatesAvailable']);
        self::assertSame('commit-2', $state['remoteCommitHash']);
        self::assertSame('commit-1', $state['trackingCommitHash']);
    }

    public function testCachedStateIsIgnoredAfterRepositorySettingsChange(): void
    {
        global $wpdb;

        update_option(SettingsRepository::OPTION_KEY, [
            'provider_key' => 'github',
            'owner_or_workspace' => 'creativemoods',
            'repository' => 'pushpull',
            'branch' => 'main',
            'enabled_managed_sets' => ['wordpress_pages'],
        ]);

        $service = new FetchAvailabilityService(
            new SettingsRepository(),
            new class () implements GitProviderFactoryInterface {
                public function make(string $providerKey): GitProviderInterface
                {
                    return new class () implements GitProviderInterface {
                        public function getKey(): string { return 'github'; }
                        public function getLabel(): string { return 'GitHub'; }
                        public function getCapabilities(): ProviderCapabilities { return new ProviderCapabilities(true, true, true); }
                        public function validateConfig(GitRemoteConfig $config): ProviderValidationResult { return ProviderValidationResult::success(); }
                        public function testConnection(GitRemoteConfig $config): ProviderConnectionResult { return ProviderConnectionResult::success('ok'); }
                        public function getRef(GitRemoteConfig $config, string $refName): ?RemoteRef { return new RemoteRef($refName, 'commit-2'); }
                        public function getDefaultBranch(GitRemoteConfig $config): ?string { return 'main'; }
                        public function getCommit(GitRemoteConfig $config, string $hash): ?RemoteCommit { return null; }
                        public function getTree(GitRemoteConfig $config, string $hash): ?RemoteTree { return null; }
                        public function getBlob(GitRemoteConfig $config, string $hash): ?RemoteBlob { return null; }
                        public function createBlob(GitRemoteConfig $config, string $content): string { return ''; }
                        public function createTree(GitRemoteConfig $config, array $entries): string { return ''; }
                        public function createCommit(GitRemoteConfig $config, CreateRemoteCommitRequest $request): string { return ''; }
                        public function updateRef(GitRemoteConfig $config, UpdateRemoteRefRequest $request): UpdateRefResult { throw new \RuntimeException('not used'); }
                        public function initializeEmptyRepository(GitRemoteConfig $config, string $commitMessage): RemoteRef { throw new \RuntimeException('not used'); }
                    };
                }
            },
            new DatabaseLocalRepository($wpdb)
        );

        $service->checkAndStore();

        update_option(SettingsRepository::OPTION_KEY, [
            'provider_key' => 'github',
            'owner_or_workspace' => 'creativemoods',
            'repository' => 'another-repo',
            'branch' => 'main',
            'enabled_managed_sets' => ['wordpress_pages'],
        ]);

        $state = $service->getCachedState((new SettingsRepository())->get());

        self::assertSame(FetchAvailabilityService::STATUS_UNKNOWN, $state['status']);
        self::assertFalse($state['updatesAvailable']);
    }
}
