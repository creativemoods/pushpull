<?php
/**
 * Deleter.
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

use CreativeMoods\PushPull\providers\GitProviderFactory;
use stdClass;
use WP_Error;

/**
 * Class Deleter
 */
class Deleter {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Initializes a new deletion manager.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
	}

	/**
	 * Delete a post by type and name.
	 *
	 * @param string $type the type of post.
	 * @param string $name the name of the post.
	 *
	 * @return bool|WP_Error
	 */
	public function deleteByName($type, $name) {
		$this->app->write_log(__( 'Starting deletion.', 'pushpull' ));

		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);

		// Get attachment from Git
		// TODO replace slashes
		$done = $gitProvider->deleteRemotePostByName($type, $name);
		if (!$done || is_wp_error($done)) {
			return new WP_Error( '404', esc_html__( 'Post not found', 'pushpull' ), array( 'status' => 404 ) );
		}

		$this->app->write_log(__( 'End deletion.', 'pushpull' ));

		return true;
	}
}
