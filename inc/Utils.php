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
	 * returns a subset of an array
	 *
	 * @param array $haystack
	 * @param array $needle
	 * @return array
	 */
	function sub_array(array $haystack, array $needle): array
	{
		return array_intersect_key($haystack, array_flip($needle));
	}

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

	/**
	 * Get list of providers.
	 *
	 * @return array
	 */
	public function getProviders() {
		return [
			[
				'id' => 'github',
				'name' => 'GitHub',
				'proonly' => false,
				'url' => 'https://api.github.com',
				'disabledurl' => true,
			],
			[
				'id' => 'gitlab',
				'name' => 'GitLab',
				'proonly' => true,
				'url' => 'https://gitlab.com/api/v4',
				'disabledurl' => true,
			],
/*			[
				'id' => 'bitbucket',
				'name' => 'Bitbucket',
				'proonly' => true,
				'url' => 'https://api.bitbucket.org/2.0',
				'disabledurl' => true,
			],
			[
				'id' => 'custom',
				'name' => 'Custom',
				'proonly' => true,
				'url' => 'https://...',
				'disabledurl' => false,
			],*/
		];
	}
}
