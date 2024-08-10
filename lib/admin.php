<?php
/**
 * Admin UI
 */

/**
 * Class PushPull_Admin
 */
class PushPull_Admin {
	public function __construct() {
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

		register_setting( 'pushpull', 'pushpull_host' );
		add_settings_field( 'pushpull_host', __( 'Git API', 'wp-github-sync' ), array( $this, 'field_callback' ), 'pushpull', 'general', array(
				'default'   => 'https://api.github.com',
				'name'      => 'pushpull_host',
				'help_text' => __( 'The Git API URL.', 'pushpull' ),
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
		add_settings_field( 'pushpull_oauth_token', __( 'Oauth Token', 'pushpull' ), array( $this, 'field_callback' ), 'pushpull', 'general', array(
				'default'   => '',
				'name'      => 'pushpull_oauth_token',
				'help_text' => __( "A <a href='https://github.com/settings/tokens/new'>personal oauth token</a> with <code>public_repo</code> scope.", 'wp-github-sync' ),
			)
		);

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
	 * Add options menu to admin navbar
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'PushPull settings', 'pushpull' ),
			__( 'PushPull', 'pushpull' ),
			'manage_options',
			'pushpull',
			array( $this, 'settings_page' )
		);
	}
}
