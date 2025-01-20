<?php
/**
 * GenerateBlocks class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull\hooks;

if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

use CreativeMoods\PushPull\PushPull;

/**
 * Class PushPull
 */
class _PushPull {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Initializes new filter hooks.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
	}

	/**
	 * Add all filters
	 *
	 * @return void
	 */
	public function add_hooks() {
		// This is the only plugin where we don't check if it's active
		add_filter('pushpull_default_tableexport__pushpull_pushpull_deploy', array(&$this, 'tableexport_pushpull_deploy'), 10, 2);
		add_filter('pushpull_default_tableimport__pushpull_pushpull_deploy_get_by_name', array(&$this, 'get_pushpull_deploy_by_name'), 10, 2);	}

	/**
	 * Add all tables managed by this plugin that contain data that should be stored in the repository.
	 *
	 * @return void
	 */
	public function add_tables() {
		return \is_plugin_active('pushpull/PushPull.php') ? [
			'pushpull_deploy' => 'pushpull_deploy',
		] : [];
	}

	/**
     * Manipulate pushpull_deploy table data on export
     *
     * @param array $data
     * @return array (first element is the unique name of the row, second element is the data)
	 * 
	 * We remove the id field from the data
	 * 
	 */
    public function tableexport_pushpull_deploy(array $data) {
		return [$data['name'], $this->app->utils()->array_without_keys($data,
			['id']
		)];
	}

	/**
	 * Get table row from name for pushpull_deploy
	 *
	 * @param string $name
	 * @return array|bool
	 */
	public function get_pushpull_deploy_by_name(string $name): array|bool {
		global $wpdb;

		// Define the table name
		$table_name = $wpdb->prefix . $this->app::PP_DEPLOY_TABLE;

        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %s WHERE name = %s", $table_name, $name));
		if ($row) {
			return (array) $row;
		}

		return False;
	}
}
