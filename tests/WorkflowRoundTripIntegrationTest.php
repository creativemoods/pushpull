<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\Media\RmlMediaOrganizationAdapter;
use PushPull\Content\Translation\WpmlTranslationManagementAdapter;
use PushPull\Content\WordPress\GeneratePressElementsAdapter;
use PushPull\Content\WordPress\WordPressAttachmentsAdapter;
use PushPull\Content\WordPress\WordPressCoreConfigurationAdapter;
use PushPull\Content\WordPress\WordPressMenusAdapter;
use PushPull\Content\WordPress\WordPressPagesAdapter;
use PushPull\Domain\Apply\ConfigManagedSetApplyService;
use PushPull\Domain\Apply\ManagedSetApplyService;
use PushPull\Domain\Apply\OverlayManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Merge\JsonThreeWayMerger;
use PushPull\Domain\Merge\ManagedSetMergeService;
use PushPull\Domain\Push\ManagedSetPushService;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Domain\Sync\RemoteBranchFetcher;
use PushPull\Persistence\ContentMap\ContentMapRepository;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Provider\GitRemoteConfig;
use PushPull\Settings\SettingsRepository;
use PushPull\Tests\Support\InMemoryWorkflowGitProvider;
use PushPull\Tests\Support\InMemoryWorkflowGitProviderFactory;

