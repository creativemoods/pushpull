<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Domain\Apply\ConfigManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Integration\Wpml\WpmlConfigurationAdapter;
use PushPull\Integration\Wpml\WpmlConfigurationApplier;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;
use RuntimeException;

final class WpmlConfigurationApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private WpmlConfigurationAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ConfigManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        global $pushpull_test_options;
        global $wpdb;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_verified_post_translations'] = [];
        $GLOBALS['sitepress'] = new \PushPull_Test_SitePress();
        $this->wpdb = new \wpdb();
        $wpdb = $this->wpdb;
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new WpmlConfigurationAdapter(new WpmlConfigurationApplier());
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->applyService = new ConfigManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            new WorkingStateRepository($this->wpdb)
        );
    }

    public function testApplyConfiguresWpmlAndMarksSetupFinished(): void
    {
        update_option('icl_sitepress_settings', [
            'default_language' => 'en',
            'active_languages' => ['en'],
            'language_negotiation_type' => 1,
            'setup_complete' => 0,
        ]);

        $item = $this->withPayload($this->adapter->exportSnapshot()->items[0], [
            'defaultLanguage' => 'fr',
            'activeLanguages' => ['en', 'fr'],
            'urlFormat' => 'parameter',
            'postTypeTranslationModes' => [
                'gp_elements' => 'translatable_fallback',
                'page' => 'translatable_only',
                'post' => 'not_translatable',
            ],
            'postTypeSlugTranslations' => [
                'gp_elements' => [
                    'enabled' => true,
                    'values' => [
                        'fr' => 'elements',
                        'en' => 'xyz1234',
                    ],
                ],
            ],
            'setupFinished' => true,
        ]);

        $snapshot = new \PushPull\Content\ManagedContentSnapshot(
            [$item],
            new \PushPull\Content\ManagedCollectionManifest('wpml_configuration', 'wpml_configuration_manifest', ['wpml-settings']),
            [
                'wpml/configuration/wpml-settings.json' => $this->adapter->serialize($item),
                'wpml/configuration/manifest.json' => $this->adapter->serializeManifest(
                    new \PushPull\Content\ManagedCollectionManifest('wpml_configuration', 'wpml_configuration_manifest', ['wpml-settings'])
                ),
            ],
            ['wpml-settings']
        );

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $this->applyService->apply(new PushPullSettings(
            'github',
            'creativemoods',
            'pushpulltestrepo',
            'main',
            'token',
            '',
            false,
            true,
            'Jane Doe',
            'jane@example.com',
            ['wpml_configuration']
        ));

        $settings = get_option('icl_sitepress_settings', []);

        self::assertSame('fr', $settings['default_language']);
        self::assertSame(['fr', 'en'], $settings['active_languages']);
        self::assertSame(3, $settings['language_negotiation_type']);
        self::assertSame([
            'gp_elements' => 2,
            'page' => 1,
        ], $settings['custom_posts_sync_option']);
        self::assertSame([
            'types' => [
                'gp_elements' => 1,
            ],
            'on' => 1,
        ], $settings['posts_slug_translation']);
        self::assertSame(1, $settings['setup_complete']);
        self::assertSame(4, $settings['setup_wizard_step']);
        self::assertSame(1, get_option('wpml_base_slug_translation'));
        self::assertSame(['gp_elements', 'page', 'post'], $GLOBALS['pushpull_test_verified_post_translations']);
        self::assertSame([
            'language' => 'fr',
            'context' => 'WordPress',
            'name' => 'URL slug: gp_elements',
            'value' => 'elements',
            'status' => 10,
            'id' => 1,
        ], $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT * FROM wp_icl_strings WHERE id = %d',
            1
        ), ARRAY_A));
        self::assertSame([
            'string_id' => 1,
            'language' => 'en',
            'value' => 'xyz1234',
            'status' => 10,
            'id' => 1,
        ], $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT id, string_id, language, value, status FROM wp_icl_string_translations WHERE string_id = %d AND language = %s',
            1,
            'en'
        ), ARRAY_A));
    }

    public function testApplyRejectsTurningOffCompletedSetup(): void
    {
        update_option('icl_sitepress_settings', [
            'default_language' => 'fr',
            'active_languages' => ['fr', 'en'],
            'language_negotiation_type' => 1,
            'setup_complete' => 1,
        ]);

        $item = $this->withPayload($this->adapter->exportSnapshot()->items[0], [
            'setupFinished' => false,
        ]);

        $snapshot = new \PushPull\Content\ManagedContentSnapshot(
            [$item],
            new \PushPull\Content\ManagedCollectionManifest('wpml_configuration', 'wpml_configuration_manifest', ['wpml-settings']),
            [
                'wpml/configuration/wpml-settings.json' => $this->adapter->serialize($item),
                'wpml/configuration/manifest.json' => $this->adapter->serializeManifest(
                    new \PushPull\Content\ManagedCollectionManifest('wpml_configuration', 'wpml_configuration_manifest', ['wpml-settings'])
                ),
            ],
            ['wpml-settings']
        );

        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Turning off completed WPML setup is not supported.');

        $this->applyService->apply(new PushPullSettings(
            'github',
            'creativemoods',
            'pushpulltestrepo',
            'main',
            'token',
            '',
            false,
            true,
            'Jane Doe',
            'jane@example.com',
            ['wpml_configuration']
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function withPayload(\PushPull\Content\ManagedContentItem $item, array $payload): \PushPull\Content\ManagedContentItem
    {
        return new \PushPull\Content\ManagedContentItem(
            $item->managedSetKey,
            $item->contentType,
            $item->logicalKey,
            $item->displayName,
            $item->selector,
            $item->slug,
            array_merge($item->payload, $payload),
            $item->postStatus,
            $item->metadata,
            $item->derived,
            $item->sourceWpObjectId,
            $item->schemaVersion,
            $item->adapterVersion
        );
    }
}
