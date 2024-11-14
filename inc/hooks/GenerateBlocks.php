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
 * Class GenerateBlocks
 */
class GenerateBlocks {
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
		add_filter('pushpull_default_meta__generateblocks_reusable_blocks', array(&$this, 'meta__generateblocks_reusable_blocks'), 10, 2);
		add_filter('pushpull_default_meta__generateblocks_dynamic_css_version', array(&$this, 'meta__generateblocks_dynamic_css_version'), 10, 2);
		add_filter('pushpull_default_meta_generateblocks_patterns_tree', array(&$this, 'meta_generateblocks_patterns_tree'), 10, 2);
		add_filter('pushpull_default_meta__generate_element_display_conditions', array(&$this, 'meta__generate_element_display_conditions'), 10, 2);
		add_filter('pushpull_default_meta__generate_element_exclude_conditions', array(&$this, 'meta__generate_element_display_conditions'), 10, 2);
		add_filter('pushpull_default_export_generateblocks', array(&$this, 'export'), 10, 2);
		add_action('pushpull_default_import_generateblocks', array(&$this, 'import'), 10, 1);
	}

    /**
     * meta_generateblocks_patterns_tree filter
     *
     * @param mixed $value
     * @return mixed
     */
    public function meta_generateblocks_patterns_tree($value) {
		// Also replace the domain in the pattern tree meta value
		foreach ($value as $k => $v) {
			$value[$k] = str_replace(get_home_url(), "@@DOMAIN@@", $v);
		}
		return $value;
    }

    /**
     * meta__generateblocks_reusable_blocks filter
     *
     * @param mixed $value
     * @return mixed
     */
    public function meta__generateblocks_reusable_blocks($value) {
        // We can delete this key.
        // This is handled by extract_patternids().
        // This value will be regenerated by post_update_option() in generateblocks/includes/class-enqueue-css.php
		//$this->app->write_log("Deleting _generateblocks_reusable_blocks");
        return False;
    }

    /**
     * meta__generateblocks_dynamic_css_version filter
     *
     * @param mixed $value
     * @return mixed
     */
    public function meta__generateblocks_dynamic_css_version($value) {
        // We can delete this key.
		// This value will be regenerated by post_update_option() in generateblocks/includes/class-enqueue-css.php
		//$this->app->write_log("Deleting _generateblocks_dynamic_css_version");
        return False;
    }

    /**
     * meta__generate_element_display_conditions filter
     *
     * @param mixed $value
     * @return mixed
     */
    public function meta__generate_element_display_conditions($value) {
		// Rewrite post IDs into post names
		foreach ($value as $k => $v) {
			$unserialized = maybe_unserialize($v);
			foreach ($unserialized as $item => $displaycond) {
				if ($displaycond['rule'] === "post:page") {
					$tmppost = get_post($displaycond['object']);
					if ($tmppost) {
						$unserialized[$item]['object'] = $tmppost->post_type."/".$tmppost->post_name;
					}
				}
			}
			$value[$k] = maybe_serialize($unserialized);
		}

		return $value;
    }

	/**
	 * Find patterns in blocks code
	 *
	 * @param array $blocks
	 * @return array
	 */
/*	protected function find_patterns($blocks) {
		$patterns = [];
		foreach ($blocks as $block) {
			// Check if the block has attributes and a ref attribute
			if (isset($block['attrs']) && isset($block['attrs']['ref'])) {
				$patterns[] = $block['attrs']['ref'];
			}
			// Recursively search in innerBlocks if they exist
			if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$innerPatterns = $this->find_patterns($block['innerBlocks']);
				$patterns = array_merge($patterns, $innerPatterns);
			}
		}

		return $patterns;
	}*/

	/**
	 * Extract pattern Ids from a WP_Post
	 *
	 * @param WP_Post
	 *
	 * @return array
	 */
