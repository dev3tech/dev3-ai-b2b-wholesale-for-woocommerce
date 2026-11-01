<?php
/**
 * Pricing rules repository (table dev3bq_rules).
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Pricing;

use Dev3\AiB2bWholesale\Data\Tables;
use Dev3\AiB2bWholesale\Wholesale\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Rules.
 */
class Rules {

	const SCOPES = array( 'all', 'categories', 'products' );
	const TYPES  = array( 'percent', 'fixed', 'price' );

	/**
	 * Active rules memo.
	 *
	 * @var array|null
	 */
	private static $active = null;

	/**
	 * Clears the memo.
	 */
	public static function flush() {
		self::$active = null;
	}

	/**
	 * Every rule (admin list).
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = Tables::name( 'rules' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY priority DESC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Active rules valid now (memoized per request).
	 *
	 * @return array
	 */
	public static function active() {
		if ( null === self::$active ) {
			$now          = current_time( 'mysql', true );
			self::$active = array_values(
				array_filter(
					self::all(),
					static function ( $r ) use ( $now ) {
						return $r['active'] && ( ! $r['starts_at'] || $r['starts_at'] <= $now ) && ( ! $r['ends_at'] || $r['ends_at'] >= $now );
					}
				)
			);
		}
		return self::$active;
	}

	/**
	 * One rule.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'rules' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * DB row → array.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function hydrate( array $row ) {
		$targets = json_decode( (string) $row['targets'], true );
		$breaks  = json_decode( (string) $row['breaks'], true );
		return array(
			'id'        => (int) $row['id'],
			'name'      => (string) $row['name'],
			'role'      => (string) $row['role'],
			'scope'     => (string) $row['scope'],
			'targets'   => is_array( $targets ) ? array_map( 'intval', $targets ) : array(),
			'type'      => (string) $row['type'],
			'amount'    => (float) $row['amount'],
			'breaks'    => is_array( $breaks ) ? $breaks : array(),
			'priority'  => (int) $row['priority'],
			'active'    => (bool) $row['active'],
			'starts_at' => $row['starts_at'] ? (string) $row['starts_at'] : '',
			'ends_at'   => $row['ends_at'] ? (string) $row['ends_at'] : '',
		);
	}

	/**
	 * Cleans quantity breaks: [{min, type, amount}] sorted by min, duplicates removed.
	 *
	 * @param mixed $breaks Raw.
	 * @return array
	 */
	public static function clean_breaks( $breaks ) {
		$out = array();
		foreach ( (array) $breaks as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			$min  = (int) ( isset( $b['min'] ) ? $b['min'] : 0 );
			$type = isset( $b['type'] ) && in_array( $b['type'], self::TYPES, true ) ? $b['type'] : 'percent';
			$amt  = max( 0, round( (float) ( isset( $b['amount'] ) ? $b['amount'] : 0 ), 4 ) );
			if ( $min < 2 ) {
				continue;
			}
			if ( 'percent' === $type ) {
				$amt = min( 100, $amt );
			}
			$out[ $min ] = array(
				'min'    => $min,
				'type'   => $type,
				'amount' => $amt,
			);
		}
		ksort( $out );
		return array_values( array_slice( $out, 0, 12 ) );
	}

