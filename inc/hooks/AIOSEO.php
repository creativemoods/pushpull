<?php
/**
 * GenerateBlocks class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull\hooks;

use CreativeMoods\PushPull\PushPull;
use stdClass;
use WP_Post;

/**
 * Class AIOSEO
 */
class AIOSEO {
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
		if (is_plugin_active('generateblocks/plugin.php')) {
			add_filter('pushpull_default_export_all-in-one-seo-pack', array(&$this, 'export'), 10, 2);
			add_action('pushpull_default_import_all-in-one-seo-pack', array(&$this, 'import'), 10, 1);
		}
	}

    /**
     * Manipulate data on export
     *
     * @param array $data
     * @param WP_Post $post
     * @return array
     */
    public function export(array $data, WP_Post $post) {
		global $wpdb;
		$aioseo_data = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d",
			$post->ID
		), ARRAY_A);

		if ($aioseo_data) {
			// Remove id and post_id and dates that change regularly
			// Unset images, they will be regenerated
			unset($aioseo_data['id'], $aioseo_data['post_id'], $aioseo_data['image_scan_date'], $aioseo_data['updated'], $aioseo_data['images']);
			$data['aioseo'] = $aioseo_data;
		}

		return $data;
	}

    /**
     * Manipulate data on import
     *
     * @param stdClass $post
     * @return void
     */
    public function import(stdClass $post) {
		if (isset($post->aioseo)) {
			global $wpdb;
			$aioseo_data = (array) $post->aioseo;
			$aioseo_data['post_id'] = $post->ID;

			$wpdb->replace(
				"{$wpdb->prefix}aioseo_posts",
				$aioseo_data
			);
		}
    }
}
