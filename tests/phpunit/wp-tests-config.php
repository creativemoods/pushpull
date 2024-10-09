<?php

// change the next line to points to your wordpress dir
define( 'ABSPATH', '/home/jerome/dev/CreativeMoods/bedrock/empty/web/app/plugins/pushpull/tests/wp/' );

define( 'WP_DEBUG', false );

// WARNING WARNING WARNING!
// tests DROPS ALL TABLES in the database. DO NOT use a production database

define( 'DB_NAME', 'test' );
define( 'DB_USER', 'test' );
define( 'DB_PASSWORD', 'test' );
define( 'DB_HOST', 'localhost.localdomain' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // Only numbers, letters, and underscores please!

define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WPLANG', '' );
