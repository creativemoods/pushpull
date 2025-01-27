<?php
/**
 * GenerateBlocks class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull\hooks;

use CreativeMoods\PushPull\PushPull;

/**
 * Class WooCommerce
 */
class WooCommerce {
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
		// We might be in REST context where plugin.php is not loaded
		if (!function_exists('is_plugin_active')) {
    		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if (\is_plugin_active('woocommerce/woocommerce.php')) {
			add_filter('pushpull_default_tableexport_woocommerce_wc_tax_rate_classes', array(&$this, 'tableexport_wc_tax_rate_classes'), 10, 2);
			add_filter('pushpull_default_tableexport_woocommerce_woocommerce_tax_rates', array(&$this, 'tableexport_woocommerce_tax_rates'), 10, 2);
			add_filter('pushpull_default_tableimport_woocommerce_wc_tax_rate_classes_get_by_name', array(&$this, 'get_wc_tax_rate_classes_by_name'), 10, 2);
			add_filter('pushpull_default_tableimport_woocommerce_woocommerce_tax_rates_get_by_name', array(&$this, 'get_woocommerce_tax_rates_by_name'), 10, 2);
			add_filter('pushpull_default_tableimport_woocommerce_woocommerce_tax_rates', array(&$this, 'tableimport_woocommerce_tax_rates'), 10, 2);
		}
	}

	/**
	 * Add all tables managed by this plugin that contain data that should be stored in the repository.
	 *
	 * @return void
	 */
	public function add_tables() {
		// We might be in REST context where plugin.php is not loaded
		if (!function_exists('is_plugin_active')) {
    		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return \is_plugin_active('woocommerce/woocommerce.php') ? [
			'wc_tax_rate_classes' => 'wc_tax_rate_classes',
			'woocommerce_tax_rates' => 'woocommerce_tax_rates',
		] : [];
	}

	/**
     * Manipulate wc_tax_rate_classes table data on export
     *
     * @param array $data
     * @return array (first element is the unique name of the row, second element is the data)
	 * 
	 * We remove the tax_rate_class_id field from the data
	 * 
	 */
    public function tableexport_wc_tax_rate_classes(array $data) {
		return [$data['name'], $this->app->utils()->array_without_keys($data,
			['tax_rate_class_id']
		)];
	}

	/**
	 * Get table row from name for wc_tax_rate_classes_by_name
	 *
	 * @param string $name
	 * @return array|bool
	 */
	public function get_wc_tax_rate_classes_by_name(string $name): array|bool {
		global $wpdb;

        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wc_tax_rate_classes WHERE name = %s", $name));
		if ($row) {
			return (array) $row;
		}

		return False;
	}

	/**
	 * Get table row from name for woocommerce_tax_rates
	 *
	 * @param string $name
	 * @return array|bool
	 */
	public function get_woocommerce_tax_rates_by_name(string $name): array|bool {
		global $wpdb;

        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name = %s", $name));
		if ($row) {
			return (array) $row;
		}

		return False;
	}

	/**
     * Manipulate woocommerce_tax_rates table data on export
     *
     * @param array $data
     * @return array (first element is the unique name of the row, second element is the data)
	 * 
	 * We remove the tax_rate_id field from the data
	 * 
	 */
    public function tableexport_woocommerce_tax_rates(array $data) {
		global $wpdb;

		// Remove ID from data
		$data = $this->app->utils()->array_without_keys($data, ['tax_rate_id']);
		// Replace tax_rate_class with tax rate class name
		if (is_int($data['tax_rate_class'])) {
	        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
			$taxrateclass = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$wpdb->prefix}wc_tax_rate_classes WHERE tax_rate_class_id = %d", $data['tax_rate_class']));
			if ($taxrateclass) {
				$data['tax_rate_class'] = $taxrateclass->name;
			}
		}

		return [$data['tax_rate_name'], $data];
	}

	/**
     * Manipulate woocommerce_tax_rates table data on import
     *
     * @param array $data
     * @return array
	 * 
	 */
    public function tableimport_woocommerce_tax_rates(array $data) {
		global $wpdb;

		// Replace tax rate class name with tax_rate_class
        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$taxrateclass = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wc_tax_rate_classes WHERE tax_rate_class_id = %d", $data['tax_rate_class']));
		if ($taxrateclass) {
			$data['tax_rate_class'] = $taxrateclass->tax_rate_class_id;
		}

		return $data;
	}
}
