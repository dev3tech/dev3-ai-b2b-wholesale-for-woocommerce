<?php
/**
 * Plugin tables.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + dbDelta statements.
 */
class Tables {

	const NAMES = array( 'rules', 'applications', 'quotes', 'quote_items', 'quote_events' );

	/**
	 * Full table name.
	 *
	 * @param string $name One of NAMES.
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3bq_' . $name;
	}

	/**
	 * All plugin tables.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array_map( array( __CLASS__, 'name' ), self::NAMES );
	}

	/**
	 * Adds CREATE TABLE statements (dev3bq_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$rules  = self::name( 'rules' );
		$apps   = self::name( 'applications' );
		$quotes = self::name( 'quotes' );
		$items  = self::name( 'quote_items' );
		$events = self::name( 'quote_events' );

		$sql[] = "CREATE TABLE {$rules} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(120) NOT NULL DEFAULT '',
			role varchar(40) NOT NULL DEFAULT 'all_wholesale',
			scope varchar(12) NOT NULL DEFAULT 'all',
			targets longtext NULL,
			type varchar(10) NOT NULL DEFAULT 'percent',
			amount decimal(19,4) NOT NULL DEFAULT 0,
			breaks longtext NULL,
			priority smallint(5) NOT NULL DEFAULT 10,
			active tinyint(1) NOT NULL DEFAULT 1,
			starts_at datetime NULL,
			ends_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY active_role (active,role)
		) {$charset};";

		$sql[] = "CREATE TABLE {$apps} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			first_name varchar(80) NOT NULL DEFAULT '',
			last_name varchar(80) NOT NULL DEFAULT '',
			company varchar(150) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			tax_id varchar(40) NOT NULL DEFAULT '',
			website varchar(190) NOT NULL DEFAULT '',
			country char(2) NOT NULL DEFAULT '',
			role varchar(40) NOT NULL DEFAULT '',
			message text NULL,
			status varchar(10) NOT NULL DEFAULT 'pending',
			reason varchar(500) NOT NULL DEFAULT '',
			ai longtext NULL,
			decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			decided_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status,created_at),
			KEY email (email),
			KEY user_id (user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$quotes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			number varchar(30) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'requested',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			name varchar(160) NOT NULL DEFAULT '',
			company varchar(150) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			role varchar(40) NOT NULL DEFAULT '',
			request text NULL,
			message text NULL,
			admin_note text NULL,
			currency char(3) NOT NULL DEFAULT '',
			subtotal decimal(19,4) NOT NULL DEFAULT 0,
			discount decimal(19,4) NOT NULL DEFAULT 0,
			shipping decimal(19,4) NOT NULL DEFAULT 0,
			tax decimal(19,4) NOT NULL DEFAULT 0,
			total decimal(19,4) NOT NULL DEFAULT 0,
			tax_mode varchar(8) NOT NULL DEFAULT 'store',
			valid_until date NULL,
			terms text NULL,
			access_key char(32) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(10) NOT NULL DEFAULT 'form',
			followup_sent tinyint(1) NOT NULL DEFAULT 0,
			sent_at datetime NULL,
			decided_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			KEY status (status,updated_at),
			KEY user_id (user_id),
			KEY email (email)
		) {$charset};";

		$sql[] = "CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(255) NOT NULL DEFAULT '',
			sku varchar(100) NOT NULL DEFAULT '',
			qty int(10) unsigned NOT NULL DEFAULT 1,
			regular_price decimal(19,4) NOT NULL DEFAULT 0,
			unit_price decimal(19,4) NOT NULL DEFAULT 0,
			line_total decimal(19,4) NOT NULL DEFAULT 0,
			note varchar(255) NOT NULL DEFAULT '',
			position smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY quote_id (quote_id,position)
		) {$charset};";

		$sql[] = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(12) NOT NULL DEFAULT 'note',
			actor varchar(10) NOT NULL DEFAULT 'system',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY quote_id (quote_id,created_at)
		) {$charset};";

		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall / tests).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( self::all() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
