<?php
/**
 * Provides REST API for React
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

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
			'callback' => array( $this, 'get_option'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/settings/', array(
			'methods' => 'POST',
			'callback' => array( $this, 'set_option'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/posttypes/', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_posttypes'),
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
	}

	// TODO transformer en json
	public function get_diff($data) {
		$params = $data->get_query_params();
		// Local
		$post = $this->app->import()->get_post_by_name($params['post_name'], $params['post_type']);
		$local = $this->app->persist()->create_post_export($post);
		// Remote
		$remote = $this->app->fetch()->getPostByName($post->post_type, $params['post_name']);
		return ['local' => wp_json_encode($local, JSON_PRETTY_PRINT), 'remote' => wp_json_encode($remote, JSON_PRETTY_PRINT)];
	}

	public function get_posts($data) {
		$params = $data->get_query_params();
		$posts = get_posts(['numberposts' => -1, 'post_type' => 'any', 'post_type' => $params['post_type']]);
		$res = [];
		foreach ($posts as $post) {
			$res[$post->post_name] = $post->post_title;
		}
		return $res;
	}

	public function get_posttypes() {
		return get_post_types();
	}

	public function get_option($data) {
		return ['oauth-token' => get_option('pushpull_oauth_token'), 'host' => get_option('pushpull_host'), 'repository' => get_option('pushpull_repository')];
	}

	public function set_option($data) {
		$params = $data->get_json_params();
		// TODO Verify data
		update_option('pushpull_host', $params['host']);
		update_option('pushpull_repository', $params['repository']);
		update_option('pushpull_oauth_token', $params['oauth-token']);
		return ['oauth-token' => get_option('pushpull_oauth_token'), 'host' => get_option('pushpull_host'), 'repository' => get_option('pushpull_repository')];
	}

	public function get_local_repo() {
		return wp_json_encode($this->app->persist()->local_tree(), JSON_PRETTY_PRINT);
	}

	public function get_remote_repo() {
		return wp_json_encode($this->app->fetch()->remote_tree(), JSON_PRETTY_PRINT);
	}

	public function pull($data) {
		$params = $data->get_json_params();
		// TODO Verify data
		$id = $this->app->import()->import_post($params['posttype'], $params['postname']);
		return is_wp_error($id) ? $id : ['id' => $id];
	}

	public function push($data) {
		$params = $data->get_json_params();
		// TODO Verify data
		$id = $this->app->persist()->push_post($params['posttype'], $params['postname']);
		return is_wp_error($id) ? $id : ['id' => $id];
	}
}
