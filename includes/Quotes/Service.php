<?php
/**
 * Quote workflow: send, accept → order (HPOS-safe), decline, messages, reminders and expiry.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Quotes;

use Dev3\AiB2bWholesale\Emails\Emails;
use Dev3\AiB2bWholesale\Settings;
use Dev3\AiB2bWholesale\Woo\Checkout_Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Service.
 */
class Service {

	const ORDER_META = '_dev3bq_quote_id';

	/**
	 * Customer link to a quote.
	 *
	 * @param array $quote Quote.
	 * @return string
	 */
	public static function customer_url( array $quote ) {
		if ( $quote['user_id'] && function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_endpoint_url( \Dev3\AiB2bWholesale\Frontend\Account::ENDPOINT, $quote['number'], wc_get_page_permalink( 'myaccount' ) );
		}
		return Settings::page_url(
			'quote_page_id',
			array(
				'dev3bq_quote' => $quote['number'],
				'key'          => $quote['access_key'],
			)
		);
	}

	/**
	 * Whether the current visitor may see a quote (owner, or the right access key).
	 *
	 * @param array  $quote Quote.
	 * @param string $key   Access key.
	 * @return bool
	 */
	public static function can_view( array $quote, $key = '' ) {
		$uid = get_current_user_id();
		if ( $uid && $quote['user_id'] && $uid === (int) $quote['user_id'] ) {
			return true;
		}
		return '' !== (string) $key && hash_equals( (string) $quote['access_key'], (string) $key );
	}

