<?php
/**
 * Provides REST API for React
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

use WP_REST_Request;
use CreativeMoods\PushPull\providers\GitProviderFactory;
use CreativeMoods\PushPull\hooks\Core;
use CreativeMoods\PushPull\hooks\Redirection;
use CreativeMoods\PushPull\hooks\WooCommerce;

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
		register_rest_route('pushpull/v1', '/tables/', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_tables'),
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
		register_rest_route('pushpull/v1', '/deletelocal/', array(
			'methods' => 'POST',
			'callback' => array( $this, 'deletelocal'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/deleteremote/', array(
			'methods' => 'POST',
			'callback' => array( $this, 'deleteremote'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/sync/localcommits', array(
			'methods' => 'GET',
			'callback' => array( $this, 'getlocalcommits'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/sync/remotecommits', array(
			'methods' => 'GET',
			'callback' => array( $this, 'getremotecommits'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/sync/status', array(
			'methods' => 'GET',
			'callback' => array( $this, 'getsyncstatus'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/sync/push', array(
			'methods' => 'POST',
			'callback' => array( $this, 'repopush'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/sync/pull', array(
			'methods' => 'POST',
			'callback' => array( $this, 'repopull'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));

		// Manage deploy items
		register_rest_route('pushpull/v1', '/deploy/', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_deployitems'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/deploy/', array(
			'methods' => 'POST',
			'callback' => array( $this, 'update_deployitem'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/deploy/create', array(
			'methods' => 'POST',
			'callback' => array( $this, 'create_deployitem'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/deploy/', array(
			'methods' => 'DELETE',
			'callback' => array( $this, 'delete_deployitem'),
			'permission_callback' => function () {
				return current_user_can( 'administrator' );
			}
		));
		register_rest_route('pushpull/v1', '/deploy/deploy', array(
			'methods' => 'POST',
			'callback' => array( $this, 'deploy'),
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
		if (strpos($params['post_type'], '@') !== false) {
			// This is a table
			list($plugin, $table) = explode('@', $params['post_type']);
			// Local
			$local = [];
			if (has_filter('pushpull_default_tableimport_'.$plugin.'_'.$table.'_get_by_name')) {
				$row = apply_filters('pushpull_default_tableimport_'.$plugin.'_'.$table.'_get_by_name', $params['post_name']);
				if ($row) {
					$local = $this->app->pusher()->create_tablerow_export($plugin, $table, $row)[1];
				}
			}
			// Remote
			$remote = $this->app->state()->getFile("_".$plugin.'@'.$table."/".str_replace("/", "@@SLASH@@", $params['post_name']));
			if ($remote) {
				$remote = json_decode($remote, true);
				if (!$remote) {
					$remote = [];
				}
			} else {
				$remote = [];
			}
		} else {
			// This is a post type
			// Local
			$post = $this->app->utils()->getLocalPostByName($params['post_type'], $params['post_name']);
			if ($post) {
				$local = $this->app->pusher()->create_post_export($post);
			} else {
				$local = [];
			}
			// Remote
			$remote = $this->app->state()->getFile("_".$params['post_type']."/".$params['post_name']);
			$remote = json_decode($remote, true);
			if (!$remote) {
				$remote = [];
			}
		}

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
	 * Gets a list of all registered tables.
	 *
	 * @return string[] — An array of table names.
	 */
	public function get_tables() {
		$tables = [];

		$core = new Core($this->app);
		$tables['core'] = $core->add_tables();

		foreach (glob(__DIR__ . '/hooks/*.php') as $file) {
			$hook_class = basename($file, '.php');
			$class_name = "CreativeMoods\\PushPull\\hooks\\$hook_class";
			if (class_exists($class_name) && method_exists($class_name, 'add_tables')) {
				$instance = new $class_name($this->app);
				$tables[strtolower($hook_class)] = $instance->add_tables();
			}
		}

		return $tables;
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
		return [
			'provider' => get_option($this->app::PROVIDER_OPTION_KEY, 'github'),
			'posttypes' => get_option($this->app::POST_TYPES_OPTION_KEY, []),
			'tables' => get_option($this->app::TABLES_OPTION_KEY, []),
			'oauth-token' => get_option($this->app::TOKEN_OPTION_KEY, ''),
			'host' => get_option($this->app::HOST_OPTION_KEY, ''),
			'repository' => get_option($this->app::REPO_OPTION_KEY, ''),
			'branch' => get_option($this->app::BRANCH_OPTION_KEY, 'main'),
		];
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
		update_option($this->app::PROVIDER_OPTION_KEY, $params['provider']);

		$params['host'] = sanitize_text_field($params['host']);
		if (!filter_var($params['host'], FILTER_VALIDATE_URL)) {
			return new \WP_Error('invalid_host', __('The provided url is not a valid URL.', 'pushpull'));
		}
		update_option($this->app::HOST_OPTION_KEY, $params['host']);

		$params['repository'] = sanitize_text_field($params['repository']);
		if (!preg_match('/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+$/', $params['repository'])) {
			return new \WP_Error('invalid_repository', __('The provided repository is not valid. It should contain a slash and be properly formatted.', 'pushpull'));
		}
		update_option($this->app::REPO_OPTION_KEY, $params['repository']);

		$params['oauth-token'] = sanitize_text_field($params['oauth-token']);
		update_option($this->app::TOKEN_OPTION_KEY, $params['oauth-token']);

		$params['branch'] = sanitize_text_field($params['branch']);
		update_option($this->app::BRANCH_OPTION_KEY, $params['branch']);

		$params['posttypes'] = array_map('sanitize_text_field', $params['posttypes']);
		update_option($this->app::POST_TYPES_OPTION_KEY, $params['posttypes']);

		$params['tables'] = array_map('sanitize_text_field', $params['tables']);
		update_option($this->app::TABLES_OPTION_KEY, $params['tables']);

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
		return wp_json_encode($this->app->state()->listFiles(), JSON_PRETTY_PRINT);
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
		if (strpos($params['posttype'], '@') !== false) {
			list($plugin, $table) = explode('@', $params['posttype']);
			$id = $this->app->puller()->pull_tablerow($plugin, $table, $params['postname']);
		} else {
			// verify that the post exists
			$id = $this->app->puller()->pull($params['posttype'], $params['postname']);
		}

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
		$posttype = sanitize_text_field($params['posttype']);
		$postname = sanitize_text_field($params['postname']);
		if (strpos($posttype, '@' ) !== false) {
			// table row
			$plugin = explode('@', $posttype)[0];
			$table = explode('@', $posttype)[1];
			$id = $this->app->pusher()->pushTableRow($plugin, $table, $postname);
		} else {
			// post type
			$id = $this->app->pusher()->pushByName($posttype, $postname);
		}

		return is_wp_error($id) ? $id : ['id' => $id];
	}

	/**
	 * Deletes a post locally.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|bool|array
	 */
	public function deletelocal(WP_REST_Request $data) {
		$params = $data->get_json_params();
		$params['posttype'] = sanitize_text_field($params['posttype']);
		$params['postname'] = sanitize_text_field($params['postname']);
		$localpost = $this->app->utils()->getLocalPostByName($params['posttype'], $params['postname']);
		if ($localpost !== null) {
			$this->app->write_log(__( 'Deleting local post.', 'pushpull' ));
			return wp_delete_post($localpost->ID, true);
		}

		return false;
	}

	/**
	 * Deletes a post remotely.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function deleteremote(WP_REST_Request $data) {
		$params = $data->get_json_params();
		$params['posttype'] = sanitize_text_field($params['posttype']);
		$params['postname'] = sanitize_text_field($params['postname']);
		$done = $this->app->state()->deleteFile("_".$params['posttype']."/".$params['postname']);

		return $done ? $done : ['done' => $done];
	}

	/**
	 * Get local commits.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function getlocalcommits(WP_REST_Request $data) {
		// Get local commits
		$localcommits = $this->app->state()->getCommitLog();

		return $localcommits;
	}

	/**
	 * Get remote commits.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function getremotecommits(WP_REST_Request $data) {
		// Get remote commits
		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
		$remotecommits = $gitProvider->getRepositoryCommits();

		return $remotecommits;
	}

	/**
	 * Get sync status.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function getsyncstatus(WP_REST_Request $data) {
		$res = [];
		$res['localLatestCommitHash'] = $this->app->state()->getLatestCommitHash();
		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
		$res['remoteLatestCommitHash'] = $gitProvider->getLatestCommitHash();
		if ($res['localLatestCommitHash'] === null) {
			// Local repository is empty
			$res['status'] = 'localempty';
			return $res;
		}
		if ($res['remoteLatestCommitHash'] === null) {
			// Remote repository is empty
			$res['status'] = 'remoteempty';
			return $res;
		}
		if ($res['localLatestCommitHash'] === $res['remoteLatestCommitHash']) {
			// Both repositories are at the same level
			$res['status'] = 'synced';
			return $res;
		}
		$remotecommits = $gitProvider->getRepositoryCommits();
		$localcommits = $this->app->state()->getCommitLog();
		if ($res['localLatestCommitHash'] !== $res['remoteLatestCommitHash']) {
			$remoteHasLocalLatestCommitHash = $this->app->utils()->findObjectByProperty($remotecommits, 'id', $res, 'localLatestCommitHash');
			$localHasRemoteLatestCommitHash = $this->app->utils()->findObjectByProperty($localcommits, 'id', $res, 'remoteLatestCommitHash');
			// Repositories have diverged
			if ($remoteHasLocalLatestCommitHash && $localHasRemoteLatestCommitHash) {
				$res['status'] = 'conflict';
				return $res;
			}
			// Remote repository is ahead of local
			if ($remoteHasLocalLatestCommitHash) {
				$res['status'] = 'needpull';
				return $res;
			}
			// Local repository is ahead of remote
			if ($localHasRemoteLatestCommitHash) {
				$res['status'] = 'needpush';
				return $res;
			}
			$res['status'] = 'conflict';
			return $res;
		}
		// We shouldn't get to here
		$this->app->write_log("Error: getSyncStatus reached end of function.");
		$res['status'] = 'error';
		return $res;
	}

	/**
	 * Push changes to repo.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function repopush(WP_REST_Request $data) {
		$localLatestCommitHash = $this->app->state()->getLatestCommitHash();
		$provider = get_option($this->app::PROVIDER_OPTION_KEY);
		$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
		$remoteLatestCommitHash = $gitProvider->getLatestCommitHash();

		// Get list of commits from the commit log
		$commits = $this->app->state()->getCommitLog();

		// Get commits from $remoteLatestCommitHash to $localLatestCommitHash
		// TODO this will not work when there's no commit in the remote repository
		$this->app->write_log($remoteLatestCommitHash);
		$this->app->write_log($localLatestCommitHash);
		$commits = $this->app->utils()->getElementsBetweenIds($commits, $remoteLatestCommitHash, $localLatestCommitHash);
		$this->app->write_log($commits);

		$actions = [];
		foreach ($commits as $commit) {
			foreach ($commit['changes'] as $filePath => $hash) {
				$actions[] = [
					'action' => 'tbd',
					'file_path' => $filePath,
					'content' => $this->app->state()->getFile($filePath),
				];
			}
			$user = get_userdata(get_current_user_id());
			$wrap = [
				'branch' => 'tbd', // Will be filled in later in the provider
				'commit_message' => $commit['message'],
				'actions' => $actions,
				'author_email' => $user->user_email,
				'author_name' => $user->display_name,
			];

			$provider = get_option($this->app::PROVIDER_OPTION_KEY);
			$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
			$res = $gitProvider->commit($wrap);
			if (is_wp_error($res)) {
				$this->app->write_log($res);
				return $res;
			}
			$this->app->state()->updateCommitId($commit['id'], $res->id);
		}

		return ['result' => 'success'];
	}

	/**
	 * Pull changes from repo.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_Error|array
	 */
	public function repopull(WP_REST_Request $data) {
		if ($this->app->state()->getLatestCommitHash()) {
			// If we have a local commit hash, we use it to get the missing commits
			$this->app->write_log('Getting diff from latest commit hash: '.$this->app->state()->getLatestCommitHash());
			$provider = get_option($this->app::PROVIDER_OPTION_KEY);
			$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
			$localLatestCommitHash = $this->app->state()->getLatestCommitHash();
			$remoteLatestCommitHash = $gitProvider->getLatestCommitHash();
			$commits = $gitProvider->getRepositoryCommits();
			$commits = $this->app->utils()->getElementsBetweenIds($commits, $localLatestCommitHash, $remoteLatestCommitHash);
			foreach ($commits as $commit) {
				$files = $gitProvider->getCommitFiles($commit->id);
				foreach ($files as $file) {
					list($type, $name) = explode('/', $file);
					$content = $gitProvider->getRemotePostByName(ltrim($type, '_'), $name);
					$this->app->state()->saveFile($file, $content);
				}
				$this->app->write_log($commit);
			}
			$this->app->state()->importcommits($commits, false);
		} else {
			// Otherwise, we need to get the remote hashes and initialize our local state with the latest commit hash from remote
			$this->app->write_log('Getting remote hashes');
			$provider = get_option($this->app::PROVIDER_OPTION_KEY);
			$gitProvider = GitProviderFactory::createProvider($provider, $this->app);
			$remotehashes = $gitProvider->getRemoteHashes();
			if (is_wp_error($remotehashes)) {
				return $remotehashes;
			}
			// Initialize the state but don't create commits
			$repofiles = $gitProvider->initializeRepository();
			// Save state
			$this->app->state()->saveState($repofiles);

			// Get all remote commits and push them onto the local commit log
			$commits = $gitProvider->getRepositoryCommits();
			$this->app->state()->importcommits($commits, true);
		}
		$this->app->state()->saveLatestCommitHash($gitProvider->getLatestCommitHash());

		return ['result' => 'success'];
	}

	// Manage deploy items

	/**
	 * Returns the deploy items.
	 *
	 * @return array — The deploy items.
	 */
	public function get_deployitems() {
		global $wpdb;

		// Define the table name
		$table_name = $wpdb->prefix . $this->app::PP_DEPLOY_TABLE;

		$results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

		return $results;
	}

	/**
	 * Creates a deploy item.
	 *
	 * @return int|false
	 */
	public function create_deployitem(WP_REST_Request $data):int|false {
		global $wpdb;

		$params = $data->get_json_params();
		$params['deployorder'] = sanitize_text_field($params['deployorder']);
		$params['name'] = sanitize_text_field($params['name']);
		$params['type'] = sanitize_text_field($params['type']);
		$params['value'] = sanitize_text_field($params['value']);

		// Define the table name
		$table_name = $wpdb->prefix . $this->app::PP_DEPLOY_TABLE;

		$wpdb->insert(
			$table_name,
			[
				'deployorder' => $params['deployorder'],
				'name' => $params['name'],
				'type' => $params['type'],
				'value' => $params['value'],
			],
		);
		$lastid = $wpdb->insert_id;

		return $lastid;
	}

	/**
	 * Updates a deploy item.
	 *
	 * @return int|false
	 */
	public function update_deployitem(WP_REST_Request $data):int|false {
		global $wpdb;

		$params = $data->get_json_params();
		$params['id'] = sanitize_text_field($params['id']);
		$params['deployorder'] = sanitize_text_field($params['deployorder']);
		$params['name'] = sanitize_text_field($params['name']);
		$params['type'] = sanitize_text_field($params['type']);
		$params['value'] = sanitize_text_field($params['value']);

		// Define the table name
		$table_name = $wpdb->prefix . $this->app::PP_DEPLOY_TABLE;

		return $wpdb->update(
			$table_name,
			[
				'deployorder' => $params['deployorder'],
				'name' => $params['name'],
				'type' => $params['type'],
				'value' => $params['value'],
			],
			['id' => $params['id']]
		);
	}

	/**
	 * Deletes a deploy item.
	 *
	 * @return int|false
	 */
	public function delete_deployitem(WP_REST_Request $data):int|false {
		global $wpdb;

		$params = $data->get_json_params();
		$params['id'] = sanitize_text_field($params['id']);

		// Define the table name
		$table_name = $wpdb->prefix . $this->app::PP_DEPLOY_TABLE;

		return $wpdb->delete(
			$table_name,
			['id' => $params['id']]
		);
	}

	/**
	 * Deploy configuration and contents.
	 *
	 * @param WP_REST_Request $data
	 * @return bool — The result.
	 */
	public function deploy(WP_REST_Request $data) {
		global $wpdb;

		$params = $data->get_json_params();
		$params['id'] = sanitize_text_field($params['id']);

		// Define the table name
		$table_name = $wpdb->prefix . $this->app::PP_DEPLOY_TABLE;

		$query = $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d",
			$params['id']
		);
		/* phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching */
		/* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery */
		$deployitem = $wpdb->get_row($query);
		if ($deployitem->type === 'option_set') {
			return update_option($deployitem->name, $deployitem->value);
		}
	}
}
