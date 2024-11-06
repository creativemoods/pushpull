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
		add_filter('pushpull_default_meta_generateblocks_patterns_tree', array(&$this, 'meta_generateblocks_patterns_tree'), 10, 2);
		add_filter('pushpull_default_meta__generate_element_display_conditions', array(&$this, 'meta__generate_element_display_conditions'), 10, 2);
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
		return str_replace(get_home_url(), "@@DOMAIN@@", $value);
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
		$this->app->write_log("Deleting _generateblocks_reusable_blocks");
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
		$unserialized = maybe_unserialize($value[0]);
		foreach ($unserialized as $item => $displaycond) {
			if ($displaycond['rule'] === "post:page") {
				$tmppost = get_post($displaycond['object']);
				if ($tmppost) {
					$unserialized[$item]['object'] = $tmppost->post_type."/".$tmppost->post_name;
				}
			}
		}

		return maybe_serialize($unserialized);
    }

	/**
	 * Find patterns in blocks code
	 *
	 * @param array $blocks
	 * @return array
	 */
	protected function find_patterns($blocks) {
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
	}

	/**
	 * Extract pattern Ids from a WP_Post
	 *
	 * @param WP_Post
	 *
	 * @return array
	 */
	protected function extract_patternids(WP_Post $post) {
		$data = [];
		$patterns = $this->find_patterns(parse_blocks($post->post_content));
		foreach ($patterns as $pattern) {
			$data[] = $pattern;
		}

		return $data;
	}

    /**
     * Manipulate data on export
     *
     * @param array $data
     * @param WP_Post $post
     * @return array
     */
    public function export(array $data, WP_Post $post) {
		// Handle references to patterns
		$patternids = $this->extract_patternids($post);
		$patternlist = [];
		foreach ($patternids as $patternid) {
			$pattern = get_post($patternid);
			$patternlist[] = ['name' => $pattern->post_name, 'id' => $pattern->ID];
		}
		$data['patterns'] = $patternlist;

        return $data;
    }

	/**
	 * Replace patterns in blocks code
	 */
	protected function replace_patterns($blocks, $patterns) {
		$res = [];
		foreach ($blocks as $block) {
			// Check if the block has attributes and a ref attribute
			if (isset($block['attrs']) && isset($block['attrs']['ref'])) {
				foreach ($patterns as $pattern) {
					if ($block['attrs']['ref'] === $pattern->id) {
						// We have a replacement match
						$tmppost = $this->app->utils()->getLocalPostByName('wp_block', $pattern->name);
						$this->app->write_log("Replacing ".$block['attrs']['ref']." with ".$tmppost->post_name." with ID ".$tmppost->ID);
						$block['attrs']['ref'] = $tmppost->ID;
					}
				}
			}
			$newBlock = $block;
			// Recursively search in innerBlocks if they exist
			if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$innerRes = $this->replace_patterns($block['innerBlocks'], $patterns);
				$newBlock['innerBlocks'] = $innerRes;
			}
			$res[] = $newBlock;
		}

		return $res;
	}

    /**
     * Manipulate data on import
     *
     * @param stdClass $post
     * @return void
     */
    public function import(stdClass $post) {
		// Replace references to patterns
		if (property_exists($post, 'patterns') && count($post->patterns) > 0) {
			// https://wordpress.stackexchange.com/questions/391381/gutenberg-block-manipulation-undo-parse-blocks-with-serialize-blocks-result
			$parsed = parse_blocks(str_replace('\\"', '"', $post->post_content));
			$replaced = $this->replace_patterns($parsed, $post->patterns);
			$post->post_content = serialize_blocks($replaced);
		}

		if (property_exists($post, 'meta')) {
			foreach ($post->meta as $key => $value) {
				// Before unserializing, we need to replace the domain in the whole string
				if ($key === "generateblocks_patterns_tree") {
					// Also replace the domain in the pattern tree meta value
					$value = str_replace("@@DOMAIN@@", get_home_url(), $value);
				}
				// Unserialize because https://developer.wordpress.org/reference/functions/update_metadata/ "...or itself a PHP-serialized string"
				$value = maybe_unserialize($value);
				if ($key === "_generate_element_display_conditions") {
					// We need to reset the post name to its ID if it exists
					foreach ($value as $item => $displaycond) {
						if ($displaycond['rule'] === "post:page") {
							$arr = explode('/', $displaycond['object']); // e.g. "page/our-story"
							$tmppost = $this->app->utils()->getLocalPostByName($arr[0], $arr[1]);
							if ($tmppost !== null) {
								$value[$item]['object'] = $tmppost->ID;
							}
						}
					}
				}
				update_post_meta($post->ID, $key, $value);
			}
		}
    }
}