	/**
	 * Shopper submits a request.
	 *
	 * @param array $data  Contact + request text + source.
	 * @param array $lines Lines.
	 * @return int|\WP_Error Quote id.
	 */
	public static function submit_request( array $data, array $lines ) {
		$text = trim( (string) ( $data['request'] ?? '' ) );
		if ( ! $lines && '' === $text ) {
			return new \WP_Error( 'dev3bq_empty_quote', __( 'Add at least one product or describe what you need.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$data['status'] = 'requested';
		$id             = Quotes::create( $data, $lines );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Quotes::add_event( $id, 'status', 'customer', __( 'Quote requested.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
		Emails::send( 'admin_quote_request', $id );
		Emails::send( 'customer_quote_received', $id );
		return $id;
	}

	/**
	 * Sends (or re-sends) a quote to the customer.
	 *
	 * @param int    $id      Quote.
	 * @param string $message Cover message (optional, replaces the stored one).
	 * @return array|\WP_Error Quote.
	 */
	public static function send( $id, $message = null ) {
		$quote = Quotes::get( $id );
		if ( ! $quote ) {
			return new \WP_Error( 'dev3bq_no_quote', __( 'Quote not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $quote['status'], array( 'requested', 'draft', 'sent', 'expired', 'declined' ), true ) ) {
			return new \WP_Error( 'dev3bq_quote_locked', __( 'This quote can no longer be sent.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) );
		}
		if ( ! Quotes::items( $id ) ) {
			return new \WP_Error( 'dev3bq_quote_empty', __( 'Add at least one line before sending the quote.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$fields = array(
			'status'        => 'sent',
			'sent_at'       => current_time( 'mysql', true ),
			'followup_sent' => 0,
		);
		if ( null !== $message ) {
			$fields['message'] = mb_substr( sanitize_textarea_field( (string) $message ), 0, 4000 );
		}
		$today = gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		if ( ! $quote['valid_until'] || $quote['valid_until'] < $today ) {
			$fields['valid_until'] = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS * (int) Settings::get( 'quote_valid_days', 30 ) );
		}
		Quotes::update( $id, $fields );
		Quotes::recalc( $id );
		Quotes::add_event( $id, 'status', 'admin', __( 'Quote sent to the customer.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
		if ( ! empty( $fields['message'] ) ) {
			Quotes::add_event( $id, 'message', 'admin', $fields['message'] );
		}
		Emails::send( 'customer_quote_ready', $id );
		/**
		 * Fires after a quote is sent.
		 *
		 * @param int $id Quote id.
		 */
		do_action( 'dev3bq_quote_sent', $id );
		return Quotes::get( $id );
	}

	/**
	 * Customer accepts → order.
	 *
	 * @param int    $id    Quote.
	 * @param string $actor customer|admin.
	 * @return \WC_Order|\WP_Error
	 */
	public static function accept( $id, $actor = 'customer' ) {
		$quote = Quotes::get( $id );
		if ( ! $quote ) {
			return new \WP_Error( 'dev3bq_no_quote', __( 'Quote not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$from = 'admin' === $actor ? array( 'requested', 'draft', 'sent' ) : array( 'sent' );
		if ( ! in_array( $quote['status'], $from, true ) ) {
			return new \WP_Error( 'dev3bq_quote_locked', __( 'This quote cannot be accepted any more.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$today = gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		if ( 'customer' === $actor && $quote['valid_until'] && $quote['valid_until'] < $today ) {
			Quotes::transition( $id, 'expired', array( 'sent' ) );
			return new \WP_Error( 'dev3bq_quote_expired', __( 'This quote has expired. Please ask us for a new one.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) );
		}
		if ( ! Quotes::items( $id ) ) {
			return new \WP_Error( 'dev3bq_quote_empty', __( 'This quote has no lines yet.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		// Claim the quote first so two clicks can never create two orders.
		if ( ! Quotes::transition( $id, 'accepted', $from, array( 'decided_at' => current_time( 'mysql', true ) ) ) ) {
			return new \WP_Error( 'dev3bq_quote_locked', __( 'This quote was already accepted.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$order = self::create_order( Quotes::get( $id ) );
		if ( is_wp_error( $order ) ) {
			Quotes::transition( $id, $quote['status'], array( 'accepted' ) );
			return $order;
		}
		Quotes::update( $id, array( 'order_id' => $order->get_id() ) );
		/* translators: %s: order number */
		Quotes::add_event( $id, 'status', $actor, sprintf( __( 'Quote accepted. Order #%s created.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $order->get_order_number() ) );
		Emails::send( 'admin_quote_decision', $id, array( 'event' => 'accepted' ) );
		/**
		 * Fires after a quote became an order.
		 *
		 * @param int       $id    Quote id.
		 * @param \WC_Order $order Order.
		 */
		do_action( 'dev3bq_quote_accepted', $id, $order );
		return $order;
	}

	/**
	 * Builds the order with the quoted prices (CRUD only, so it works with HPOS and legacy storage).
	 *
	 * @param array $quote Quote.
	 * @return \WC_Order|\WP_Error
	 */
	public static function create_order( array $quote ) {
		$exempt   = 'exempt' === $quote['tax_mode'];
		$tax_on   = ! $exempt && wc_tax_enabled();
		$incl_tax = $tax_on && wc_prices_include_tax();
		try {
			$order = wc_create_order(
				array(
					'customer_id' => (int) $quote['user_id'],
					'created_via' => 'dev3bq_quote',
				)
			);
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$order->set_currency( $quote['currency'] ? $quote['currency'] : get_woocommerce_currency() );
			$order->set_prices_include_tax( $incl_tax );
			foreach ( Quotes::items( $quote['id'] ) as $item ) {
				$product = $item['variation_id'] ? wc_get_product( $item['variation_id'] ) : ( $item['product_id'] ? wc_get_product( $item['product_id'] ) : null );
				$total   = (float) $item['line_total'];
				if ( $incl_tax && $product ) {
					$rates  = \WC_Tax::get_base_tax_rates( $product->get_tax_class() );
					$total -= array_sum( \WC_Tax::calc_tax( $total, $rates, true ) );
				}
				if ( $product ) {
					$item_id = $order->add_product(
						$product,
						$item['qty'],
						array(
							'subtotal' => $total,
							'total'    => $total,
						)
					);
					if ( $item_id && '' !== $item['note'] ) {
						wc_add_order_item_meta( $item_id, __( 'Note', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $item['note'] );
					}
				} else {
					$line = new \WC_Order_Item_Product();
					$line->set_name( $item['name'] );
					$line->set_quantity( $item['qty'] );
					$line->set_subtotal( $total );
					$line->set_total( $total );
					$order->add_item( $line );
				}
			}
			if ( $quote['shipping'] > 0 ) {
				$ship = new \WC_Order_Item_Shipping();
				$ship->set_method_title( __( 'Shipping (as quoted)', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
				$ship->set_method_id( 'dev3bq_quote' );
				$ship->set_total( (float) $quote['shipping'] );
				$order->add_item( $ship );
			}
			if ( $quote['discount'] > 0 ) {
				$fee = new \WC_Order_Item_Fee();
				/* translators: %s: quote number */
				$fee->set_name( sprintf( __( 'Discount (quote %s)', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $quote['number'] ) );
				$fee->set_amount( -1 * (float) $quote['discount'] );
				$fee->set_total( -1 * (float) $quote['discount'] );
				$fee->set_tax_status( 'none' );
				$order->add_item( $fee );
			}
			self::set_addresses( $order, $quote );
			if ( $exempt ) {
				$order->update_meta_data( 'is_vat_exempt', 'yes' );
			}
			$order->update_meta_data( self::ORDER_META, (int) $quote['id'] );
			if ( '' !== $quote['role'] ) {
				$order->update_meta_data( Checkout_Rules::ORDER_ROLE_META, $quote['role'] );
			}
			$order->calculate_totals( $tax_on );
			$order->set_status( 'pending' );
			/* translators: %s: quote number */
			$order->add_order_note( sprintf( __( 'Created from accepted quote %s.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $quote['number'] ) );
			$order->save();
			return wc_get_order( $order->get_id() );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'dev3bq_order_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Billing/shipping from the customer account, else the quote contact.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $quote Quote.
	 */
	private static function set_addresses( $order, array $quote ) {
		$customer = null;
		if ( $quote['user_id'] ) {
			try {
				$customer = new \WC_Customer( (int) $quote['user_id'] );
			} catch ( \Exception $e ) {
				$customer = null;
			}
		}
		$name  = trim( (string) $quote['name'] );
		$parts = preg_split( '/\s+/', $name, 2 );
		$order->set_billing_email( $quote['email'] );
		$order->set_billing_first_name( $customer && $customer->get_billing_first_name() ? $customer->get_billing_first_name() : (string) ( $parts[0] ?? '' ) );
		$order->set_billing_last_name( $customer && $customer->get_billing_last_name() ? $customer->get_billing_last_name() : (string) ( $parts[1] ?? '' ) );
		$order->set_billing_company( $quote['company'] ? $quote['company'] : ( $customer ? $customer->get_billing_company() : '' ) );
		$order->set_billing_phone( $quote['phone'] ? $quote['phone'] : ( $customer ? $customer->get_billing_phone() : '' ) );
		if ( $customer ) {
			foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $field ) {
				$order->{"set_billing_{$field}"}( $customer->{"get_billing_{$field}"}() );
				$ship = $customer->{"get_shipping_{$field}"}();
				$order->{"set_shipping_{$field}"}( '' !== $ship ? $ship : $customer->{"get_billing_{$field}"}() );
			}
			$order->set_shipping_first_name( $customer->get_shipping_first_name() ? $customer->get_shipping_first_name() : $order->get_billing_first_name() );
			$order->set_shipping_last_name( $customer->get_shipping_last_name() ? $customer->get_shipping_last_name() : $order->get_billing_last_name() );
			$order->set_shipping_company( $customer->get_shipping_company() ? $customer->get_shipping_company() : $order->get_billing_company() );
		}
	}

	/**
	 * Customer declines.
	 *
	 * @param int    $id     Quote.
	 * @param string $reason Reason.
	 * @param string $actor  customer|admin.
	 * @return bool|\WP_Error
	 */
	public static function decline( $id, $reason = '', $actor = 'customer' ) {
		$from = 'admin' === $actor ? array( 'requested', 'draft', 'sent' ) : array( 'sent' );
		if ( ! Quotes::transition( $id, 'declined', $from, array( 'decided_at' => current_time( 'mysql', true ) ) ) ) {
			return new \WP_Error( 'dev3bq_quote_locked', __( 'This quote cannot be declined any more.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$reason = trim( sanitize_textarea_field( (string) $reason ) );
		Quotes::add_event( $id, 'status', $actor, '' !== $reason ? sprintf( /* translators: %s: reason */ __( 'Quote declined: %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $reason ) : __( 'Quote declined.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
		if ( 'customer' === $actor ) {
			Emails::send(
				'admin_quote_decision',
				$id,
				array(
					'event'   => 'declined',
					'message' => $reason,
				)
			);
		}
		return true;
	}

	/**
	 * A message on the quote thread.
	 *
	 * @param int    $id      Quote.
	 * @param string $message Text.
	 * @param string $actor   customer|admin.
	 * @return bool|\WP_Error
	 */
	public static function message( $id, $message, $actor ) {
		$message = trim( mb_substr( sanitize_textarea_field( (string) $message ), 0, 4000 ) );
		if ( '' === $message ) {
			return new \WP_Error( 'dev3bq_empty_message', __( 'Write a message first.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$quote = Quotes::get( $id );
		if ( ! $quote ) {
			return new \WP_Error( 'dev3bq_no_quote', __( 'Quote not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 404 ) );
		}
		Quotes::add_event( $id, 'message', $actor, $message );
		Quotes::update( $id, array( 'admin_note' => $quote['admin_note'] ) ); // Bumps updated_at so the quote rises in the list.
		if ( 'admin' === $actor ) {
			Emails::send( 'customer_quote_message', $id, array( 'message' => $message ) );
		} else {
			Emails::send(
				'admin_quote_decision',
				$id,
				array(
					'event'   => 'message',
					'message' => $message,
				)
			);
		}
		return true;
	}

	/**
	 * Daily cron: reminders and expiry.
	 *
	 * @return array { reminded, expired }
	 */
	public static function daily() {
		$reminded = 0;
		$expired  = 0;
		foreach ( Quotes::due( 'expire' ) as $quote ) {
			if ( Quotes::transition( $quote['id'], 'expired', array( 'sent', 'draft', 'requested' ) ) ) {
				++$expired;
				Quotes::add_event( $quote['id'], 'status', 'system', __( 'Quote expired.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
				if ( 'sent' === $quote['status'] && Settings::get( 'expiry_email', true ) ) {
					Emails::send( 'customer_quote_reminder', $quote['id'], array( 'event' => 'expired' ) );
				}
			}
		}
		foreach ( Quotes::due( 'reminder' ) as $quote ) {
			if ( Quotes::transition( $quote['id'], 'sent', array( 'sent' ), array( 'followup_sent' => 1 ) ) ) {
				++$reminded;
				Emails::send( 'customer_quote_reminder', $quote['id'], array( 'event' => 'reminder' ) );
				Quotes::add_event( $quote['id'], 'email', 'system', __( 'Reminder sent.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
			}
		}
		return array(
			'reminded' => $reminded,
			'expired'  => $expired,
		);
	}

	/**
	 * Duplicates a quote as a new draft.
	 *
	 * @param int $id Quote.
	 * @return int|\WP_Error New id.
	 */
	public static function duplicate( $id ) {
		$quote = Quotes::get( $id );
		if ( ! $quote ) {
			return new \WP_Error( 'dev3bq_no_quote', __( 'Quote not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$new = Quotes::create(
			array(
				'user_id' => $quote['user_id'],
				'email'   => $quote['email'],
				'name'    => $quote['name'],
				'company' => $quote['company'],
				'phone'   => $quote['phone'],
				'role'    => $quote['role'],
				'request' => $quote['request'],
				'status'  => 'draft',
				'source'  => 'admin',
			),
			Quotes::items( $id )
		);
		if ( ! is_wp_error( $new ) ) {
			Quotes::update(
				$new,
				array(
					'shipping' => $quote['shipping'],
					'discount' => $quote['discount'],
					'terms'    => $quote['terms'],
					'tax_mode' => $quote['tax_mode'],
				)
			);
			/* translators: %s: quote number */
			Quotes::add_event( $new, 'note', 'admin', sprintf( __( 'Copied from %s.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $quote['number'] ) );
		}
		return $new;
	}
}
