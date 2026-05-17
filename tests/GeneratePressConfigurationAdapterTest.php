<?php

declare(strict_types=1);

namespace PushPull\Tests;

use PHPUnit\Framework\TestCase;
use PushPull\Content\WordPress\GeneratePressConfigurationAdapter;

final class GeneratePressConfigurationAdapterTest extends TestCase
{
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
                'isActive' => false,
                'exportable' => false,
            ],
            'Menu Plus' => [
                'title' => 'Menu Plus',
                'key' => 'generate_package_menu_plus',
                'settings' => 'generate_menu_plus_settings',
                'isActive' => true,
                'exportable' => true,
            ],
        ];
        $GLOBALS['pushpull_test_generatepress_setting_keys'] = [
            'generate_settings',
            'generate_background_settings',
            'generate_menu_plus_settings',
        ];
        $GLOBALS['pushpull_test_generatepress_theme_mod_keys'] = [
            'generate_copyright',
            'font_body_variants',
        ];
    }

    public function testExportSnapshotIncludesModuleStatesOptionsAndThemeMods(): void
    {
        update_option('generate_package_backgrounds', 'activated');
        update_option('generate_package_elements', 'deactivated');
        update_option('generate_package_menu_plus', 'activated');
        update_option('generate_settings', ['container_width' => 1200]);
        update_option('generate_background_settings', ['body_background' => '#fff']);
        update_option('generate_menu_plus_settings', ['sticky_menu' => true]);
        set_theme_mod('generate_copyright', 'Creative Moods');
        set_theme_mod('font_body_variants', ['400', '700']);

        $adapter = new GeneratePressConfigurationAdapter();
        $snapshot = $adapter->exportSnapshot();

        self::assertSame(['generatepress-settings'], $snapshot->orderedLogicalKeys);
        self::assertCount(1, $snapshot->items);

        $payload = $snapshot->items[0]->payload;
        self::assertTrue($payload['moduleStates']['generate_package_backgrounds']['active']);
        self::assertFalse($payload['moduleStates']['generate_package_elements']['active']);
        self::assertSame('generate_menu_plus_settings', $payload['moduleStates']['generate_package_menu_plus']['settingsKey']);
        self::assertArrayNotHasKey('title', $payload['moduleStates']['generate_package_backgrounds']);
        self::assertSame(['container_width' => 1200], $payload['options']['generate_settings']);
        self::assertSame(['body_background' => '#fff'], $payload['options']['generate_background_settings']);
        self::assertSame('Creative Moods', $payload['themeMods']['generate_copyright']);
        self::assertSame(['400', '700'], $payload['themeMods']['font_body_variants']);
    }
}
