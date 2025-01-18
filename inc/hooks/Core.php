<?php
/**
 * GenerateBlocks class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull\hooks;

use CreativeMoods\PushPull\PushPull;

// TODO Create Interface for Hook classes

/**
 * Class Core
 */
class Core {
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
		// TODO check number of arguments in all other hooks
		add_filter('pushpull_default_tableexport_core_users', array(&$this, 'tableexport_core_users'), 10, 1);
		add_filter('pushpull_default_tableexport_core_comments', array(&$this, 'tableexport_core_comments'), 10, 1);
		add_filter('pushpull_default_tableimport_core_users_get_by_name', array(&$this, 'get_core_users_by_name'), 10, 1);
		add_filter('pushpull_default_tableimport_core_comments_get_by_name', array(&$this, 'get_core_comments_by_name'), 10, 1);
		add_filter('pushpull_default_tableimport_core_users_filter', array(&$this, 'tableimport_core_users_filter'), 10, 1);
		add_action('pushpull_default_tableimport_core_users_action', array(&$this, 'tableimport_core_users_action'), 10, 3);
		add_filter('pushpull_default_tableimport_core_comments_filter', array(&$this, 'tableimport_core_comments_filter'), 10, 1);
		add_action('pushpull_default_tableimport_core_comments_action', array(&$this, 'tableimport_core_comments_action'), 10, 3);
	}

	/**
	 * Add all tables managed by this plugin that contain data that should be stored in the repository.
	 *
	 * @return void
	 */
	public function add_tables() {
		return [
			'users' => 'users',
			'comments' => 'comments',
		];
	}

	/**
     * Manipulate users table data on export
     *
     * @param array $data
     * @return array (first element is the unique name of the row, second element is the data)
	 * 
	 * We remove the ID field from the data
	 * 
	 */
    public function tableexport_core_users(array $data) {
		global $wpdb;
		// Get all usermeta for this user
        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$metadata = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}usermeta WHERE user_id = %d", $data['ID']), ARRAY_A);
		foreach ($metadata as $i => $meta) {
			$data['metadata'][$meta['meta_key']] = $meta['meta_value'];
		}

		return [$data['user_login'], $this->app->utils()->array_without_keys($data,
			['ID']
		)];
	}

	/**
     * Manipulate comments table data on export
     *
     * @param array $data
     * @return array (first element is the unique name of the row, second element is the data)
	 * 
	 * We remove the comment_ID field from the data
	 * 
	 */
    public function tableexport_core_comments(array $data) {
		global $wpdb;
		// Get all usermeta for this comment
        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$metadata = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}commentmeta WHERE comment_id = %d", $data['comment_ID']), ARRAY_A);
		foreach ($metadata as $i => $meta) {
			$data['metadata'][$meta['meta_key']] = $meta['meta_value'];
		}

		// Replace user_id with user_login
		if (is_numeric($data['user_id']) && $data['user_id'] > 0) {
	        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
			$user = $wpdb->get_row($wpdb->prepare("SELECT user_login FROM {$wpdb->prefix}users WHERE ID = %d", $data['user_id']));
			if ($user) {
				$data['user_id'] = $user->user_login;
			}
		}
		
		// Replace comment_post_ID with post name
		if (is_numeric($data['comment_post_ID']) && $data['comment_post_ID'] > 0) {
			$post = get_post($data['comment_post_ID']);
			if ($post && $post->post_name !== "") {
				$data['comment_post_ID'] = $post->post_type.'/'.$post->post_name;
			} else {
				return false;
			}
		} else {
			return false;
		}

		// Replace comment_parent with comment name
		if (is_numeric($data['comment_parent']) && $data['comment_parent'] > 0) {
			$comment = get_comment($data['comment_parent']);
			if ($comment) {
				$data['comment_parent'] = $comment['comment_post_ID'].'/'.$comment['comment_author'].'/'.$comment['comment_date'];
			}
		}

		// DO NOT ADD @ in the name of the row
		return [$data['comment_post_ID'].'/'.$data['comment_author'].'/'.$data['comment_date'], $this->app->utils()->array_without_keys($data,
			['comment_ID']
		)];
	}

	/**
	 * Get table row from name for users
	 *
	 * @param string $name
	 * @return array|bool
	 */
	public function get_core_users_by_name(string $name): array|bool {
		global $wpdb;

        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}users WHERE user_login = %s", $name));
		if ($row) {
			return (array) $row;
		}

		return False;
	}

	/**
	 * Get table row from name for comments
	 *
	 * @param string $name
	 * @return array|bool
	 */
	public function get_core_comments_by_name(string $name): array|bool {
		global $wpdb;

		list($post_type, $post_name, $commentauthor, $commentdate) = explode('/', $name);
		$post = $this->app->utils()->getLocalPostByName($post_type, $post_name);
		$author = get_user_by('login', $commentauthor);
        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}comments WHERE comment_post_ID = %d AND user_id = %d AND comment_date = %s", $post->ID, $author->ID, $commentdate));
		if ($row) {
			return (array) $row;
		}

		return False;
	}

	/**
     * Manipulate users table data on import
     *
     * @param array $data
     * @return array
	 * 
	 */
    public function tableimport_core_users_filter(array $data) {
		return $this->app->utils()->array_without_keys($data, ['metadata']);
	}

	/**
     * Manipulate comments table data on import
     *
     * @param array $data
     * @return array
	 * 
	 */
    public function tableimport_core_comments_filter(array $data) {
		// Convert comment_post_ID to post ID
		list($post_type, $post_name) = explode('/', $data['comment_post_ID']);
		$post = $this->app->utils()->getLocalPostByName($post_type, $post_name);
		$data['comment_post_ID'] = $post->ID;
		// Convert comment_parent to comment ID
		if ($data['comment_parent'] !== "0") {
			$comment_parent = explode('/', $data['comment_parent']);
			$comment_parent = $this->app->utils()->getLocalPostByName($comment_parent[0], $comment_parent[1]);
			$data['comment_parent'] = $comment_parent->ID;
		}
		// Convert user_id to user ID
		if (is_string($data['user_id'])) {
			$author = get_user_by('login', $data['user_id']);
			$data['user_id'] = $author->ID;
		}

		return $this->app->utils()->array_without_keys($data, ['metadata']);
	}

	/**
     * Manipulate users table data on import
     *
     * @param int $localid the id of the local row that was created before this action
     * @param array $initialrow the row before passing through our filter
     * @param array $row the row after passing through our filter
     * @return void
     */
    public function tableimport_core_users_action(int $localid, array $initialrow, array $row) {
		global $wpdb; // TODO add to class construct

		foreach ($initialrow['metadata'] as $key => $value) {
			// Insert metadata
	        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery */
			$wpdb->insert($wpdb->prefix . 'usermeta', [
				'user_id' => $localid,
				'meta_key' => $key,
				'meta_value' => $value
			]);
			$wpdb->insert_id;
		}
    }

	/**
     * Manipulate comments table data on import
     *
     * @param int $localid the id of the local row that was created before this action
     * @param array $initialrow the row before passing through our filter
     * @param array $row the row after passing through our filter
     * @return void
     */
    public function tableimport_core_comments_action(int $localid, array $initialrow, array $row) {
		global $wpdb; // TODO add to class construct

		foreach ($initialrow['metadata'] as $key => $value) {
			// Insert metadata
	        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery */
			$wpdb->insert($wpdb->prefix . 'commentmeta', [
				'comment_id' => $localid,
				'meta_key' => $key,
				'meta_value' => $value
			]);
			$wpdb->insert_id;
		}
    }
}
