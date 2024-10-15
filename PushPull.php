<?php

/*
 * Plugin Name:       PushPull
 * Plugin URI:        https://creativemoods.pt/pushpull
 * Description:       Push Pull DevOps plugin for Wordpress
 * Version:           0.0.37
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

use CreativeMoods\PushPull\Api;
use CreativeMoods\PushPull\Import;
use CreativeMoods\PushPull\Rest;
use CreativeMoods\PushPull\Admin;
use CreativeMoods\PushPull\CLI;
use CreativeMoods\PushPull\FetchClient;
use CreativeMoods\PushPull\PersistClient;

require __DIR__ . '/vendor/autoload.php';

add_action( 'plugins_loaded', array( new PushPull, 'boot' ) );

/**
 * Class PushPull
 *
 * Main application class for the plugin. Responsible for bootstrapping
 * any hooks and instantiating all service classes.
 */
class PushPull {
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
	 * GitHub fetch client.
	 *
	 * @var FetchClient
	 */
	protected $fetch;

	/**
	 * Github persist client.
	 *
	 * @var PersistClient
	 */
	protected $persist;

	/**
	 * REST object.
	 *
	 * @var Rest
	 */
	protected $rest;

	/**
	 * Import object.
	 *
	 * @var Import
	 */
	protected $import;

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
			\WP_CLI::add_command( 'pushpull', $this->cli() );
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
		$this->persist()->commit($post);
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
	 * Lazy-load fetch client.
	 *
	 * @return FetchClient
	 */
	public function fetch() {
		if ( ! $this->fetch ) {
			$this->fetch = new FetchClient( $this );
		}

		return $this->fetch;
	}

	/**
	 * Lazy-load persist client.
	 *
	 * @return PersistClient
	 */
	public function persist() {
		if ( ! $this->persist ) {
			$this->persist = new PersistClient( $this );
		}

		return $this->persist;
	}

	/**
	 * Lazy-load the Import object.
	 *
	 * @return Import
	 */
	public function import() {
		if ( ! $this->import ) {
			$this->import = new Import( $this );
		}

		return $this->import;
	}

	/**
	 * Lazy-load the Export object.
	 *
	 * @return Export
	 */
	public function export() {
		if ( ! $this->export ) {
			$this->export = new Export( $this );
		}

		return $this->export;
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
				\WP_CLI::print_value( $msg );
			} else {
				\WP_CLI::$write( $msg );
			}
		} elseif ( true === WP_DEBUG ) {
			if ( is_array( $msg ) || is_object( $msg ) ) {
				error_log( print_r( $msg, true ) );
			} else {
				error_log( $msg );
			}
		}
	}
}
