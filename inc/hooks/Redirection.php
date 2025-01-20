<?php
/**
 * GenerateBlocks class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull\hooks;

use CreativeMoods\PushPull\PushPull;

/**
 * Class Redirection
 */
class Redirection {
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
		if (\is_plugin_active('redirection/redirection.php')) {
			add_filter('pushpull_default_tableexport_redirection_redirection_groups', array(&$this, 'tableexport_redirection_groups'), 10, 2);
			add_filter('pushpull_default_tableexport_redirection_redirection_items', array(&$this, 'tableexport_redirection_items'), 10, 2);
			add_filter('pushpull_default_tableimport_redirection_redirection_groups_get_by_name', array(&$this, 'get_redirection_groups_by_name'), 10, 2);
			add_filter('pushpull_default_tableimport_redirection_redirection_items_get_by_name', array(&$this, 'get_redirection_items_by_name'), 10, 2);
			add_filter('pushpull_default_tableimport_redirection_redirection_items', array(&$this, 'tableimport_redirection_items'), 10, 2);
		}
	}

	/**
	 * Add all tables managed by this plugin that contain data that should be stored in the repository.
	 *
	 * @return void
	 */
	public function add_tables() {
		return \is_plugin_active('redirection/redirection.php') ? [
			'redirection_groups' => 'redirection_groups',
			'redirection_items' => 'redirection_items',
		] : [];
	}

	/**
     * Manipulate redirection_groups table data on export
     *
     * @param array $data
     * @return array (first element is the unique name of the row, second element is the data)
	 * 
	 * We remove the id field from the data
	 * 
	 */
    public function tableexport_redirection_groups(array $data) {
		return [$data['name'], $this->app->utils()->array_without_keys($data,
			['id']
		)];
	}

	/**
	 * Get table row from name for redirection_groups
	 *
	 * @param string $name
	 * @return array|bool
	 */
	public function get_redirection_groups_by_name(string $name): array|bool {
		global $wpdb;

        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}redirection_groups WHERE name = %s", $name));
		if ($row) {
			return (array) $row;
		}

		return False;
	}

	/**
	 * Get table row from name for redirection_items
	 *
	 * @param string $name
	 * @return array|bool
	 */
	public function get_redirection_items_by_name(string $name): array|bool {
		global $wpdb;

        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}redirection_items WHERE url = %s", $name));
		if ($row) {
			return (array) $row;
		}

		return False;
	}

	/**
     * Manipulate redirection_items table data on export
     *
     * @param array $data
     * @return array (first element is the unique name of the row, second element is the data)
	 * 
	 * We remove the id field from the data
	 * 
	 */
    public function tableexport_redirection_items(array $data) {
		global $wpdb;

		// Remove ID from data
		$data = $this->app->utils()->array_without_keys($data, ['id']);

		// Replace group_id with group name
        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$group = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$wpdb->prefix}redirection_groups WHERE id = %d", $data['group_id']));
		if ($group) {
			$data['group_id'] = $group->name;
		}

		return [$data['url'], $data];
	}

	/**
     * Manipulate redirection_items table data on import
     *
     * @param array $data
     * @return array
	 * 
	 */
    public function tableimport_redirection_items(array $data) {
		global $wpdb;

		// Replace group name with group_id
        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$group = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}redirection_groups WHERE name = %s", $data['group_id']));
		if ($group) {
			$data['group_id'] = $group->id;
		}

		return $data;
	}
}
