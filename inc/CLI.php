<?php
/**
 * WP_CLI Commands
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

use CreativeMoods\PushPull\Api;

/**
 * Class CLI
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
	 * Exports an individual post
	 * all your posts to GitHub
	 *
	 * ## OPTIONS
	 *
	 * <post_id|all>
	 * : The post ID to export or 'all' for full site
	 *
	 * <user_id>
	 * : The user ID you'd like to save the commit as
	 *
	 * ## EXAMPLES
	 *
	 *     wp pushpull export all 1
	 *     wp pushpull export 1 1
	 *
	 * @synopsis <post_id|all> <user_id>
	 *
	 * @param array $args Command arguments.
	 */
	public function export( $args ) {
		list( $post_id, $user_id ) = $args;

		if ( ! is_numeric( $user_id ) ) {
			WP_CLI::error( __( 'Invalid user ID', 'pushpull' ) );
		}

		$this->app->export()->set_user( $user_id );

		if ( 'all' === $post_id ) {
			WP_CLI::line( __( 'Starting full export to GitHub.', 'pushpull' ) );
			$this->app->controller()->export_all();
		} elseif ( is_numeric( $post_id ) ) {
			WP_CLI::line(
				sprintf(
					__( 'Exporting post ID to GitHub: %d', 'pushpull' ),
					$post_id
				)
			);
			$this->app->controller()->export_post( (int) $post_id );
		} else {
			WP_CLI::error( __( 'Invalid post ID', 'pushpull' ) );
		}
	}

	/**
	 * Imports the post in your GitHub repo
	 * into your WordPress blog
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : The user ID you'd like to save the commit as
	 *
	 * <type>
	 * : The type of the post to import
	 *
	 * <name>
	 * : The name of the post to import
	 *
	 * ## EXAMPLES
	 *
	 *     wp pushpull import 1 page our-story
	 *
	 * @synopsis <user_id> <type> <name>
	 *
	 * @param array $args Command arguments.
	 */
	public function import( $args ) {
		list( $user_id, $type, $name ) = $args;

		$this->app->import()->import_post($user_id, $type, $name);
	}

	/**
	 * Fetches the provided sha or the repository's
	 * master branch and caches it.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : The user ID you'd like to save the commit as
	 *
	 * ## EXAMPLES
	 *
	 *     wp pushpull prime --branch=master
	 *     wp pushpull prime --sha=<commit_sha>
	 *
	 * @synopsis [--sha=<commit_sha>] [--branch]
	 *
	 * @param array $args Command arguments.
	 * @param array $assoc_args Command associated arguments.
	 */
	public function prime( $args, $assoc_args ) {
		if ( isset( $assoc_args['branch'] ) ) {
			WP_CLI::line( __( 'Starting branch import.', 'pushpull' ) );

			$commit = $this->app->fetch()->master();

			if ( is_wp_error( $commit ) ) {
				WP_CLI::error(
					sprintf(
						__( 'Failed to import and cache branch with error: %s', 'pushpull' ),
						$commit->get_error_message()
					)
				);
			} else {
				WP_CLI::success(
					sprintf(
						__( 'Successfully imported and cached commit %s from branch.', 'pushpull' ),
						$commit->sha()
					)
				);
			}
		} else if ( isset( $assoc_args['sha'] ) ) {
			WP_CLI::line( 'Starting sha import.' );

			$commit = $this->app->fetch()->commit( $assoc_args['sha'] );

			WP_CLI::success(
				sprintf(
					__( 'Successfully imported and cached commit %s.', 'pushpull' ),
					$commit->sha()
				)
			);
		} else {
			WP_CLI::error( 'Invalid fetch.' );
		}
	}
}
