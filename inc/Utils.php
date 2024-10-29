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
