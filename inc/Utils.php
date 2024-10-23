<?php
/**
 * Utils class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

/**
 * Class Utils
 */
class Utils {
	/**
	 * Get post by name
	 *
	 * @param string $name
	 * @param string $post_type
	 * @return \WP_Post|null
	 */
	public function getLocalPostByName(string $type, string $name) {
		$query = new \WP_Query([
			"post_type" => $type,
			"name" => $name
		]);

		return $query->have_posts() ? reset($query->posts) : null;
	}
}
