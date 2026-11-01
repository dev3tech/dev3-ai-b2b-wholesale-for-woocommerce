<?php
/**
 * GDPR exporter / eraser for quotes and wholesale applications.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Data;

use Dev3\AiB2bWholesale\Core3\Core\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data.
 */
class Personal_Data {

	/**
	 * Hooks.
	 */
	public static function register() {
		Privacy::add_exporter( __( 'B2B quotes and wholesale applications', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( __CLASS__, 'export' ) );
		Privacy::add_eraser( __( 'B2B quotes and wholesale applications', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( __CLASS__, 'erase' ) );
	}

	/**
	 * Exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$quotes = Tables::name( 'quotes' );
		$apps   = Tables::name( 'applications' );
		$items  = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT number, name, company, phone, request, created_at FROM {$quotes} WHERE email = %s", $email ), ARRAY_A ) as $q ) {
			$items[] = array(
				'group_id'    => 'dev3bq-quotes',
				'group_label' => __( 'Quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				'item_id'     => 'dev3bq-quote-' . $q['number'],
				'data'        => array(
					array(
						'name'  => __( 'Quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
						'value' => $q['number'],
					),
					array(
						'name'  => __( 'Name', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
						'value' => $q['name'],
					),
					array(
						'name'  => __( 'Company', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
						'value' => $q['company'],
					),
					array(
						'name'  => __( 'Phone', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
						'value' => $q['phone'],
					),
					array(
						'name'  => __( 'Request', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
						'value' => (string) $q['request'],
					),
					array(
						'name'  => __( 'Date', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
						'value' => $q['created_at'],
					),
				),
			);
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, company, phone, tax_id, website, country, message, status, created_at FROM {$apps} WHERE email = %s", $email ), ARRAY_A ) as $a ) {
			$data = array();
			foreach ( $a as $k => $v ) {
				if ( 'id' !== $k ) {
					$data[] = array(
						'name'  => $k,
						'value' => (string) $v,
					);
				}
			}
			$items[] = array(
				'group_id'    => 'dev3bq-applications',
				'group_label' => __( 'Wholesale applications', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				'item_id'     => 'dev3bq-app-' . $a['id'],
				'data'        => $data,
			);
		}
		// phpcs:enable
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Eraser: anonymises contact details (quotes stay for the store's records).
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$anon = 'deleted-' . substr( md5( $email ), 0, 10 ) . '@example.invalid';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$q = (int) $wpdb->update(
			Tables::name( 'quotes' ),
			array(
				'email'   => $anon,
				'name'    => '',
				'phone'   => '',
				'request' => '',
			),
			array( 'email' => $email )
		);
		$a = (int) $wpdb->update(
			Tables::name( 'applications' ),
			array(
				'email'      => $anon,
				'first_name' => '',
				'last_name'  => '',
				'phone'      => '',
				'tax_id'     => '',
				'message'    => '',
			),
			array( 'email' => $email )
		);
		// phpcs:enable
		return array(
			'items_removed'  => ( $q + $a ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
