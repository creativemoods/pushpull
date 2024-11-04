<?php
/**
 * Provides REST API for React
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

use WP_REST_Request;
use CreativeMoods\PushPull\providers\GitProviderFactory;

/**
 * Class Rest
 */
class Rest {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * Instantiates a new Rest object.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	public function register_rest_route() {
		register_rest_route('pushpull/v1', '/settings/', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_settings'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/settings/', array(
			'methods' => 'POST',
			'callback' => array( $this, 'set_settings'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/posttypes/', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_post_types'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/branches/', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_branches'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/posts/', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_posts'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/diff/', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_diff'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/repo/local', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_local_repo'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/repo/remote', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_remote_repo'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/repo/diff', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_diff_repo'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/pull/', array(
			'methods' => 'POST',
			'callback' => array( $this, 'pull'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/push/', array(
			'methods' => 'POST',
			'callback' => array( $this, 'push'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/providers/', array(
			'methods' => 'GET',
			'callback' => array( $this->app->utils(), 'getProviders'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/delete/', array(
			'methods' => 'POST',
			'callback' => array( $this, 'delete'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
	}

	/**
	 * Get the diff between the local and remote versions of a post.
	 *
	 * @param WP_REST_Request $data
	 * @return array
	 */
	public function get_diff(WP_REST_Request $data) {
		$params = $data->get_query_params();
		$params['post_type'] = sanitize_text_field($params['post_type']);
		$params['post_name'] = sanitize_text_field($params['post_name']);
		// Local
		$post = $this->app->utils()->getLocalPostByName($params['post_type'], $params['post_name']);
		$local = $this->app->pusher()->create_post_export($post);
		// Remote
		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
		$remote = $gitProvider->getRemotePostByName($params['post_type'], $params['post_name']);

		return ['local' => wp_json_encode($local, JSON_PRETTY_PRINT), 'remote' => wp_json_encode($remote, JSON_PRETTY_PRINT)];
	}

	/**
	 * Get list of posts of type post_type.
	 *
	 * @param WP_REST_Request $data
	 * @return array
	 */
	public function get_posts(WP_REST_Request $data) {
		$params = $data->get_query_params();
		$params['post_type'] = sanitize_text_field($params['post_type']);
		$posts = get_posts(['numberposts' => -1, 'post_type' => $params['post_type']]);

		$res = [];
		foreach ($posts as $post) {
			$res[$post->post_name] = $post->post_title;
		}

		return $res;
	}

	/**
	 * Gets a list of all registered post type names.
	 *
	 * @return string[] — An array of post type names.
	 */
	public function get_post_types() {
		return get_post_types(array(), 'names', 'and');
	}

	/**
	 * Get a list of branches for a provider, url, token and repository.
	 *
	 * @param WP_REST_Request $data
	 * @return string[] — An array of branches.
	 */
	public function get_branches(WP_REST_Request $data) {
		$params = $data->get_query_params();

		$provider = sanitize_text_field($params['provider']);
		$url = sanitize_text_field($params['url']);
		$repository = sanitize_text_field($params['repository']);
		$token = sanitize_text_field($params['token']);

		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);

		return $gitProvider->getBranches($url, $repository, $token);
	}

	/**
	 * Returns the current settings.
	 *
	 * @return array — The current settings.
	 */
	public function get_settings() {
		return ['provider' => get_option('pushpull_provider', 'github'), 'posttypes' => get_option('pushpull_post_types', []), 'oauth-token' => get_option('pushpull_oauth_token'), 'host' => get_option('pushpull_host'), 'repository' => get_option('pushpull_repository'), 'branch' => get_option('pushpull_branch', 'main')];
	}

	/**
	 * Sets the settings.
	 *
	 * @param WP_REST_Request $data
	 * @return array — The new settings.
	 */
	public function set_settings(WP_REST_Request $data) {
		$params = $data->get_json_params();

		$params['provider'] = sanitize_text_field($params['provider']);

		// Check provider is in the list of providers
		if (!in_array($params['provider'], array_map(function($v) { return $v['id']; }, $this->app->utils()->getProviders()))) {
			return new \WP_Error('invalid_provider', __('The provided provider is not valid.', 'pushpull'));
		}
		update_option('pushpull_provider', $params['provider']);

		$params['host'] = sanitize_text_field($params['host']);
		if (!filter_var($params['host'], FILTER_VALIDATE_URL)) {
			return new \WP_Error('invalid_host', __('The provided url is not a valid URL.', 'pushpull'));
		}
		update_option('pushpull_host', $params['host']);

		$params['repository'] = sanitize_text_field($params['repository']);
		if (!preg_match('/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+$/', $params['repository'])) {
			return new \WP_Error('invalid_repository', __('The provided repository is not valid. It should contain a slash and be properly formatted.', 'pushpull'));
		}
		update_option('pushpull_repository', $params['repository']);

		$params['oauth-token'] = sanitize_text_field($params['oauth-token']);
		update_option('pushpull_oauth_token', $params['oauth-token']);

		$params['branch'] = sanitize_text_field($params['branch']);
		update_option('pushpull_branch', $params['branch']);

		$params['posttypes'] = array_map('sanitize_text_field', $params['posttypes']);
		update_option('pushpull_post_types', $params['posttypes']);

		// Invalidate cache
		delete_transient('pushpull_remote_repo_files');

		return $this->get_settings();
	}

	/**
	 * Get local repository as JSON
	 *
	 * @return string
	 */
	public function get_local_repo() {
		return wp_json_encode($this->app->repository()->local_tree(), JSON_PRETTY_PRINT);
	}

	/**
	 * Get remote repository as JSON
	 *
	 * @return string
	 */
	public function get_remote_repo() {
		return wp_json_encode($this->app->repository()->remote_tree(), JSON_PRETTY_PRINT);
	}

	/**
	 * Get local and remote repository as JSON
	 *
	 * @return string
	 */
	public function get_diff_repo() {
		return wp_json_encode($this->app->repository()->diff_tree(), JSON_PRETTY_PRINT);
	}

	/**
	 * Pulls a post.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function pull(WP_REST_Request $data) {
		$params = $data->get_json_params();
		$params['posttype'] = sanitize_text_field($params['posttype']);
		$params['postname'] = sanitize_text_field($params['postname']);
		// verify that the post exists
		$id = $this->app->puller()->pull($params['posttype'], $params['postname']);
		return is_wp_error($id) ? $id : ['id' => $id];
	}

	/**
	 * Pushes a post.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function push(WP_REST_Request $data) {
		$params = $data->get_json_params();
		$params['posttype'] = sanitize_text_field($params['posttype']);
		$params['postname'] = sanitize_text_field($params['postname']);
		$id = $this->app->pusher()->pushByName($params['posttype'], $params['postname']);
		return is_wp_error($id) ? $id : ['id' => $id];
	}

	/**
	 * Deletes a post.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function delete(WP_REST_Request $data) {
		$params = $data->get_json_params();
		$params['posttype'] = sanitize_text_field($params['posttype']);
		$params['postname'] = sanitize_text_field($params['postname']);
		$done = $this->app->deleter()->deleteByName($params['posttype'], $params['postname']);
		return is_wp_error($done) ? $done : ['done' => $done];
	}
}
