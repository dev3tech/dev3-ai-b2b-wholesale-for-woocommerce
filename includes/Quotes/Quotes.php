<?php
/**
 * Quotes repository (quotes, quote_items, quote_events tables).
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Quotes;

use Dev3\AiB2bWholesale\Data\Tables;
use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Settings;
use Dev3\AiB2bWholesale\Wholesale\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Quotes.
 */
class Quotes {

	const STATUSES  = array( 'requested', 'draft', 'sent', 'accepted', 'declined', 'expired', 'cancelled' );
	const OPEN      = array( 'requested', 'draft', 'sent' );
	const SOURCES   = array( 'cart', 'form', 'text', 'admin', 'quick' );
	const TAX_MODES = array( 'store', 'exempt' );

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'requested' => __( 'New request', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'draft'     => __( 'In progress', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'sent'      => __( 'Sent', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'accepted'  => __( 'Accepted', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'declined'  => __( 'Declined', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'expired'   => __( 'Expired', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'cancelled' => __( 'Cancelled', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}

	/**
	 * Creates a quote.
	 *
	 * @param array $data  Fields (user_id, email, name, company, phone, request, source, role, status).
	 * @param array $items Lines [{product_id, variation_id, qty, unit_price?, note}].
	 * @return int|\WP_Error Quote id.
	 */
	public static function create( array $data, array $items = array() ) {
		global $wpdb;
		$now     = current_time( 'mysql', true );
		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		$email   = sanitize_email( isset( $data['email'] ) ? (string) $data['email'] : '' );
		if ( ! $email && $user_id ) {
			$user  = get_userdata( $user_id );
			$email = $user ? $user->user_email : '';
		}
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'dev3bq_bad_email', __( 'Please enter a valid email address.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$status = isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'requested';
		$row    = array(
			'number'      => 'tmp-' . wp_generate_password( 12, false ),
			'status'      => $status,
			'user_id'     => $user_id,
			'email'       => $email,
			'name'        => mb_substr( sanitize_text_field( isset( $data['name'] ) ? (string) $data['name'] : '' ), 0, 160 ),
			'company'     => mb_substr( sanitize_text_field( isset( $data['company'] ) ? (string) $data['company'] : '' ), 0, 150 ),
			'phone'       => mb_substr( sanitize_text_field( isset( $data['phone'] ) ? (string) $data['phone'] : '' ), 0, 40 ),
			'role'        => isset( $data['role'] ) ? sanitize_key( (string) $data['role'] ) : Roles::user_role( $user_id ),
			'request'     => mb_substr( sanitize_textarea_field( isset( $data['request'] ) ? (string) $data['request'] : '' ), 0, 4000 ),
			'message'     => '',
			'admin_note'  => '',
			'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'tax_mode'    => Roles::tax_exempt( $user_id ) ? 'exempt' : 'store',
			'valid_until' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS * (int) Settings::get( 'quote_valid_days', 30 ) ),
			'terms'       => '',
			'access_key'  => wp_generate_password( 32, false ),
			'source'      => isset( $data['source'] ) && in_array( $data['source'], self::SOURCES, true ) ? $data['source'] : 'form',
			'created_at'  => $now,
			'updated_at'  => $now,
		);
		$table  = Tables::name( 'quotes' );
		if ( ! $wpdb->insert( $table, $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new \WP_Error( 'dev3bq_db', __( 'The quote could not be saved.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 500 ) );
		}
		$id     = (int) $wpdb->insert_id;
		$number = self::format_number( $id );
		$wpdb->update( $table, array( 'number' => $number ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $items ) {
			self::set_items( $id, $items );
		}
		self::recalc( $id );
		/**
		 * Fires after a quote is created.
		 *
		 * @param int $id Quote id.
		 */
		do_action( 'dev3bq_quote_created', $id );
		return $id;
	}

	/**
	 * Quote number from the id.
	 *
	 * @param int $id Id.
	 * @return string
	 */
	public static function format_number( $id ) {
		/**
		 * Filters the quote number.
		 *
		 * @param string $number Number.
		 * @param int    $id     Quote id.
		 */
		return (string) apply_filters( 'dev3bq_quote_number', Settings::get( 'quote_prefix', 'Q-' ) . str_pad( (string) ( 1000 + (int) $id ), 5, '0', STR_PAD_LEFT ), $id );
	}

	/**
	 * Row by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'quotes' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Row by number.
	 *
	 * @param string $number Number.
	 * @return array|null
	 */
	public static function get_by_number( $number ) {
		global $wpdb;
		$table = Tables::name( 'quotes' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE number = %s", (string) $number ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Casts a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function hydrate( array $row ) {
		foreach ( array( 'id', 'user_id', 'order_id', 'followup_sent' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		foreach ( array( 'subtotal', 'discount', 'shipping', 'tax', 'total' ) as $k ) {
			$row[ $k ] = (float) $row[ $k ];
		}
		foreach ( array( 'request', 'message', 'admin_note', 'terms', 'valid_until', 'sent_at', 'decided_at' ) as $k ) {
			$row[ $k ] = null === $row[ $k ] ? '' : (string) $row[ $k ];
		}
		return $row;
	}

	/**
	 * Updates fields.
	 *
	 * @param int   $id     Id.
	 * @param array $fields Fields.
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$allowed = array( 'status', 'email', 'name', 'company', 'phone', 'role', 'request', 'message', 'admin_note', 'discount', 'shipping', 'tax_mode', 'valid_until', 'terms', 'order_id', 'followup_sent', 'sent_at', 'decided_at', 'user_id' );
		$data    = array_intersect_key( $fields, array_flip( $allowed ) );
		if ( ! $data ) {
			return false;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$ok                 = false !== $wpdb->update( Tables::name( 'quotes' ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( isset( $data['discount'] ) || isset( $data['shipping'] ) || isset( $data['tax_mode'] ) ) {
			self::recalc( $id );
		}
		return $ok;
	}

	/**
	 * Atomic status change (only from the given statuses).
	 *
	 * @param int      $id   Id.
	 * @param string   $to   New status.
	 * @param string[] $from Allowed current statuses.
	 * @param array    $also Extra columns.
	 * @return bool Changed.
	 */
	public static function transition( $id, $to, array $from, array $also = array() ) {
		global $wpdb;
		if ( ! in_array( $to, self::STATUSES, true ) || ! $from ) {
			return false;
		}
		$table = Tables::name( 'quotes' );
		$in    = implode( ', ', array_fill( 0, count( $from ), '%s' ) );
		$sets  = array( 'status = %s', 'updated_at = %s' );
		$vals  = array( $to, current_time( 'mysql', true ) );
		foreach ( $also as $col => $val ) {
			if ( in_array( $col, array( 'order_id', 'decided_at', 'sent_at', 'followup_sent' ), true ) ) {
				$sets[] = $col . ' = %s';
				$vals[] = (string) $val;
			}
		}
		$vals[] = (int) $id;
		$vals   = array_merge( $vals, $from );
		$sql    = "UPDATE {$table} SET " . implode( ', ', $sets ) . " WHERE id = %d AND status IN ({$in})";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from fixed column names and placeholders only.
		$changed = $wpdb->query( $wpdb->prepare( $sql, $vals ) );
		return 1 === (int) $changed;
	}

	/**
	 * Lines.
	 *
	 * @param int $id Quote.
	 * @return array
	 */
	public static function items( $id ) {
		global $wpdb;
		$table = Tables::name( 'quote_items' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE quote_id = %d ORDER BY position ASC, id ASC", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			static function ( $r ) {
				return array(
					'id'            => (int) $r['id'],
					'product_id'    => (int) $r['product_id'],
					'variation_id'  => (int) $r['variation_id'],
					'name'          => (string) $r['name'],
					'sku'           => (string) $r['sku'],
					'qty'           => (int) $r['qty'],
					'regular_price' => (float) $r['regular_price'],
					'unit_price'    => (float) $r['unit_price'],
					'line_total'    => (float) $r['line_total'],
					'note'          => (string) $r['note'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Normalizes one input line (prices from the rules when not given).
	 *
	 * @param array  $line Line.
	 * @param string $role Role for pricing.
	 * @return array|null
	 */
	public static function normalize_line( array $line, $role ) {
		$qty  = min( 100000, max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) ) );
		$pid  = (int) ( isset( $line['product_id'] ) ? $line['product_id'] : 0 );
		$vid  = (int) ( isset( $line['variation_id'] ) ? $line['variation_id'] : 0 );
		$note = mb_substr( sanitize_text_field( isset( $line['note'] ) ? (string) $line['note'] : '' ), 0, 255 );
		$prod = $vid ? wc_get_product( $vid ) : ( $pid ? wc_get_product( $pid ) : null );
		if ( $prod && $prod->is_type( 'variation' ) ) {
			$vid = $prod->get_id();
			$pid = $prod->get_parent_id();
		} elseif ( $prod && $prod->is_type( 'variable' ) ) {
			$prod = null; // A variable parent has no price; a variation must be chosen.
		}
		if ( ! $prod ) {
			$name = mb_substr( sanitize_text_field( isset( $line['name'] ) ? (string) $line['name'] : '' ), 0, 255 );
			if ( '' === $name ) {
				return null;
			}
			$unit = isset( $line['unit_price'] ) && is_numeric( $line['unit_price'] ) ? max( 0, (float) $line['unit_price'] ) : 0;
			return array(
				'product_id'    => 0,
				'variation_id'  => 0,
				'name'          => $name,
				'sku'           => mb_substr( sanitize_text_field( isset( $line['sku'] ) ? (string) $line['sku'] : '' ), 0, 100 ),
				'qty'           => $qty,
				'regular_price' => $unit,
				'unit_price'    => $unit,
				'line_total'    => Engine::round( $unit * $qty ),
				'note'          => $note,
			);
		}
		$price = Engine::price( $prod, $role, $qty );
		$unit  = isset( $line['unit_price'] ) && '' !== $line['unit_price'] && null !== $line['unit_price'] && is_numeric( $line['unit_price'] ) ? Engine::round( $line['unit_price'] ) : $price['price'];
		return array(
			'product_id'    => $pid ? $pid : $prod->get_id(),
			'variation_id'  => $vid,
			'name'          => mb_substr( wp_strip_all_tags( $prod->get_name() ), 0, 255 ),
			'sku'           => mb_substr( (string) $prod->get_sku(), 0, 100 ),
			'qty'           => $qty,
			'regular_price' => (float) $price['regular'],
			'unit_price'    => $unit,
			'line_total'    => Engine::round( $unit * $qty ),
			'note'          => $note,
		);
	}

	/**
	 * Replaces the lines and recalculates totals.
	 *
	 * @param int   $id    Quote.
	 * @param array $lines Lines.
	 * @return array Saved lines.
	 */
	public static function set_items( $id, array $lines ) {
		global $wpdb;
		$quote = self::get( $id );
		if ( ! $quote ) {
			return array();
		}
		$table = Tables::name( 'quote_items' );
		$wpdb->delete( $table, array( 'quote_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$pos = 0;
		foreach ( array_slice( $lines, 0, 200 ) as $line ) {
			$clean = is_array( $line ) ? self::normalize_line( $line, $quote['role'] ) : null;
			if ( ! $clean ) {
				continue;
			}
			$clean['quote_id'] = (int) $id;
			$clean['position'] = $pos++;
			$wpdb->insert( $table, $clean ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		self::recalc( $id );
		return self::items( $id );
	}

	/**
	 * Re-prices every product line from the current rules.
	 *
	 * @param int $id Quote.
	 * @return array Lines.
	 */
	public static function reprice( $id ) {
		$lines = array_map(
			static function ( $l ) {
				if ( $l['product_id'] ) {
					unset( $l['unit_price'] );
				}
				return $l;
			},
			self::items( $id )
		);
		return self::set_items( $id, $lines );
	}

	/**
	 * Estimated tax for the lines (store rates for the customer's address), 0 when exempt.
	 *
	 * @param array $quote Quote.
	 * @param array $items Lines.
	 * @return float Tax to add (0 when prices include tax).
	 */
	public static function estimate_tax( array $quote, array $items ) {
		if ( 'exempt' === $quote['tax_mode'] || ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() || wc_prices_include_tax() ) {
			return 0.0;
		}
		$location = self::tax_location( $quote );
		$tax      = 0.0;
		foreach ( $items as $item ) {
			$product = $item['variation_id'] ? wc_get_product( $item['variation_id'] ) : ( $item['product_id'] ? wc_get_product( $item['product_id'] ) : null );
			if ( $product && 'taxable' !== $product->get_tax_status() ) {
				continue;
			}
			$rates = \WC_Tax::find_rates(
				array(
					'country'   => $location[0],
					'state'     => $location[1],
					'postcode'  => $location[2],
					'city'      => $location[3],
					'tax_class' => $product ? $product->get_tax_class() : '',
				)
			);
			$tax  += array_sum( \WC_Tax::calc_tax( $item['line_total'], $rates, false ) );
		}
		return Engine::round( $tax );
	}

	/**
	 * Tax location: customer billing address, else the store base.
	 *
	 * @param array $quote Quote.
	 * @return array [country, state, postcode, city]
	 */
	private static function tax_location( array $quote ) {
		if ( $quote['user_id'] && class_exists( '\WC_Customer' ) ) {
			try {
				$c = new \WC_Customer( $quote['user_id'] );
				if ( $c->get_billing_country() ) {
					return array( $c->get_billing_country(), $c->get_billing_state(), $c->get_billing_postcode(), $c->get_billing_city() );
				}
			} catch ( \Exception $e ) {
				unset( $e );
			}
		}
		return array( WC()->countries->get_base_country(), WC()->countries->get_base_state(), WC()->countries->get_base_postcode(), WC()->countries->get_base_city() );
	}

	/**
	 * Recalculates totals.
	 *
	 * @param int $id Quote.
	 * @return array|null Quote.
	 */
	public static function recalc( $id ) {
		global $wpdb;
		$quote = self::get( $id );
		if ( ! $quote ) {
			return null;
		}
		$items    = self::items( $id );
		$subtotal = Engine::round( array_sum( wp_list_pluck( $items, 'line_total' ) ) );
		$discount = min( $subtotal, max( 0, (float) $quote['discount'] ) );
		$taxable  = $items;
		if ( $discount > 0 && $subtotal > 0 ) {
			$ratio = ( $subtotal - $discount ) / $subtotal;
			foreach ( $taxable as $i => $item ) {
				$taxable[ $i ]['line_total'] = $item['line_total'] * $ratio;
			}
		}
		$tax   = self::estimate_tax( $quote, $taxable );
		$total = Engine::round( $subtotal - $discount + (float) $quote['shipping'] + $tax );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'quotes' ),
			array(
				'subtotal' => $subtotal,
				'discount' => $discount,
				'tax'      => $tax,
				'total'    => $total,
			),
			array( 'id' => (int) $id )
		);
		return self::get( $id );
	}

	/**
	 * Adds a timeline event.
	 *
	 * @param int    $id      Quote.
	 * @param string $type    status|message|email|note|ai.
	 * @param string $actor   customer|admin|system.
	 * @param string $message Text.
	 * @return int Event id.
	 */
	public static function add_event( $id, $type, $actor, $message ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'quote_events' ),
			array(
				'quote_id'   => (int) $id,
				'type'       => substr( sanitize_key( $type ), 0, 12 ),
				'actor'      => in_array( $actor, array( 'customer', 'admin', 'system' ), true ) ? $actor : 'system',
				'user_id'    => get_current_user_id(),
				'message'    => mb_substr( sanitize_textarea_field( (string) $message ), 0, 4000 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Timeline.
	 *
	 * @param int  $id            Quote.
	 * @param bool $customer_only Only messages/status visible to the customer.
	 * @return array
	 */
	public static function events( $id, $customer_only = false ) {
		global $wpdb;
		$table = Tables::name( 'quote_events' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE quote_id = %d ORDER BY id ASC", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $r ) {
			if ( $customer_only && ! in_array( $r['type'], array( 'message', 'status' ), true ) ) {
				continue;
			}
			$user  = (int) $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : null;
			$out[] = array(
				'id'         => (int) $r['id'],
				'type'       => (string) $r['type'],
				'actor'      => (string) $r['actor'],
				'user'       => $user && ! $customer_only ? $user->display_name : '',
				'message'    => (string) $r['message'],
				'created_at' => mysql_to_rfc3339( $r['created_at'] ),
			);
		}
		return $out;
	}

	/**
	 * Admin list.
	 *
	 * @param array $args status, search, page, per_page, user_id, email.
	 * @return array { rows, total, counts }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table  = Tables::name( 'quotes' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) && 'open' === $args['status'] ) {
			$where[] = "status IN ('requested','draft','sent')";
		} elseif ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = '(user_id = %d OR (user_id = 0 AND email = %s))';
			$params[] = (int) $args['user_id'];
			$params[] = isset( $args['email'] ) ? (string) $args['email'] : '';
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(number LIKE %s OR email LIKE %s OR name LIKE %s OR company LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$per    = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page   = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$sql    = implode( ' AND ', $where );
		$limit  = $wpdb->prepare( ' LIMIT %d OFFSET %d', $per, ( $page - 1 ) * $per );
		$select = "SELECT * FROM {$table} WHERE {$sql} ORDER BY updated_at DESC, id DESC";
		$count  = "SELECT COUNT(*) FROM {$table} WHERE {$sql}";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $params ? $wpdb->get_results( $wpdb->prepare( $select, $params ) . $limit, ARRAY_A ) : $wpdb->get_results( $select . $limit, ARRAY_A );
		$total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : (int) $wpdb->get_var( $count );
		$raw   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		// phpcs:enable
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $raw as $r ) {
			$counts[ $r['status'] ] = (int) $r['n'];
		}
		$counts['open'] = $counts['requested'] + $counts['draft'] + $counts['sent'];
		return array(
			'rows'   => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ),
			'total'  => $total,
			'counts' => $counts,
		);
	}

	/**
	 * Quotes that are sent and due for a reminder or expiry.
	 *
	 * @param string $what reminder|expire.
	 * @return array
	 */
	public static function due( $what ) {
		global $wpdb;
		$table = Tables::name( 'quotes' );
		if ( 'expire' === $what ) {
			$today = gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('sent','draft','requested') AND valid_until IS NOT NULL AND valid_until < %s LIMIT 200", $today ), ARRAY_A );
		} else {
			$days = (int) Settings::get( 'followup_days', 3 );
			if ( $days < 1 ) {
				return array();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'sent' AND followup_sent = 0 AND sent_at IS NOT NULL AND sent_at <= %s LIMIT 200", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A );
		}
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Stats for the dashboard.
	 *
	 * @param int $days Window.
	 * @return array
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;
		$table = Tables::name( 'quotes' );
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$open     = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS v FROM {$table} WHERE status IN ('requested','draft','sent')", ARRAY_A );
		$decided  = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status='accepted') AS a, SUM(status IN ('accepted','declined','expired')) AS d, COALESCE(SUM(CASE WHEN status='accepted' THEN total ELSE 0 END),0) AS won FROM {$table} WHERE updated_at >= %s", $since ), ARRAY_A );
		$requests = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since ) );
		$new      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'requested'" );
		// phpcs:enable
		$d = (int) ( $decided['d'] ?? 0 );
		return array(
			'open'        => (int) $open['n'],
			'open_value'  => (float) $open['v'],
			'new'         => $new,
			'requests'    => $requests,
			'accepted'    => (int) ( $decided['a'] ?? 0 ),
			'won_value'   => (float) ( $decided['won'] ?? 0 ),
			'accept_rate' => $d ? round( 100 * (int) $decided['a'] / $d ) : null,
		);
	}
}
