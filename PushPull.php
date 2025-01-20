<?php

/*
* Plugin Name:       PushPull
* Plugin URI:        https://creativemoods.pt/pushpull
* Description:       Push Pull DevOps plugin for Wordpress
* Version:           0.1.14
* Requires at least: 6.6
* Requires PHP:      8.0
* Author:            Creative Moods
* Author URI:        https://creativemoods.pt
* License:           GPL v2 or later
* License URI:       https://www.gnu.org/licenses/gpl-2.0.html
*/

namespace CreativeMoods\PushPull;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

use CreativeMoods\PushPull\Puller;
use CreativeMoods\PushPull\Deleter;
use CreativeMoods\PushPull\Rest;
use CreativeMoods\PushPull\Admin;
use CreativeMoods\PushPull\CLI;
use CreativeMoods\PushPull\Utils;
use CreativeMoods\PushPull\Pusher;
use CreativeMoods\PushPull\Repository;
use CreativeMoods\PushPull\WPFileStateManager;
use CreativeMoods\PushPull\hooks\Core;
use WP_CLI;

require __DIR__ . '/vendor/autoload.php';

// Register activation and deactivation hooks
register_activation_hook( __FILE__, array( 'CreativeMoods\\PushPull\\PushPull', 'activate' ) );
register_uninstall_hook( __FILE__, array( 'CreativeMoods\\PushPull\\PushPull', 'uninstall' ) );

add_action( 'plugins_loaded', array( new PushPull, 'boot' ) );

/**
* Class PushPull
*
* Main application class for the plugin. Responsible for bootstrapping
* any hooks and instantiating all service classes.
*/
class PushPull {
	const PROVIDER_OPTION_KEY = 'pushpull_provider';
	const POST_TYPES_OPTION_KEY = 'pushpull_post_types';
	const TABLES_OPTION_KEY = 'pushpull_tables';
	const HOST_OPTION_KEY  = 'pushpull_host';
	const REPO_OPTION_KEY  = 'pushpull_repository';
	const TOKEN_OPTION_KEY = 'pushpull_oauth_token';
	const BRANCH_OPTION_KEY = 'pushpull_branch';
	const PP_PUBLIC_REPO = 'pushpull_public_repo';
	const PP_DEPLOY_TABLE = 'pushpull_deploy';
	const PP_DEPLOY_VERSION_OPTION_KEY = 'pushpull_deploy_version';
	const PP_DEPLOY_VERSION = '1.0';

	/**
	* Object name.
	*
	* @var string
	*/
	public static $name = 'PushPull';
	
	/**
	* Object instance.
	*
	* @var self
	*/
	public static $instance;
	
	/**
	* Current version.
	*
	* @var string
	*/
	public static $version = '0.0.33';
	
	/**
	* Admin object.
	*
	* @var Admin
	*/
	public $admin;

	/**
	 * local git clone
	 *
	 * @var WPFileStateManager
	 */
	protected $state;

	/**
	* CLI object.
	*
	* @var CLI
	*/
	protected $cli;
	
	/**
	* Utils class.
	*
	* @var Utils
	*/
	protected $utils;
	
	/**
	* Pusher.
	*
	* @var Pusher
	*/
	protected $pusher;
	
	/**
	* Repository.
	*
	* @var Repository
	*/
	protected $repository;
	
	/**
	* REST object.
	*
	* @var Rest
	*/
	protected $rest;
	
	/**
	* Puller.
	*
	* @var Puller
	*/
	protected $puller;
	
	/**
	* Deleter.
	*
	* @var Deleter
	*/
	protected $deleter;
	
	/**
	* Deployer.
	*
	* @var Deployer
	*/
	protected $deployer;
	
	/**
	* Export object.
	*
	* @var Export
	*/
	protected $export;
	
	/**
	* Called at load time, hooks into WP core
	*/
	public function __construct() {
		self::$instance = $this;
		
		if ( is_admin() ) {
			$this->admin = new Admin($this);
		}
		
		$this->rest = new Rest($this);
		
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'pushpull', $this->cli() );
		}
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	static public function activate() {
		global $wpdb;

		// Define the table name
		$table_name = $wpdb->prefix . self::PP_DEPLOY_TABLE;

		// Check if the table already exists
        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
			// Include the WordPress file for dbDelta function
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			// Define the SQL for creating the table
			$charset_collate = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE $table_name (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				deployorder INT(11) NOT NULL,
				type VARCHAR(20) NOT NULL,
				name VARCHAR(191) NOT NULL,
				value LONGTEXT NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_name (name),
				INDEX idx_name (name),
				INDEX idx_deployorder (deployorder)
			) $charset_collate;";

// TODO "option_set", "option_add", "option_merge", "custom", "lang_add", "rest_request", "folder_create", "category_create", "pushpull_pull", "pushpull_pullall", "menu_create", "row_insert", "rewrite_rules_flush", "email_send"

			// Execute the query to create the table
			dbDelta($sql);

			add_option( self::PP_DEPLOY_VERSION_OPTION_KEY, self::PP_DEPLOY_VERSION );
		}

