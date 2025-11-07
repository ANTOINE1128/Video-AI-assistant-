<?php
/**
 * Uninstall cleanup for Farhat Video Q&A.
 *
 * Drops custom tables and deletes options if the admin opted-in.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$delete_all = (bool) get_option( 'fvqa_delete_data_on_uninstall', false );

if ( $delete_all ) {
	global $wpdb;

	// Drop logs table
	$table = $wpdb->prefix . 'fvqa_logs';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// Remove our options
	delete_option( 'fvqa_delete_data_on_uninstall' );

	// If you add more options later, delete them here:
	// delete_option( 'fvqa_some_other_option' );
}
