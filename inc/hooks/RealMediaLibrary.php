<?php
/**
 * RealMediaLibrary class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull\hooks;

use CreativeMoods\PushPull\PushPull;
use WP_Post;

/**
 * Class RealMediaLibrary
 */
class RealMediaLibrary {
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
		add_filter('pushpull_default_export_real-media-library-lite', array(&$this, 'export'), 10, 2);
	}

    /**
     * Manipulate data on export
     *
     * @param array $data
     * @param WP_Post $post
     * @return array
     */
    public function export(array $data, WP_Post $post) {
		// If we have a media folder plugin, add location
		if ($post->post_type === "attachment" && function_exists('wp_attachment_folder')) {
			$folder = wp_rml_get_by_id(wp_attachment_folder($post->ID));
			if (is_rml_folder($folder)) {
		        fwrite(STDERR, print_r($folder, TRUE));
				$data['folder'] = $folder->getName();
			}
		}

		$this->app->write_log("Setting folder: ".json_encode($data));
        return $data;
    }
}
