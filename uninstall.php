<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

global $wpdb;
$tables = array(
	'mlt_post_to_post',
	'mlt_media_to_media',
	'mlt_term_to_term',
);

foreach ( get_sites() as $site ) {
	delete_network_option( 1, "mlt_lang_{$site->blog_id}" );
}

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}