	/**
	 * Validates + saves a rule.
	 *
	 * @param array $input Input.
	 * @param int   $id    Id (0 = new).
	 * @return array|\WP_Error Saved rule.
	 */
	public static function save( array $input, $id = 0 ) {
		global $wpdb;
		$role = sanitize_key( isset( $input['role'] ) ? (string) $input['role'] : 'all_wholesale' );
		if ( ! in_array( $role, array( 'everyone', 'all_wholesale' ), true ) && ! Roles::get( $role ) ) {
			return new \WP_Error( 'dev3bq_bad_rule', __( 'Pick who the rule is for.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$scope   = isset( $input['scope'] ) && in_array( $input['scope'], self::SCOPES, true ) ? $input['scope'] : 'all';
		$type    = isset( $input['type'] ) && in_array( $input['type'], self::TYPES, true ) ? $input['type'] : 'percent';
		$amount  = max( 0, round( (float) ( isset( $input['amount'] ) ? $input['amount'] : 0 ), 4 ) );
		$targets = array_values( array_unique( array_filter( array_map( 'absint', isset( $input['targets'] ) ? (array) $input['targets'] : array() ) ) ) );
		if ( 'percent' === $type && $amount > 100 ) {
			return new \WP_Error( 'dev3bq_bad_rule', __( 'A percentage discount cannot be more than 100.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( 'all' !== $scope && ! $targets ) {
			return new \WP_Error( 'dev3bq_bad_rule', __( 'Choose at least one product or category.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$name  = mb_substr( sanitize_text_field( isset( $input['name'] ) ? (string) $input['name'] : '' ), 0, 120 );
		$data  = array(
			'name'       => '' !== $name ? $name : __( 'Untitled rule', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'role'       => $role,
			'scope'      => $scope,
			'targets'    => wp_json_encode( 'all' === $scope ? array() : array_slice( $targets, 0, 500 ) ),
			'type'       => $type,
			'amount'     => $amount,
			'breaks'     => wp_json_encode( self::clean_breaks( isset( $input['breaks'] ) ? $input['breaks'] : array() ) ),
			'priority'   => min( 999, max( 0, (int) ( isset( $input['priority'] ) ? $input['priority'] : 10 ) ) ),
			'active'     => ! isset( $input['active'] ) || ! empty( $input['active'] ) ? 1 : 0,
			'starts_at'  => self::date( isset( $input['starts_at'] ) ? $input['starts_at'] : '' ),
			'ends_at'    => self::date( isset( $input['ends_at'] ) ? $input['ends_at'] : '' ),
			'updated_at' => current_time( 'mysql', true ),
		);
		$table = Tables::name( 'rules' );
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$data['created_at'] = $data['updated_at'];
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = (int) $wpdb->insert_id;
		}
		self::flush();
		Engine::flush();
		return self::get( $id );
	}

	/**
	 * Deletes a rule.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$ok = (bool) $wpdb->delete( Tables::name( 'rules' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush();
		Engine::flush();
		return $ok;
	}

	/**
	 * Y-m-d (H:i) → MySQL UTC datetime or null.
	 *
	 * @param string $value Value.
	 * @return string|null
	 */
	private static function date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	/**
	 * Whether a rule applies to a role.
	 *
	 * @param array  $rule Rule.
	 * @param string $role Role key ('' = retail/guest).
	 * @return bool
	 */
	public static function for_role( array $rule, $role ) {
		if ( 'everyone' === $rule['role'] ) {
			return true;
		}
		if ( 'all_wholesale' === $rule['role'] ) {
			return '' !== $role;
		}
		return $rule['role'] === $role;
	}

	/**
	 * Whether a rule covers a product (variations match on parent id + categories).
	 *
	 * @param array       $rule    Rule.
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public static function covers( array $rule, $product ) {
		if ( 'all' === $rule['scope'] ) {
			return true;
		}
		$ids = array( $product->get_id() );
		if ( $product->get_parent_id() ) {
			$ids[] = $product->get_parent_id();
		}
		if ( 'products' === $rule['scope'] ) {
			return (bool) array_intersect( $ids, $rule['targets'] );
		}
		$cat_source = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		$cats       = $cat_source ? $cat_source->get_category_ids() : array();
		// Child categories count as their parents.
		$all = $cats;
		foreach ( $cats as $cat ) {
			$all = array_merge( $all, get_ancestors( (int) $cat, 'product_cat', 'taxonomy' ) );
		}
		return (bool) array_intersect( array_map( 'intval', $all ), $rule['targets'] );
	}
}
