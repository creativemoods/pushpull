<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\GeneratePressConfigurationAdapter;
use PushPull\Domain\Apply\ConfigManagedSetApplyService;
use PushPull\Domain\Diff\RepositoryStateReader;
use PushPull\Domain\Repository\DatabaseLocalRepository;
use PushPull\Domain\Sync\CommitManagedSetRequest;
use PushPull\Domain\Sync\ManagedSetRepositoryCommitter;
use PushPull\Persistence\WorkingState\WorkingStateRepository;
use PushPull\Settings\PushPullSettings;

final class GeneratePressConfigurationApplyServiceTest extends TestCase
{
    private \wpdb $wpdb;
    private DatabaseLocalRepository $repository;
    private GeneratePressConfigurationAdapter $adapter;
    private ManagedSetRepositoryCommitter $committer;
    private ConfigManagedSetApplyService $applyService;

    protected function setUp(): void
    {
        global $pushpull_test_options;

        $pushpull_test_options = [];
        $GLOBALS['pushpull_test_theme_mods'] = [];
        $GLOBALS['pushpull_test_generatepress_modules'] = [
            'Backgrounds' => [
                'title' => 'Backgrounds',
                'key' => 'generate_package_backgrounds',
                'settings' => 'generate_background_settings',
                'isActive' => true,
                'exportable' => true,
            ],
            'Elements' => [
                'title' => 'Elements',
                'key' => 'generate_package_elements',
                'isActive' => true,
                'exportable' => false,
            ],
            'Menu Plus' => [
                'title' => 'Menu Plus',
                'key' => 'generate_package_menu_plus',
                'settings' => 'generate_menu_plus_settings',
                'isActive' => false,
                'exportable' => true,
            ],
        ];
        $GLOBALS['pushpull_test_generatepress_setting_keys'] = [
            'generate_settings',
            'generate_background_settings',
            'generate_blog_settings',
            'generate_menu_plus_settings',
        ];
        $GLOBALS['pushpull_test_generatepress_theme_mod_keys'] = [
            'generate_copyright',
            'font_body_variants',
        ];

        $this->wpdb = new \wpdb();
        $this->repository = new DatabaseLocalRepository($this->wpdb);
        $this->adapter = new GeneratePressConfigurationAdapter();
        $this->committer = new ManagedSetRepositoryCommitter($this->repository, $this->adapter);
        $this->applyService = new ConfigManagedSetApplyService(
            $this->adapter,
            new RepositoryStateReader($this->repository),
            new WorkingStateRepository($this->wpdb)
        );
    }

    public function testApplyRestoresGeneratePressModuleAndSettingsState(): void
    {
        update_option('generate_package_backgrounds', 'activated');
        update_option('generate_package_elements', 'activated');
        update_option('generate_package_menu_plus', 'deactivated');
        update_option('generate_settings', ['container_width' => 1200]);
        update_option('generate_background_settings', ['body_background' => '#fff']);
        update_option('generate_blog_settings', ['single_author' => false, 'single_categories' => false, 'single_date' => false, 'single_tags' => false]);
        update_option('generate_menu_plus_settings', ['sticky_menu' => true]);
        set_theme_mod('generate_copyright', 'Creative Moods');
        set_theme_mod('font_body_variants', ['400', '700']);

        $snapshot = $this->adapter->exportSnapshot();
        $this->committer->commitSnapshot(
            $snapshot,
            new CommitManagedSetRequest('main', 'Initial export', 'Jane Doe', 'jane@example.com')
        );

        update_option('generate_package_backgrounds', 'deactivated');
        update_option('generate_package_elements', 'deactivated');
        update_option('generate_package_menu_plus', 'activated');
        update_option('generate_settings', ['container_width' => 900]);
        update_option('generate_background_settings', ['body_background' => '#000']);
        update_option('generate_blog_settings', ['single_author' => true, 'single_categories' => true, 'single_date' => true, 'single_tags' => true]);
        delete_option('generate_menu_plus_settings');
        set_theme_mod('generate_copyright', 'Changed');
        remove_theme_mod('font_body_variants');

        $result = $this->applyService->apply(new PushPullSettings(
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
            ['generatepress_configuration']
        ));

        self::assertSame(0, $result->createdCount);
        self::assertSame(1, $result->updatedCount);
        self::assertSame('activated', get_option('generate_package_backgrounds'));
        self::assertSame('activated', get_option('generate_package_elements'));
        self::assertSame('deactivated', get_option('generate_package_menu_plus'));
        self::assertSame(['container_width' => 1200], get_option('generate_settings'));
        self::assertSame(['body_background' => '#fff'], get_option('generate_background_settings'));
        self::assertSame(['single_author' => false, 'single_categories' => false, 'single_date' => false, 'single_tags' => false], get_option('generate_blog_settings'));
        self::assertSame(['sticky_menu' => true], get_option('generate_menu_plus_settings'));
        self::assertSame('Creative Moods', get_theme_mod('generate_copyright'));
        self::assertSame(['400', '700'], get_theme_mod('font_body_variants'));
    }
}
