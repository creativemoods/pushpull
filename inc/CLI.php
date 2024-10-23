<?php
/**
 * WP_CLI Commands
 * @package PushPull
 */

namespace CreativeMoods\PushPull;
use WP_CLI_Command;

/**
 * PushPull CLI
 */
class CLI extends WP_CLI_Command {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Grab the Application container on instantiation.
	 */
	public function __construct() {
		$this->app = PushPull::$instance;
	}

	/**
	 * Push a post from your WordPress blog into your GitHub repo
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : The type of the post to push
	 *
	 * <name>
	 * : The name of the post to push
	 *
	 * ## EXAMPLES
	 *
	 *     wp pushpull push page our-story
	 *
	 * @synopsis <type> <name>
	 *
	 * @param array $args Command arguments.
	 */
	public function push( $args ) {
		list( $type, $name ) = $args;

		$this->app->pusher()->push($type, $name);
	}

	/**
	 * Pull a post from your GitHub repo into your WordPress blog
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : The type of the post to pull
	 *
	 * <name>
	 * : The name of the post to pull
	 *
	 * ## EXAMPLES
	 *
	 *     wp pushpull pull page our-story
	 *
	 * @synopsis <type> <name>
	 *
	 * @param array $args Command arguments.
	 */
	public function pull( $args ) {
		list( $type, $name ) = $args;

		$this->app->puller()->pull($type, $name);
	}
}