final class WorkflowRoundTripIntegrationTest extends TestCase
{
    private string $uploadsBaseDir = '/tmp/pushpull-test-uploads';

    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_generateblocks_posts'] = [];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_terms']['nav_menu'] = [];
        $GLOBALS['pushpull_test_next_term_id'] = 1;
        $GLOBALS['pushpull_test_next_post_id'] = 1;
        $GLOBALS['pushpull_test_theme_mods'] = [];
        $GLOBALS['pushpull_test_wpml_translations'] = [];
        $GLOBALS['pushpull_test_rml_folders'] = [];
        $GLOBALS['pushpull_test_rml_attachment_folders'] = [];
        $GLOBALS['pushpull_test_next_rml_folder_id'] = 2;
        $this->removeDirectory($this->uploadsBaseDir);
        wp_mkdir_p($this->uploadsBaseDir . '/2026/03');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadsBaseDir);
    }

    public function testPagesMenusAndTranslationsRoundTripThroughRemoteProvider(): void
    {
        $managedSets = ['wordpress_pages', 'wordpress_menus', 'translation_management'];
        $sourceSettingsRepository = $this->configureSettings($managedSets);
        $pagesAdapter = new WordPressPagesAdapter();
        $menusAdapter = new WordPressMenusAdapter();
        $translationsAdapter = new WpmlTranslationManagementAdapter($sourceSettingsRepository);
        $provider = new InMemoryWorkflowGitProvider();
        $providerFactory = new InMemoryWorkflowGitProviderFactory($provider);
        $sourceRemoteConfig = GitRemoteConfig::fromSettings($sourceSettingsRepository->get());

        $this->seedSourceWordPressState();
        $sourcePageSnapshot = $pagesAdapter->exportSnapshot();
        $sourceMenuSnapshot = $menusAdapter->exportSnapshot();
        $sourceTranslationSnapshot = $translationsAdapter->exportSnapshot();

        $sourceRepository = new DatabaseLocalRepository(new \wpdb());
        $provider->initializeEmptyRepository($sourceRemoteConfig, 'Initialize remote repository');
        $sourceFetcher = new RemoteBranchFetcher($provider, $sourceRepository, $sourceRemoteConfig);
        $sourceFetcher->fetchManagedSet('wordpress_pages');
        (new ManagedSetMergeService(
            $sourceRepository,
            new RepositoryStateReader($sourceRepository),
            new JsonThreeWayMerger(),
            new WorkingStateRepository(new \wpdb())
        ))->merge('wordpress_pages', 'main');
        $this->commitSnapshot($sourceRepository, $pagesAdapter, $sourcePageSnapshot);
        $this->commitSnapshot($sourceRepository, $menusAdapter, $sourceMenuSnapshot);
        $this->commitSnapshot($sourceRepository, $translationsAdapter, $sourceTranslationSnapshot);

        $pushService = new ManagedSetPushService($sourceRepository, $providerFactory);
        $pushResult = $pushService->push('wordpress_pages', $sourceSettingsRepository->get());

        self::assertSame('main', $pushResult->branch);

        $this->seedTargetWordPressState();
        $targetSettingsRepository = $this->configureSettings($managedSets);
        $targetRepository = new DatabaseLocalRepository(new \wpdb());
        $remoteConfig = GitRemoteConfig::fromSettings($targetSettingsRepository->get());

        $fetcher = new RemoteBranchFetcher($provider, $targetRepository, $remoteConfig);
        $fetchResult = $fetcher->fetchManagedSet('wordpress_pages');
        self::assertNotSame('', $fetchResult->remoteCommitHash);

        $mergeService = new ManagedSetMergeService(
            $targetRepository,
            new RepositoryStateReader($targetRepository),
            new JsonThreeWayMerger(),
            new WorkingStateRepository(new \wpdb())
        );
        $mergeResult = $mergeService->merge('wordpress_pages', 'main');
        self::assertSame('fast_forward', $mergeResult->status);

        $targetPagesAdapter = new WordPressPagesAdapter();
        $targetMenusAdapter = new WordPressMenusAdapter();
        $targetTranslationsAdapter = new WpmlTranslationManagementAdapter($targetSettingsRepository);
        $targetStateReader = new RepositoryStateReader($targetRepository);
        $targetWorkingStateRepository = new WorkingStateRepository(new \wpdb());

        $pagesApplyService = new ManagedSetApplyService(
            $targetPagesAdapter,
            $targetStateReader,
            new ContentMapRepository(new \wpdb()),
            $targetWorkingStateRepository
        );
        $menusApplyService = new ManagedSetApplyService(
            $targetMenusAdapter,
            $targetStateReader,
            new ContentMapRepository(new \wpdb()),
            $targetWorkingStateRepository
        );
        $translationsApplyService = new OverlayManagedSetApplyService(
            $targetTranslationsAdapter,
            $targetStateReader,
            $targetWorkingStateRepository
        );

        $pagesApplyService->apply($targetSettingsRepository->get());
        $menusApplyService->apply($targetSettingsRepository->get());
        $translationsApplyService->apply($targetSettingsRepository->get());

        self::assertSame($sourcePageSnapshot->files, $targetPagesAdapter->exportSnapshot()->files);
        self::assertSame($sourceMenuSnapshot->files, $targetMenusAdapter->exportSnapshot()->files);
        self::assertSame($sourceTranslationSnapshot->files, $targetTranslationsAdapter->exportSnapshot()->files);
    }

    public function testAttachmentsAndMediaOrganizationRoundTripThroughRemoteProvider(): void
    {
        $managedSets = ['wordpress_attachments', 'media_organization'];
        $sourceSettingsRepository = $this->configureSettings($managedSets);
        $attachmentsAdapter = new WordPressAttachmentsAdapter();
        $mediaAdapter = new RmlMediaOrganizationAdapter($sourceSettingsRepository);

        $this->seedSourceAttachmentsAndMediaState();
        $sourceAttachmentSnapshot = $attachmentsAdapter->exportSnapshot();
        $sourceMediaSnapshot = $mediaAdapter->exportSnapshot();
        $provider = $this->publishSourceSnapshots(
            $sourceSettingsRepository,
            [
                [$attachmentsAdapter, $sourceAttachmentSnapshot],
                [$mediaAdapter, $sourceMediaSnapshot],
            ],
            'wordpress_attachments'
        );

        $this->seedTargetAttachmentsAndMediaState();
        [$targetDb, $targetRepository, $targetWorkingStateRepository] = $this->fetchAndMergeTargetRepository(
            $provider,
            $sourceSettingsRepository,
            'wordpress_attachments'
        );
        $targetStateReader = new RepositoryStateReader($targetRepository);
        $attachmentsApplyService = new ManagedSetApplyService(
            $attachmentsAdapter,
            $targetStateReader,
            new ContentMapRepository($targetDb),
            $targetWorkingStateRepository
        );
        $mediaApplyService = new OverlayManagedSetApplyService(
            new RmlMediaOrganizationAdapter($sourceSettingsRepository),
            $targetStateReader,
            $targetWorkingStateRepository
        );

        $attachmentsApplyService->apply($sourceSettingsRepository->get());
        $mediaApplyService->apply($sourceSettingsRepository->get());

        self::assertSame($sourceAttachmentSnapshot->files, $attachmentsAdapter->exportSnapshot()->files);
        self::assertSame($sourceMediaSnapshot->files, (new RmlMediaOrganizationAdapter($sourceSettingsRepository))->exportSnapshot()->files);
    }

    public function testPagesAndGeneratePressElementsRoundTripThroughRemoteProvider(): void
    {
        $managedSets = ['wordpress_pages', 'generatepress_elements'];
        $sourceSettingsRepository = $this->configureSettings($managedSets);
        $pagesAdapter = new WordPressPagesAdapter();
        $elementsAdapter = new GeneratePressElementsAdapter();

        $this->seedSourcePagesAndGeneratePressElementsState();
        $sourcePageSnapshot = $pagesAdapter->exportSnapshot();
        $sourceElementsSnapshot = $elementsAdapter->exportSnapshot();
        $provider = $this->publishSourceSnapshots(
            $sourceSettingsRepository,
            [
                [$pagesAdapter, $sourcePageSnapshot],
                [$elementsAdapter, $sourceElementsSnapshot],
            ],
            'wordpress_pages'
        );

        $this->seedTargetPagesAndGeneratePressElementsState();
        [$targetDb, $targetRepository, $targetWorkingStateRepository] = $this->fetchAndMergeTargetRepository(
            $provider,
            $sourceSettingsRepository,
            'wordpress_pages'
        );
        $targetStateReader = new RepositoryStateReader($targetRepository);
        $pagesApplyService = new ManagedSetApplyService(
            $pagesAdapter,
            $targetStateReader,
            new ContentMapRepository($targetDb),
            $targetWorkingStateRepository
        );
        $elementsApplyService = new ManagedSetApplyService(
            $elementsAdapter,
            $targetStateReader,
            new ContentMapRepository($targetDb),
            $targetWorkingStateRepository
        );

        $pagesApplyService->apply($sourceSettingsRepository->get());
        $elementsApplyService->apply($sourceSettingsRepository->get());

        self::assertSame($sourcePageSnapshot->files, $pagesAdapter->exportSnapshot()->files);
        self::assertSame($sourceElementsSnapshot->files, $elementsAdapter->exportSnapshot()->files);
    }

    public function testPagesAndCoreConfigurationRoundTripThroughRemoteProvider(): void
    {
        $managedSets = ['wordpress_pages', 'wordpress_core_configuration'];
        $sourceSettingsRepository = $this->configureSettings($managedSets);
        $pagesAdapter = new WordPressPagesAdapter();
        $configurationAdapter = new WordPressCoreConfigurationAdapter();

        $this->seedSourcePagesAndCoreConfigurationState();
        $sourcePageSnapshot = $pagesAdapter->exportSnapshot();
        $sourceConfigurationSnapshot = $configurationAdapter->exportSnapshot();
        $provider = $this->publishSourceSnapshots(
            $sourceSettingsRepository,
            [
                [$pagesAdapter, $sourcePageSnapshot],
                [$configurationAdapter, $sourceConfigurationSnapshot],
            ],
            'wordpress_pages'
        );

        $this->seedTargetPagesAndCoreConfigurationState();
        [$targetDb, $targetRepository, $targetWorkingStateRepository] = $this->fetchAndMergeTargetRepository(
            $provider,
            $sourceSettingsRepository,
            'wordpress_pages'
        );
        $targetStateReader = new RepositoryStateReader($targetRepository);
        $pagesApplyService = new ManagedSetApplyService(
            $pagesAdapter,
            $targetStateReader,
            new ContentMapRepository($targetDb),
            $targetWorkingStateRepository
        );
        $configurationApplyService = new ConfigManagedSetApplyService(
            $configurationAdapter,
            $targetStateReader,
            $targetWorkingStateRepository
        );

        $pagesApplyService->apply($sourceSettingsRepository->get());
        $configurationApplyService->apply($sourceSettingsRepository->get());

        self::assertSame($sourcePageSnapshot->files, $pagesAdapter->exportSnapshot()->files);
        self::assertSame($sourceConfigurationSnapshot->files, $configurationAdapter->exportSnapshot()->files);
    }

    /**
     * @param string[] $enabledManagedSets
     */
    private function configureSettings(array $enabledManagedSets): SettingsRepository
    {
        $settingsRepository = new SettingsRepository();
        $settingsRepository->save($settingsRepository->sanitize([
            'provider_key' => 'github',
            'owner_or_workspace' => 'owner',
            'repository' => 'repo',
            'branch' => 'main',
            'api_token' => 'token',
            'author_name' => 'Jane Doe',
            'author_email' => 'jane@example.com',
            'enabled_managed_sets' => $enabledManagedSets,
        ]));

        update_option('icl_sitepress_settings', [
            'active_languages' => ['en', 'fr'],
            'custom_posts_sync_option' => [
                'page' => '1',
            ],
            'taxonomies_sync_option' => [
                'nav_menu' => '1',
            ],
        ]);

        return $settingsRepository;
    }

    private function seedSourceWordPressState(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(11, 'Accueil', 'accueil', 'publish', 0, 'page'),
            new \WP_Post(12, 'Contact', 'contact', 'publish', 0, 'page'),
            new \WP_Post(13, 'Contact FR', 'contact-fr', 'publish', 0, 'page'),
        ];
        $menuEnId = (int) wp_create_nav_menu('Footer menu EN');
        wp_update_term($menuEnId, 'nav_menu', ['slug' => 'footer-menu-en']);
        $menuFrId = (int) wp_create_nav_menu('Footer menu FR');
        wp_update_term($menuFrId, 'nav_menu', ['slug' => 'footer-menu-fr']);

        wp_update_nav_menu_item($menuEnId, 0, [
            'menu-item-title' => 'Home',
            'menu-item-type' => 'post_type',
            'menu-item-object' => 'page',
            'menu-item-object-id' => 10,
            'menu-item-position' => 1,
            'menu-item-status' => 'publish',
        ]);
        wp_update_nav_menu_item($menuFrId, 0, [
            'menu-item-title' => 'Accueil',
            'menu-item-type' => 'post_type',
            'menu-item-object' => 'page',
            'menu-item-object-id' => 11,
            'menu-item-position' => 1,
            'menu-item-status' => 'publish',
        ]);

        $GLOBALS['pushpull_test_wpml_translations'] = [
            [
                'translation_id' => 1,
                'element_type' => 'post_page',
                'element_id' => 10,
                'trid' => 100,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
            [
                'translation_id' => 2,
                'element_type' => 'post_page',
                'element_id' => 11,
                'trid' => 100,
                'language_code' => 'fr',
                'source_language_code' => 'en',
            ],
            [
                'translation_id' => 3,
                'element_type' => 'post_page',
                'element_id' => 12,
                'trid' => 200,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
            [
                'translation_id' => 4,
                'element_type' => 'post_page',
                'element_id' => 13,
                'trid' => 200,
                'language_code' => 'fr',
                'source_language_code' => 'en',
            ],
            [
                'translation_id' => 5,
                'element_type' => 'tax_nav_menu',
                'element_id' => $menuEnId,
                'trid' => 300,
                'language_code' => 'en',
                'source_language_code' => null,
            ],
            [
                'translation_id' => 6,
                'element_type' => 'tax_nav_menu',
                'element_id' => $menuFrId,
                'trid' => 300,
                'language_code' => 'fr',
                'source_language_code' => 'en',
            ],
        ];
    }

    private function seedTargetWordPressState(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(92, 'Accueil', 'accueil', 'publish', 0, 'page'),
            new \WP_Post(93, 'Contact', 'contact', 'publish', 0, 'page'),
            new \WP_Post(94, 'Contact FR', 'contact-fr', 'publish', 0, 'page'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
        $GLOBALS['pushpull_test_terms']['nav_menu'] = [];
        $GLOBALS['pushpull_test_next_term_id'] = 1;
        $GLOBALS['pushpull_test_theme_mods'] = [];
        $GLOBALS['pushpull_test_wpml_translations'] = [];

        $menuEnId = (int) wp_create_nav_menu('Footer menu EN');
        wp_update_term($menuEnId, 'nav_menu', ['slug' => 'footer-menu-en']);
        $menuFrId = (int) wp_create_nav_menu('Footer menu FR');
        wp_update_term($menuFrId, 'nav_menu', ['slug' => 'footer-menu-fr']);
    }

    private function seedSourceAttachmentsAndMediaState(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Hero', 'hero', 'inherit', 0, 'attachment', '', '2026-03-24 09:00:00', '2026-03-24 09:00:00', '', 'image/jpeg'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [
            10 => [
                WordPressAttachmentsAdapter::SYNC_META_KEY => ['1'],
                '_wp_attached_file' => ['2026/03/hero.jpg'],
                '_wp_attachment_metadata' => [[
                    'file' => '2026/03/hero.jpg',
                    'generated' => true,
                    'sizes' => [
                        'thumbnail' => [
                            'file' => 'thumb-hero.jpg',
                            'width' => 150,
                            'height' => 150,
                        ],
                    ],
                ]],
                '_wp_attachment_image_alt' => ['Hero alt'],
            ],
        ];
        $GLOBALS['pushpull_test_rml_folders'] = [
            2 => '/Marketing',
            3 => '/Marketing/Heroes',
        ];
        $GLOBALS['pushpull_test_rml_attachment_folders'] = [
            10 => 3,
        ];
        file_put_contents($this->uploadsBaseDir . '/2026/03/hero.jpg', 'JPEGDATA');
    }

    private function seedTargetAttachmentsAndMediaState(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Hero', 'hero', 'inherit', 0, 'attachment', '', '2026-03-24 09:00:00', '2026-03-24 09:00:00', '', 'image/jpeg'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [
            91 => [
                WordPressAttachmentsAdapter::SYNC_META_KEY => ['1'],
                '_wp_attached_file' => ['2026/03/hero.jpg'],
            ],
        ];
        $GLOBALS['pushpull_test_rml_folders'] = [];
        $GLOBALS['pushpull_test_rml_attachment_folders'] = [];
        $GLOBALS['pushpull_test_next_rml_folder_id'] = 2;
        $this->removeDirectory($this->uploadsBaseDir);
    }

    private function seedSourcePagesAndGeneratePressElementsState(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(17, 'Share an event', 'share-an-event', 'publish', 0, 'page'),
            new \WP_Post(42, 'Header CTA', 'header-cta', 'publish', 0, 'gp_elements', '<!-- wp:paragraph --><p>CTA</p><!-- /wp:paragraph -->'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [
            42 => [
                '_generate_element_type' => ['block'],
                '_generate_hook' => ['generate_after_header'],
                '_generate_element_display_conditions' => [[
                    ['object' => '17', 'rule' => 'post:page'],
                ]],
                '_generate_block_type' => ['hook'],
            ],
        ];
    }

    private function seedTargetPagesAndGeneratePressElementsState(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Share an event', 'share-an-event', 'publish', 0, 'page'),
            new \WP_Post(200, 'Header CTA', 'header-cta', 'publish', 0, 'gp_elements', '<!-- wp:paragraph --><p>Old CTA</p><!-- /wp:paragraph -->'),
        ];
        $GLOBALS['pushpull_test_generateblocks_meta'] = [];
    }

    private function seedSourcePagesAndCoreConfigurationState(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(10, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(11, 'Blog', 'blog', 'publish', 0, 'page'),
        ];
        update_option('show_on_front', 'page');
        update_option('page_on_front', 10);
        update_option('page_for_posts', 11);
        update_option('permalink_structure', '/archives/%postname%/');
    }

    private function seedTargetPagesAndCoreConfigurationState(): void
    {
        $GLOBALS['pushpull_test_generateblocks_posts'] = [
            new \WP_Post(91, 'Home', 'home', 'publish', 0, 'page'),
            new \WP_Post(92, 'Blog', 'blog', 'publish', 0, 'page'),
        ];
        update_option('show_on_front', 'posts');
        update_option('page_on_front', 0);
        update_option('page_for_posts', 0);
        update_option('permalink_structure', '');
    }

    private function commitSnapshot(
        DatabaseLocalRepository $repository,
        object $adapter,
        object $snapshot
    ): void {
        $committer = new ManagedSetRepositoryCommitter($repository, $adapter);
        $committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Integration export', 'Jane Doe', 'jane@example.com')
        );
    }

    /**
     * @param array<int, array{0: object, 1: object}> $adapterSnapshotPairs
     */
    private function publishSourceSnapshots(
        SettingsRepository $settingsRepository,
        array $adapterSnapshotPairs,
        string $bootstrapManagedSetKey
    ): InMemoryWorkflowGitProvider {
        $provider = new InMemoryWorkflowGitProvider();
        $providerFactory = new InMemoryWorkflowGitProviderFactory($provider);
        $sourceRemoteConfig = GitRemoteConfig::fromSettings($settingsRepository->get());
        $sourceDb = new \wpdb();
        $sourceRepository = new DatabaseLocalRepository($sourceDb);
        $sourceWorkingStateRepository = new WorkingStateRepository($sourceDb);

        $provider->initializeEmptyRepository($sourceRemoteConfig, 'Initialize remote repository');
        (new RemoteBranchFetcher($provider, $sourceRepository, $sourceRemoteConfig))->fetchManagedSet($bootstrapManagedSetKey);
        (new ManagedSetMergeService(
            $sourceRepository,
            new RepositoryStateReader($sourceRepository),
            new JsonThreeWayMerger(),
            $sourceWorkingStateRepository
        ))->merge($bootstrapManagedSetKey, 'main');

        foreach ($adapterSnapshotPairs as [$adapter, $snapshot]) {
            $this->commitSnapshot($sourceRepository, $adapter, $snapshot);
        }

        (new ManagedSetPushService($sourceRepository, $providerFactory))->push($bootstrapManagedSetKey, $settingsRepository->get());

        return $provider;
    }

    /**
     * @return array{0: \wpdb, 1: DatabaseLocalRepository, 2: WorkingStateRepository}
     */
    private function fetchAndMergeTargetRepository(
        InMemoryWorkflowGitProvider $provider,
        SettingsRepository $settingsRepository,
        string $bootstrapManagedSetKey
    ): array {
        $targetDb = new \wpdb();
        $targetRepository = new DatabaseLocalRepository($targetDb);
        $targetWorkingStateRepository = new WorkingStateRepository($targetDb);
        $remoteConfig = GitRemoteConfig::fromSettings($settingsRepository->get());

        (new RemoteBranchFetcher($provider, $targetRepository, $remoteConfig))->fetchManagedSet($bootstrapManagedSetKey);
        (new ManagedSetMergeService(
            $targetRepository,
            new RepositoryStateReader($targetRepository),
            new JsonThreeWayMerger(),
            $targetWorkingStateRepository
        ))->merge($bootstrapManagedSetKey, 'main');

        return [$targetDb, $targetRepository, $targetWorkingStateRepository];
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