/*	protected function extract_patternids(WP_Post $post) {
		$data = [];
		$patterns = $this->find_patterns(parse_blocks($post->post_content));
		foreach ($patterns as $pattern) {
			$data[] = $pattern;
		}

		return $data;
	}*/

	/**
	 * Replace pattern ids in blocks code
	 *
	 * @param array $blocks
	 * @return array
	 */
	protected function replace_patternids($blocks) {
		foreach ($blocks as $k => $block) {
			// Check if the block has attributes and a ref attribute
			if (isset($block['attrs']) && isset($block['attrs']['ref'])) {
				$post = get_post($block['attrs']['ref']);
				if ($post) {
					$blocks[$k]['attrs']['ref'] = $post->post_name;
				}
			}
			// Recursively search in innerBlocks if they exist
			if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$blocks[$k]['innerBlocks'] = $this->replace_patternids($block['innerBlocks']);
			}
		}

		return $blocks;
	}

    /**
     * Manipulate data on export
	 * Warning! You need to work on $data, not on $post because $data contains previously modified data while $post down not.
     *
     * @param array $data
     * @param WP_Post $post
     * @return array
     */
    public function export(array $data, WP_Post $post) {
		$data['post_content'] = serialize_blocks($this->replace_patternids(parse_blocks($data['post_content'])));
        return $data;
    }

	/**
	 * Replace patterns in blocks code
	 */
	protected function replace_patterns($blocks) {
		foreach ($blocks as $k => $block) {
			// Check if the block has attributes and a ref attribute and the attribute is not an ID (it has been replaced with the pattern name)
			if (isset($block['attrs']) && isset($block['attrs']['ref']) && !is_numeric($block['attrs']['ref'])) {
				$tmppost = $this->app->utils()->getLocalPostByName('wp_block', $block['attrs']['ref']);
				//$this->app->write_log("Replacing ".$block['attrs']['ref']." with ".$tmppost->post_name." with ID ".$tmppost->ID);
				$blocks[$k]['attrs']['ref'] = $tmppost->ID;
			}
			// Recursively search in innerBlocks if they exist
			if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$blocks[$k]['innerBlocks'] = $this->replace_patterns($block['innerBlocks']);
			}
		}

		return $blocks;
	}

	/**
     * Manipulate data on import
     *
     * @param stdClass $post
     * @return void
     */
    public function import(stdClass $post) {
		// Replace references to patterns with pattern ids
		$tmppost = array(
			'ID' => $post->ID,
			// https://wordpress.stackexchange.com/questions/391381/gutenberg-block-manipulation-undo-parse-blocks-with-serialize-blocks-result
			'post_content' => serialize_blocks($this->replace_patterns(parse_blocks(str_replace('\\"', '"', $post->post_content)))),
		);
		wp_update_post($tmppost, true);

		if (property_exists($post, 'meta')) {
			foreach ($post->meta as $key => $values) {
				// Before unserializing, we need to replace the domain in the whole string
				if ($key === "generateblocks_patterns_tree") {
					// Replace the values imported by Puller::pull()
					delete_post_meta($post->ID, $key);
					foreach ($values as $v) {
						// Also replace the domain in the pattern tree meta value
						$v = str_replace("@@DOMAIN@@", get_home_url(), $v);
						add_post_meta($post->ID, $key, $v);
					}
				}
				if ($key === "_generate_element_display_conditions" || $key === "_generate_element_exclude_conditions") {
					// We need to reset the post name to its ID if it exists
					// Replace the values imported by Puller::pull()
					delete_post_meta($post->ID, $key);
					foreach ($values as $v) {
						// Unserialize because https://developer.wordpress.org/reference/functions/update_metadata/ "...or itself a PHP-serialized string"
						$vobject = maybe_unserialize($v);
						foreach ($vobject as $item => $displaycond) {
							$this->app->write_log($item);
							$this->app->write_log($displaycond);
							if ($displaycond['rule'] === "post:page") {
								$arr = explode('/', $displaycond['object']); // e.g. "page/our-story"
								$tmppost = $this->app->utils()->getLocalPostByName($arr[0], $arr[1]);
								if ($tmppost !== null) {
									$vobject[$item]['object'] = $tmppost->ID;
								}
							}
							add_post_meta($post->ID, $key, $vobject);
						}
					}
				}
			}
		}

    }
}