/*		$installed_ver = get_option( self::PP_DEPLOY_VERSION_OPTION_KEY );
		if ( $installed_ver != self::PP_DEPLOY_VERSION ) {
			$charset_collate = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE $table_name (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(255) NOT NULL,
				newcol VARCHAR(255) NOT NULL,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY (id)
			) $charset_collate;";
			// Execute the query to create the table
			dbDelta($sql);

			add_option( self::PP_DEPLOY_VERSION_OPTION_KEY, self::PP_DEPLOY_VERSION );
		}*/
	}

	/**
	 * Uninstallation hook.
	 *
	 * @return void
	 */
	static public function uninstall() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::PP_DEPLOY_TABLE;

        /* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange */
		$wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %s", $table_name));
	}

	/**
	* Attaches the plugin's hooks into WordPress.
	*/
	public function boot() {
		//add_action( 'init', array( $this, 'l10n' ) );

		// Pushpull is not needed on the frontend TODO This doesn't work
/*		$this->write_log(is_admin());
		$this->write_log(wp_doing_ajax());
		$this->write_log(wp_doing_cron());
		$this->write_log(defined('REST_REQUEST'));
		if (!is_admin() && !wp_doing_ajax() && !wp_doing_cron() && !defined('REST_REQUEST')) {
			$this->write_log("returning");
			return;
		}*/

		/*add_action('admin_action_pushpull_push', array(&$this, 'push_post'));
		add_action('admin_action_pushpull_pull', array(&$this, 'pull_post'));
		add_filter('post_row_actions', array(&$this, 'dt_duplicate_post_link'), 10, 2);
		add_filter('page_row_actions', array(&$this, 'dt_duplicate_post_link'), 10, 2);*/

		// Wordpress core tables
		$core = new Core($this);
		$core->add_hooks();

		// Register all default hooks for 3rd party plugins
		foreach (glob(__DIR__ . '/hooks/*.php') as $file) {
			$hook_class = basename($file, '.php');
			if ($hook_class == '_PushPull') {
				continue;
			}
			$class_name = "CreativeMoods\\PushPull\\hooks\\$hook_class";
			if (class_exists($class_name)) {
				$instance = new $class_name($this);
				$instance->add_hooks();
			}
		}

		// Manually register myself TODO why doesn't it work in the loop above ?
		$instance = new \CreativeMoods\PushPull\hooks\_PushPull($this);
		$instance->add_hooks();
	}

