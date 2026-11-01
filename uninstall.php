<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiB2bWholesale
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3bq_settings = get_option( 'dev3bq_settings', array() );
if ( empty( $dev3bq_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3bq_usage', 'dev3bq_core_cache', 'dev3bq_core_jobs', 'dev3bq_core_embeddings', 'dev3bq_rules', 'dev3bq_applications', 'dev3bq_quotes', 'dev3bq_quote_items', 'dev3bq_quote_events' ) as $dev3bq_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3bq_table}" );
}
$dev3bq_roles = get_option( 'dev3bq_roles', array() );
foreach ( is_array( $dev3bq_roles ) ? $dev3bq_roles : array() as $dev3bq_role ) {
	if ( is_array( $dev3bq_role ) && ! empty( $dev3bq_role['key'] ) ) {
		remove_role( 'dev3bq_' . sanitize_key( $dev3bq_role['key'] ) );
	}
}
foreach ( array( 'dev3bq_settings', 'dev3bq_roles', 'dev3bq_roles_hash', 'dev3bq_price_version', 'dev3bq_flush_rewrite', 'dev3bq_db_version' ) as $dev3bq_option ) {
	delete_option( $dev3bq_option );
}
delete_transient( 'dev3bq_models' );
wp_unschedule_hook( 'dev3bq_run_job' );
wp_unschedule_hook( 'dev3bq_daily' );
