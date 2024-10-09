<?php
/**
 * Admin UI
 */

namespace CreativeMoods\PushPull;

use CreativeMoods\PushPull\PushPull;

/**
 * Class Admin
 */
class Admin {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	public function __construct(PushPull $app) {
		$this->app = $app;
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
	//	add_action( 'current_screen', array( $this, 'trigger_cron' ) );
	}

	/**
	 * Init admin menu
	 */
	public function pushpull_admin_page() {
		require_once plugin_dir_path( __FILE__ ) . '../templates/app.php';
	}

	/**
	 * Enqueue scripts and style
	 */
	public function pushpull_admin_enqueue_scripts($admin_page) {
		if ( 'toplevel_page_pushpull' !== $admin_page ) {
			return;
		}
		$asset_file = plugin_dir_path( __FILE__ ) . '../build/index.asset.php';
		if (!file_exists($asset_file)) {
			$this->app->write_log("No asset file");
			return;
		}
		$asset = include $asset_file;
		wp_enqueue_style('pushpull-style',
			plugin_dir_url( __FILE__ ) . '../build/index.css',
			array_filter(
				$asset['dependencies'],
				function ($style) {
					return wp_style_is($style, 'registered');
				}
			),
			$asset['version'],
		);
		wp_enqueue_script(
			'pushpull-script',
			plugin_dir_url( __FILE__ ) . '../build/index.js',
			$asset['dependencies'],
			$asset['version'],
			array('in_footer' => true)
		);
	}

	/**
	 * Add options menu to admin navbar
	 */
	public function add_admin_menu() {
		add_menu_page( __( 'PushPull', 'pushpull'), __( 'PushPull', 'pushpull'), 'manage_options', 'pushpull', array( $this, 'pushpull_admin_page' ), 'dashicons-cloud-saved', '85' );
		add_action( 'admin_enqueue_scripts', array( $this, 'pushpull_admin_enqueue_scripts' ) );
	}
}
