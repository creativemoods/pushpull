<?php
/**
 * Deployer.
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

/**
 * Class Deployer
 */
class Deployer {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Initializes a new deployer.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
	}

	/**
	 * Deploy configuration and contents.
	 *
	 * @param int $id the id if the deploy item.
	 * 
	 * @return bool — The result.
	 */
	public function deploy($id): bool {
		global $wpdb;

		// Define the table name
		$table_name = $wpdb->prefix . $this->app::PP_DEPLOY_TABLE;

        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$deployitem = $wpdb->get_row($wpdb->prepare('SELECT * FROM %s WHERE id = %d', $table_name, $id));
		if ($deployitem->type === 'option_set') {
			return update_option($deployitem->name, $deployitem->value);
		}

		return false;
	}
}
