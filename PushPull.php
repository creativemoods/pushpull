<?php

/*
* Plugin Name:       PushPull
* Plugin URI:        https://creativemoods.pt/pushpull
* Description:       Push Pull DevOps plugin for Wordpress
* Version:           0.0.54
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
use CreativeMoods\PushPull\hooks\GenerateBlocks;
use CreativeMoods\PushPull\hooks\RealMediaLibrary;
use CreativeMoods\PushPull\hooks\Polylang;
use CreativeMoods\PushPull\hooks\PPTest;
use WP_CLI;

require __DIR__ . '/vendor/autoload.php';

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
	const URL_OPTION_KEY  = 'pushpull_host';
	const REPO_OPTION_KEY  = 'pushpull_repository';
	const TOKEN_OPTION_KEY = 'pushpull_oauth_token';
	const BRANCH_OPTION_KEY = 'pushpull_branch';

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
	* Attaches the plugin's hooks into WordPress.
	*/
	public function boot() {
		//add_action( 'init', array( $this, 'l10n' ) );

		add_action('admin_action_pushpull_push', array(&$this, 'push_post'));
		add_action('admin_action_pushpull_pull', array(&$this, 'pull_post'));
		add_filter('post_row_actions', array(&$this, 'dt_duplicate_post_link'), 10, 2);
		add_filter('page_row_actions', array(&$this, 'dt_duplicate_post_link'), 10, 2);

		// Register all default hooks for 3rd party plugins
		// TODO Il faudrait trouver un système pour pas loader ce qui est pas nécessaire
		$gb = new GenerateBlocks($this);
		$gb->add_hooks();
		$rml = new RealMediaLibrary($this);
		$rml->add_hooks();
		$pll = new Polylang($this);
		$pll->add_hooks();
		$ppt = new PPTest($this);
		$ppt->add_hooks();
	}

	public function push_post()
	{
		/*
		* get Nonce value
		*/
		if (!isset($_REQUEST['nonce'])) {
			wp_die(esc_html_e('Security check issue, Please try again.','pushpull'));
		}
		$nonce = sanitize_text_field(wp_unslash($_REQUEST['nonce']));
		
		/*
		* get the original post id
		*/
		
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
		/*
		* get Nonce value
		*/
		if (!isset($_REQUEST['nonce'])) {
			wp_die(esc_html_e('Security check issue, Please try again.','pushpull'));
		}
		$nonce = sanitize_text_field(wp_unslash($_REQUEST['nonce']));
		/*
		* get the original post id
		*/

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
	
	/*
	* Add the duplicate link to action list for post_row_actions
	*/
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
	}
	
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
