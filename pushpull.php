<?php

/*
 * Plugin Name:       PushPull
 * Plugin URI:        https://creativemoods.pt/
 * Description:       Push Pull DevOps plugin for Wordpress
 * Version:           0.0.33
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            Creative Moods
 * Author URI:        https://creativemoods.pt
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://creativemoods.pt/
 */

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

if (is_admin()) {
  // we are in admin mode
  require_once __DIR__ . '/lib/admin.php';
}

require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/import.php';
require_once __DIR__ . '/lib/rest.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/lib/cli.php';
}

//require_once __DIR__ . '/pushpull-api.php';

add_action( 'plugins_loaded', array( new PushPull, 'boot' ) );

/**
 * Class PushPull
 *
 * Main application class for the plugin. Responsible for bootstrapping
 * any hooks and instantiating all service classes.
 */
class PushPull {
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
	public static $version = '0.0.1';

	/**
	 * Controller object.
	 *
	 * @var WordPress_GitHub_Sync_Controller
	 */
	public $controller;

	/**
	 * Admin object.
	 *
	 * @var WordPress_GitHub_Sync_Admin
	 */
	public $admin;

	/**
	 * CLI object.
	 *
	 * @var WordPress_GitHub_Sync_CLI
	 */
	protected $cli;

	/**
	 * Request object.
	 *
	 * @var WordPress_GitHub_Sync_Request
	 */
	protected $request;

	/**
	 * Response object.
	 *
	 * @var WordPress_GitHub_Sync_Response
	 */
	protected $response;

	/**
	 * Api object.
	 *
	 * @var WordPress_GitHub_Sync_Api
	 */
	protected $api;

	/**
	 * REST object.
	 *
	 * @var PushPull_Rest
	 */
	protected $rest;

	/**
	 * Import object.
	 *
	 * @var WordPress_GitHub_Sync_Import
	 */
	protected $import;

	/**
	 * Persist object.
	 *
	 * @var WordPress_GitHub_Sync_Persist
	 */
	protected $persist;

	/**
	 * Export object.
	 *
	 * @var WordPress_GitHub_Sync_Export
	 */
	protected $export;

	/**
	 * Semaphore object.
	 *
	 * @var WordPress_GitHub_Sync_Semaphore
	 */
	protected $semaphore;

	/**
	 * Database object.
	 *
	 * @var WordPress_GitHub_Sync_Database
	 */
	protected $database;

