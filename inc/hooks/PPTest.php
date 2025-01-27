<?php
/**
 * GenerateBlocks class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull\hooks;

/*if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}*/

use CreativeMoods\PushPull\PushPull;
use stdClass;
use WP_Post;

/**
 * Class PPTest
 */
class PPTest {
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
		if (\is_plugin_active('pptest/pptest.php')) {
			add_filter('pushpull_default_term_taxname', array(&$this, 'term_taxname'), 10, 2);
			add_filter('pushpull_default_meta_custom_meta_key_simple_managed', array(&$this, 'meta_custom_meta_key_simple_managed'), 10, 2);
			add_filter('pushpull_default_meta_custom_meta_key_multiple_managed', array(&$this, 'meta_custom_meta_key_multiple_managed'), 10, 2);
			add_filter('pushpull_default_export_pptest', array(&$this, 'export'), 10, 2);
			add_action('pushpull_default_import_pptest', array(&$this, 'import'), 10, 1);
		}
	}

    /**
     * term_taxname filter
     *
     * @param mixed $term
     * @param array &$data - passed by reference
     * @return mixed
     */
    public function term_taxname($term, &$data) {
		// We delete TermA but we keep TermB
		if ($term->slug === "terma") {
			return False;
		}

		return [
			'slug' => $term->slug,
			'name' => $term->name,
		];
    }

    /**
     * meta_custom_meta_key_simple_managed filter
     *
     * @param mixed $value
     * @return mixed
     */
    public function meta_custom_meta_key_simple_managed($value) {
		foreach ($value as $k => $v) {
			$value[$k] = str_replace(get_home_url(), "@@DOMAIN@@", $v);
		}

		return $value;
    }

    /**
     * meta_custom_meta_key_multiple_managed filter
     *
     * @param mixed $value
     * @return mixed
     */
    public function meta_custom_meta_key_multiple_managed($value) {
		foreach ($value as $k => $v) {
			$value[$k] = str_replace(get_home_url(), "@@DOMAIN@@", $v);
		}

		return $value;
    }

    /**
     * Manipulate data on export
     *
     * @param array $data
     * @param WP_Post $post
     * @return array
     */
    public function export(array $data, WP_Post $post) {
		// Return quickly if not a pptest_customtype post
		if ($post->post_type !== 'pptest_customtype') {
			return $data;
		}
		$data['pptest'] = "test";
		return $data;
	}

    /**
     * Manipulate data on import
     *
     * @param stdClass $post
     * @return void
     */
    public function import(stdClass $post) {
		// Return quickly if not a pptest_customtype post
		if ($post->post_type !== 'pptest_customtype') {
			return;
		}
		// Replace references to patterns
		if (property_exists($post, 'pptest') && $post->pptest === "test") {
			$tmppost = array(
				'ID' => $post->ID,
				'post_content' => $post->post_content." - import OK",
			);
			wp_update_post($tmppost, true);
		}

		if (property_exists($post, 'meta')) {
			foreach ($post->meta as $key => $value) {
				if ($key === "custom_meta_key_simple_managed") {
					// Replace the values imported by Puller::pull()
					delete_post_meta($post->ID, $key);
					foreach ($value as $v) {
						$v = str_replace("@@DOMAIN@@", get_home_url(), $v);
						$this->app->write_log("Setting meta: ".$v);
						add_post_meta($post->ID, $key, $v);
					}
				}
				if ($key === "custom_meta_key_multiple_managed") {
					// Replace the values imported by Puller::pull()
					delete_post_meta($post->ID, $key);
					foreach ($value as $v) {
						$v = str_replace("@@DOMAIN@@", get_home_url(), $v);
						$this->app->write_log("Setting meta: ".$v);
						add_post_meta($post->ID, $key, $v);
					}
				}
			}
		}
    }
}
