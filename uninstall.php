<?php

// if uninstall.php is not called by WordPress, die
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

//$option_name = 'wporg_option';

delete_option('pushpull_provider');
delete_option('pushpull_host');
delete_option('pushpull_oauth_token');
delete_option('pushpull_repository');
delete_option('pushpull_post_types');

// for site options in Multisite
//delete_site_option( $option_name );

// drop a custom database table
//global $wpdb;
//$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mytable" );