/*	public function push_post()
	{
		if (!isset($_REQUEST['nonce'])) {
			wp_die(esc_html_e('Security check issue, Please try again.','pushpull'));
		}
		$nonce = sanitize_text_field(wp_unslash($_REQUEST['nonce']));
		
		if (isset($_GET['post'])) {
			$post_id = intval($_GET['post']);
		} elseif (isset($_POST['post'])) {
			$post_id = intval($_POST['post']);
		} else {
			wp_die(esc_html_e('Security check issue, Please try again.','pushpull'));
		}
		if(wp_verify_nonce( $nonce, 'pushpull-'.$post_id)) {
			$post = get_post($post_id);
			if (current_user_can('manage_options') || current_user_can('edit_others_posts')) {
				$this->pusher()->push($post);
				// Redirect
				$returnpage = '';            
				if ($post->post_type != 'post'){
					$returnpage = '?post_type='.$post->post_type;
				}
				wp_redirect(esc_url_raw(admin_url('edit.php'.$returnpage))); 
			}
			else {
				wp_die(esc_html_e('Unauthorized Access.','pushpull'));
			}
		} else {
			wp_die(esc_html_e('Security check issue, Please try again.','pushpull'));
		} 
	}
	
	public function pull_post()
	{
		if (!isset($_REQUEST['nonce'])) {
			wp_die(esc_html_e('Security check issue, Please try again.','pushpull'));
		}
		$nonce = sanitize_text_field(wp_unslash($_REQUEST['nonce']));

		if (isset($_GET['post'])) {
			$post_id = intval($_GET['post']);
		} elseif (isset($_POST['post'])) {
			$post_id = intval($_POST['post']);
		} else {
			wp_die(esc_html_e('Security check issue, Please try again.','pushpull'));
		}
		if(wp_verify_nonce( $nonce, 'pushpull-'.$post_id)) {
			$post = get_post($post_id);
			if (current_user_can('manage_options') || current_user_can('edit_others_posts')) {
				// Redirect
				$returnpage = '';            
				if ($post->post_type != 'post'){
					$returnpage = '?post_type='.$post->post_type;
				}
				wp_redirect(esc_url_raw(admin_url('edit.php'.$returnpage)));
			}
			else {
				wp_die(esc_html_e('Unauthorized Access.','pushpull'));
			}
		} else {
			wp_die(esc_html_e('Security check issue, Please try again.','pushpull'));
		}
	}
	
	public function dt_duplicate_post_link($actions, $post)
	{
		// Skip acf-field-group post type
		if($post->post_type == 'acf-field-group'){
			return $actions;
		}
		
		if (current_user_can('edit_posts')) {
			$actions['push'] = isset($post) ? '<a href="admin.php?action=pushpull_push&amp;post='.intval($post->ID).'&amp;nonce='.wp_create_nonce( 'pushpull-'.intval($post->ID) ).'" title="'.__('Push to Git', 'pushpull').'" rel="permalink">'.__('Push', 'pushpull').'</a>' : '';
			$actions['pull'] = isset($post) ? '<a href="admin.php?action=pushpull_pull&amp;post='.intval($post->ID).'&amp;nonce='.wp_create_nonce( 'pushpull-'.intval($post->ID) ).'" title="'.__('Pull from Git', 'pushpull').'" rel="permalink">'.__('Pull', 'pushpull').'</a>' : '';
		}
		
		return $actions;
	}*/
	
	/**
	* Init i18n files
	*/
	/*public function l10n() {
	load_plugin_textdomain( self::$text_domain, false, plugin_basename( dirname( __FILE__ ) ) . '/languages/' );
	}*/
	
	/**
	* Lazy-load the CLI object.
	*
	* @return CLI
	*/
	public function cli() {
		if ( ! $this->cli ) {
			$this->cli = new CLI;
		}
		
		return $this->cli;
	}
	
	/**
	* Lazy-load utils.
	*
	* @return Utils
	*/
	public function utils() {
		if ( ! $this->utils ) {
			$this->utils = new Utils( $this );
		}
		
		return $this->utils;
	}
	
	/**
	* Lazy-load pusher.
	*
	* @return Pusher
	*/
	public function pusher() {
		if ( ! $this->pusher ) {
			$this->pusher = new Pusher( $this );
		}
		
		return $this->pusher;
	}
	
	/**
	* Lazy-load repository.
	*
	* @return Repository
	*/
	public function repository() {
		if ( ! $this->repository ) {
			$this->repository = new Repository( $this );
		}
		
		return $this->repository;
	}
	
	/**
	* Lazy-load Puller.
	*
	* @return Puller
	*/
	public function puller() {
		if ( ! $this->puller ) {
			$this->puller = new Puller( $this );
		}
		
		return $this->puller;
	}
	
	/**
	* Lazy-load Deleter.
	*
	* @return Deleter
	*/
	public function deleter() {
		if ( ! $this->deleter ) {
			$this->deleter = new Deleter( $this );
		}
		
		return $this->deleter;
	}
	
	/**
	* Lazy-load Deployer.
	*
	* @return Deployer
	*/
	public function deployer() {
		if ( ! $this->deployer ) {
			$this->deployer = new Deployer( $this );
		}

		return $this->deployer;
	}

	/**
	* Lazy-load State.
	*
	* @return WPFileStateManager
	*/
	public function state() {
		if ( ! $this->state ) {
			$this->state = new WPFileStateManager( $this, 'main' );
		}
		
		return $this->state;
	}
	
	/**
	* Print to WP_CLI if in CLI environment or
	* write to debug.log if WP_DEBUG is enabled
	*
	* @source http://www.stumiller.me/sending-output-to-the-wordpress-debug-log/
	*
	* @param mixed  $msg   Message text.
	* @param string $write How to write the message, if CLI.
	*/
	public static function write_log( $msg, $write = 'line' ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			if ( is_array( $msg ) || is_object( $msg ) ) {
				WP_CLI::print_value( $msg );
			} else {
				WP_CLI::$write( $msg );
			}
		} elseif ( true === WP_DEBUG ) {
			if ( is_array( $msg ) || is_object( $msg ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions
				error_log( print_r( $msg, true ) );
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( $msg );
			}
		}
	}
}
