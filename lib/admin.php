<?php
/**
 * Admin UI
 */

/**
 * Class PushPull_Admin
 */
class PushPull_Admin {
	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	public function __construct(PushPull $app) {
		$this->app = $app;
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	//	add_action( 'current_screen', array( $this, 'trigger_cron' ) );
	}

	/**
	 * Callback to render the settings page view
	 */
	public function settings_page() {
		include dirname( dirname( __FILE__ ) ) . '/views/options.php';
	}

	/**
	 * Callback to register the plugin's options
	 */
	public function register_settings() {
		add_settings_section(
			'general',
			'General Settings',
			array( $this, 'section_callback' ),
			'pushpull',
		);

		register_setting(
			'pushpull',
			'pushpull_host',
			array(
				'type'              => 'string',
				'description'       => __( 'The Git API URL.', 'pushpull' ),
				//'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		add_settings_field( 'pushpull_host', __( 'Git API', 'wp-github-sync' ), array( $this, 'field_callback' ), 'pushpull', 'general', array(
				'default'      => 'https://api.github.com',
				'name'         => 'pushpull_host',
				'help_text'    => __( 'The Git API URL.', 'pushpull' ),
			)
		);

		register_setting( 'pushpull', 'pushpull_repository' );
		add_settings_field( 'pushpull_repository', __( 'Project', 'pushpull' ), array( $this, 'field_callback' ), 'pushpull', 'general', array(
				'default'   => '',
				'name'      => 'pushpull_repository',
				'help_text' => __( 'The Git repository to commit to.', 'pushpull' ),
			)
		);

		register_setting( 'pushpull', 'pushpull_oauth_token' );
		/*add_settings_field( 'pushpull_oauth_token', __( 'Oauth Token', 'pushpull' ), array( $this, 'field_callback' ), 'pushpull', 'general', array(
				'default'   => '',
				'name'      => 'pushpull_oauth_token',
				'help_text' => __( "A <a href='https://github.com/settings/tokens/new'>personal oauth token</a> with <code>public_repo</code> scope.", 'wp-github-sync' ),
			)
		);*/

		register_setting( 'pushpull', 'wpghs_secret' );
		add_settings_field( 'wpghs_secret', __( 'Webhook Secret', 'wp-github-sync' ), array( $this, 'field_callback' ), 'pushpull', 'general', array(
				'default'   => '',
				'name'      => 'wpghs_secret',
				'help_text' => __( "The webhook's secret phrase. This should be password strength, as it is used to verify the webhook's payload.", 'wp-github-sync' ),
			)
		);

		register_setting( 'pushpull', 'wpghs_default_user' );
		add_settings_field( 'wpghs_default_user', __( 'Default Import User', 'wp-github-sync' ), array( &$this, 'user_field_callback' ), 'pushpull', 'general', array(
				'default'   => '',
				'name'      => 'wpghs_default_user',
				'help_text' => __( 'The fallback user for import, in case WordPress <--> GitHub Sync cannot find the committer in the database.', 'wp-github-sync' ),
			)
		);
	}

	/**
	 * Callback to render an individual options field
	 *
	 * @param array $args Field arguments.
	 */
	public function field_callback( $args ) {
		include dirname( dirname( __FILE__ ) ) . '/views/setting-field.php';
	}

	/**
	 * Callback to render the default import user field.
	 *
	 * @param array $args Field arguments.
	 */
	public function user_field_callback( $args ) {
		include dirname( dirname( __FILE__ ) ) . '/views/user-setting-field.php';
	}

	/**
	 * Displays settings messages from background processes
	 */
	public function section_callback() {
		if ( get_current_screen()->id !== 'settings_page_' . 'pushpull' ) {
			return;
		}

		if ( 'yes' === get_option( '_wpghs_export_started' ) ) { ?>
			<div class="updated">
				<p><?php esc_html_e( 'Export to GitHub started.', 'wp-github-sync' ); ?></p>
			</div><?php
			delete_option( '_wpghs_export_started' );
		}

		if ( $message = get_option( '_wpghs_export_error' ) ) { ?>
			<div class="error">
				<p><?php esc_html_e( 'Export to GitHub failed with error:', 'wp-github-sync' ); ?> <?php echo esc_html( $message );?></p>
			</div><?php
			delete_option( '_wpghs_export_error' );
		}

		if ( 'yes' === get_option( '_wpghs_export_complete' ) ) { ?>
			<div class="updated">
				<p><?php esc_html_e( 'Export to GitHub completed successfully.', 'wp-github-sync' );?></p>
			</div><?php
			delete_option( '_wpghs_export_complete' );
		}

		if ( 'yes' === get_option( '_wpghs_import_started' ) ) { ?>
			<div class="updated">
			<p><?php esc_html_e( 'Import from GitHub started.', 'wp-github-sync' ); ?></p>
			</div><?php
			delete_option( '_wpghs_import_started' );
		}

		if ( $message = get_option( '_wpghs_import_error' ) ) { ?>
			<div class="error">
			<p><?php esc_html_e( 'Import from GitHub failed with error:', 'wp-github-sync' ); ?> <?php echo esc_html( $message );?></p>
			</div><?php
			delete_option( '_wpghs_import_error' );
		}

		if ( 'yes' === get_option( '_wpghs_import_complete' ) ) { ?>
			<div class="updated">
			<p><?php esc_html_e( 'Import from GitHub completed successfully.', 'wp-github-sync' );?></p>
			</div><?php
			delete_option( '_wpghs_import_complete' );
		}
	}

	/**
	 * Init admin menu
	 */
	public function pushpull_admin_page() {
		require_once plugin_dir_path( __FILE__ ) . '../templates/app.php';
	}

	/**
	 * Enqueue scripts and style
	 */
	public function pushpull_admin_enqueue_scripts($admin_page) {
		if ( 'toplevel_page_pushpull' !== $admin_page ) {
			return;
		}
		$asset_file = plugin_dir_path( __FILE__ ) . '../build/index.asset.php';
		if (!file_exists($asset_file)) {
			$this->app->write_log("No asset file");
			return;
		}
		$asset = include $asset_file;
		wp_enqueue_style('pushpull-style',
			plugin_dir_url( __FILE__ ) . '../build/index.css',
			array_filter(
				$asset['dependencies'],
				function ($style) {
					return wp_style_is($style, 'registered');
				}
			),
			$asset['version'],
		);
		wp_enqueue_script(
			'pushpull-script',
			plugin_dir_url( __FILE__ ) . '../build/index.js',
			$asset['dependencies'],
			$asset['version'],
			array('in_footer' => true)
		);
	}

	/**
	 * Add options menu to admin navbar
	 */
	public function add_admin_menu() {
		add_menu_page( __( 'PushPull', 'pushpull'), __( 'PushPull', 'pushpull'), 'manage_options', 'pushpull', array( $this, 'pushpull_admin_page' ), 'dashicons-admin-post', '2.1' );
		add_options_page(
			__( 'PushPull settings', 'pushpull' ),
			__( 'PushPull', 'pushpull' ),
			'manage_options',
			'pushpull',
			array( $this, 'settings_page' )
		);
		add_action( 'admin_enqueue_scripts', array( $this, 'pushpull_admin_enqueue_scripts' ) );
	}
}
