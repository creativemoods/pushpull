<?php
/**
 * Polylang class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull\hooks;

use CreativeMoods\PushPull\PushPull;
use stdClass;
use WP_Post;

/**
 * Class Polylang
 */
class Polylang {
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
		if (\is_plugin_active('polylang/polylang.php')) {
			add_filter('pushpull_default_term_post_translations', array(&$this, 'term_post_translations'), 10, 2);
			add_filter('pushpull_default_term_language', array(&$this, 'term_language'), 10, 2);
			add_action('pushpull_default_import_polylang', array(&$this, 'import'), 10, 1);
		}
	}

    /**
     * term_post_translations filter
     *
     * @param mixed $term
     * @param array &$data - passed by reference
     * @return mixed
     */
    public function term_post_translations($term, &$data) {
		// Rewrite post IDs into post names for polylang post_translations taxonomies
		$newvals = [];
		$description = maybe_unserialize($term->description);
		foreach($description as $lang => $id) {
			$tmppost = get_post($id);
			$newvals[$lang] = $tmppost->post_type."/".$tmppost->post_name;
		}
		ksort($newvals);
		$data['translations'] = maybe_serialize($newvals);
		//$this->app->write_log("Setting translations: ".json_encode($data['translations']));

		// We can delete this term.
        return False;
    }

    /**
     * term_language filter
     *
     * @param mixed $term
     * @param array &$data - passed by reference
     * @return mixed
     */
    public function term_language($term, &$data) {
		$data['language'] = $term->slug;
		//$this->app->write_log("Setting language: ".json_encode($data['language']));

		// We can delete this term.
        return False;
    }

    /**
     * Manipulate data on import
     *
     * @param stdClass $post
     * @return void
     */
    public function import(stdClass $post) {
		//$this->app->write_log("Import pll");
		if (property_exists($post, 'language') && function_exists('pll_set_post_language')) {
			pll_set_post_language($post->ID, $post->language);
		}
		if (property_exists($post, 'translations') && function_exists('pll_save_post_translations')) {
			// Change back from post names to IDs
			$newvals = [];
			$description = maybe_unserialize($post->translations);
			$found = true;
			foreach($description as $lang => $name) {
				$arr = explode('/', $name); // e.g. "page/our-story"
				$tmppost = $this->app->utils()->getLocalPostByName($arr[0], $arr[1]);
				if ($tmppost !== null) {
					$newvals[$lang] = $tmppost->ID;
				} else {
					$found = false;
				}
			}
			if ($found) {
				//$this->app->write_log("Setting post translation: ".json_encode($newvals));
				pll_save_post_translations($newvals);
			}
		}
    }
}
