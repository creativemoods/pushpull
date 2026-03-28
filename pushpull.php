<?php

/**
 * Plugin Name: PushPull
 * Plugin URI: https://github.com/creativemoods/pushpull
 * Description: Git-backed content workflows for selected WordPress content domains.
 * Version: 0.0.11
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: CreativeMoods
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pushpull
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('PUSHPULL_PLUGIN_FILE', __FILE__);
define('PUSHPULL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PUSHPULL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PUSHPULL_VERSION', '0.0.11');

if (is_readable(PUSHPULL_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once PUSHPULL_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    require_once PUSHPULL_PLUGIN_DIR . 'src/Plugin/Autoloader.php';

    \PushPull\Plugin\Autoloader::register();
}

register_activation_hook(PUSHPULL_PLUGIN_FILE, static function (): void {
    $repository = new \PushPull\Settings\SettingsRepository();
    $repository->save($repository->defaults());

    (new \PushPull\Persistence\Migrations\SchemaMigrator())->install();
});

add_action('plugins_loaded', static function (): void {
    (new \PushPull\Plugin\Plugin())->boot();
});