	/**
	 * Called at load time, hooks into WP core
	 */
	public function __construct() {
		self::$instance = $this;

		if ( is_admin() ) {
			$this->admin = new PushPull_Admin($this);
		}

//		$this->controller = new WordPress_GitHub_Sync_Controller( $this );
		$this->rest = new PushPull_Rest($this);

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'pushpull', $this->cli() );
		}
	}

	/**
	 * Attaches the plugin's hooks into WordPress.
	 */
	public function boot() {
		//register_activation_hook( __FILE__, array( $this, 'activate' ) );
		//add_action( 'admin_notices', array( $this, 'activation_notice' ) );

		//add_action( 'init', array( $this, 'l10n' ) );

		// Controller actions.
                add_action('admin_action_pushpull_push', array(&$this, 'push_post'));
                add_action('admin_action_pushpull_pull', array(&$this, 'pull_post'));
		add_filter('post_row_actions', array(&$this, 'dt_duplicate_post_link'), 10, 2);
		add_filter('page_row_actions', array(&$this, 'dt_duplicate_post_link'), 10, 2);
/*		add_action( 'save_post', array( $this->controller, 'export_post' ) );
		add_action( 'delete_post', array( $this->controller, 'delete_post' ) );
		add_action( 'wp_ajax_nopriv_wpghs_sync_request', array( $this->controller, 'pull_posts' ) );
		add_action( 'wpghs_export', array( $this->controller, 'export_all' ) );
		add_action( 'wpghs_import', array( $this->controller, 'import_master' ) );

		add_shortcode( 'wpghs', 'write_wpghs_link' );

		do_action( 'wpghs_boot', $this );*/
	}

        public function push_post()
        {
           /*
           * get Nonce value
           */
           $nonce = sanitize_text_field($_REQUEST['nonce']);
            /*
            * get the original post id
            */
           
           $post_id = (isset($_GET['post']) ? intval($_GET['post']) : intval($_POST['post']));
           $post = get_post($post_id);
           $current_user_id = get_current_user_id();
           if(wp_verify_nonce( $nonce, 'pushpull-'.$post_id)) {
            if (current_user_can('manage_options') || current_user_can('edit_others_posts')) {
		$this->api()->persist()->commit($post);
		// Redirect
                $returnpage = '';            
                if ($post->post_type != 'post'){
                    $returnpage = '?post_type='.$post->post_type;
                }
                wp_redirect(esc_url_raw(admin_url('edit.php'.$returnpage))); 
            }
            else {
                wp_die(__('Unauthorized Access.','pushpull'));
		}
          } else {
            wp_die(__('Security check issue, Please try again.','pushpull'));
          } 
        }

        public function pull_post()
        {
           /*
           * get Nonce value
           */
           $nonce = sanitize_text_field($_REQUEST['nonce']);
            /*
            * get the original post id
            */
           
           $post_id = (isset($_GET['post']) ? intval($_GET['post']) : intval($_POST['post']));
           $post = get_post($post_id);
           $current_user_id = get_current_user_id();
           if(wp_verify_nonce( $nonce, 'pushpull-'.$post_id)) {
            if (current_user_can('manage_options') || current_user_can('edit_others_posts')) {
		// Redirect
                $returnpage = '';            
                if ($post->post_type != 'post'){
                    $returnpage = '?post_type='.$post->post_type;
                }
                wp_redirect(esc_url_raw(admin_url('edit.php'.$returnpage))); 
            }
            else {
                wp_die(__('Unauthorized Access.','pushpull'));
		}
          } else {
            wp_die(__('Security check issue, Please try again.','pushpull'));
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
	 * Sets and kicks off the export cronjob
	 */
	public function start_export() {
		$this->export()->set_user( get_current_user_id() );
		$this->start_cron( 'export' );
	}

	/**
	 * Sets and kicks off the import cronjob
	 */
	public function start_import() {
		//$this->start_cron( 'import' );
	}

	/**
	 * Enables the admin notice on initial activation
	 */
	public function activate() {
		if ( 'yes' !== get_option( '_wpghs_fully_exported' ) ) {
			set_transient( '_wpghs_activated', 'yes' );
		}
	}

	/**
	 * Displays the activation admin notice
	 */
	public function activation_notice() {
		if ( ! get_transient( '_wpghs_activated' ) ) {
			return;
		}

		delete_transient( '_wpghs_activated' );

		?><div class="updated">
			<p>
				<?php
					printf(
						__( 'To set up your site to sync with GitHub, update your <a href="%s">settings</a> and click "Export to GitHub."', 'wp-github-sync' ),
						admin_url( 'options-general.php?page=' . static::$text_domain)
					);
				?>
			</p>
		</div><?php
	}

	/**
	 * Get the Controller object.
	 *
	 * @return WordPress_GitHub_Sync_Controller
	 */
	public function controller() {
		return $this->controller;
	}

	/**
	 * Lazy-load the CLI object.
	 *
	 * @return WordPress_GitHub_Sync_CLI
	 */
	public function cli() {
		if ( ! $this->cli ) {
			$this->cli = new PushPull_CLI;
		}

		return $this->cli;
	}

	/**
	 * Lazy-load the Request object.
	 *
	 * @return WordPress_GitHub_Sync_Request
	 */
	public function request() {
		if ( ! $this->request ) {
			$this->request = new WordPress_GitHub_Sync_Request( $this );
		}

		return $this->request;
	}

	/**
	 * Lazy-load the Response object.
	 *
	 * @return WordPress_GitHub_Sync_Response
	 */
	public function response() {
		if ( ! $this->response ) {
			$this->response = new WordPress_GitHub_Sync_Response( $this );
		}

		return $this->response;
	}

	/**
	 * Lazy-load the Api object.
	 *
	 * @return WordPress_GitHub_Sync_Api
	 */
	public function api() {
		if ( ! $this->api ) {
			$this->api = new PushPull_Api( $this );
		}

		return $this->api;
	}

	/**
	 * Lazy-load persist client.
	 *
	 * @return PushPull_Persist_Client
	 */
	public function persist() {
		if ( ! $this->persist ) {
			$this->persist = new PushPull_Persist_Client( $this );
		}

		return $this->persist;
	}

	/**
	 * Lazy-load the Import object.
	 *
	 * @return WordPress_GitHub_Sync_Import
	 */
	public function import() {
		if ( ! $this->import ) {
			$this->import = new PushPull_Import( $this );
		}

		return $this->import;
	}

	/**
	 * Lazy-load the Export object.
	 *
	 * @return WordPress_GitHub_Sync_Export
	 */
	public function export() {
		if ( ! $this->export ) {
			$this->export = new WordPress_GitHub_Sync_Export( $this );
		}

		return $this->export;
	}

	/**
	 * Lazy-load the Semaphore object.
	 *
	 * @return WordPress_GitHub_Sync_Semaphore
	 */
	public function semaphore() {
		if ( ! $this->semaphore ) {
			$this->semaphore = new WordPress_GitHub_Sync_Semaphore;
		}

		return $this->semaphore;
	}

	/**
	 * Lazy-load the Database object.
	 *
	 * @return WordPress_GitHub_Sync_Database
	 */
	public function database() {
		if ( ! $this->database ) {
			$this->database = new WordPress_GitHub_Sync_Database( $this );
		}

		return $this->database;
	}

	/**
	 * Lazy-load the Cache object.
	 *
	 * @return WordPress_GitHub_Sync_Cache
	 */
	public function cache() {
		if ( ! $this->cache ) {
			$this->cache = new WordPress_GitHub_Sync_Cache;
		}

		return $this->cache;
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
				error_log( print_r( $msg, true ) );
			} else {
				error_log( $msg );
			}
		}
	}

	/**
	 * Kicks of an import or export cronjob.
	 *
	 * @param string $type Cron to kick off.
	 */
	protected function start_cron( $type ) {
		//update_option( '_wpghs_' . $type . '_started', 'yes' );
		//wp_schedule_single_event( time(), 'wpghs_' . $type . '' );
		//spawn_cron();
	}
}
