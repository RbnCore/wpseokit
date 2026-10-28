<?php
/**
 * Runs when the plugin is deleted. Settings, tables and meta are removed only if "delete data on uninstall" is on.
 *
 * @package Wpseokit
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Update helpers: temporary records, removed regardless of the data-removal choice.
delete_transient( 'wpseokit_update_release' );
delete_option( 'wpseokit_update_checked' );
delete_option( 'wpseokit_update_off' );

$wpseokit_settings = get_option( 'wpseokit_settings', array() );
if ( is_array( $wpseokit_settings ) && ! empty( $wpseokit_settings['general']['delete_on_uninstall'] ) ) {
	global $wpdb;
	require_once __DIR__ . '/includes/Db.php';
	$wpseokit_tables = array( \Wpseokit\Db::table( 'redirects' ), \Wpseokit\Db::table( '404_logs' ) );
	foreach ( $wpseokit_tables as $wpseokit_table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpseokit_table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table name is comes from Db::table().
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_wpseokit_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'wpseokit_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( array( 'wpseokit_settings', 'wpseokit_db_version', 'wpseokit_redirect_map', 'wpseokit_import_state', 'wpseokit_ip_salt', 'wpseokit_score_site', 'wpseokit_score_dismissed' ) as $wpseokit_option ) {
		delete_option( $wpseokit_option );
	}
}
