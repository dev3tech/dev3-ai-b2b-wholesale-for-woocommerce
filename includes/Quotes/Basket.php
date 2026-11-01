<?php
/**
 * Quote basket in the WooCommerce session (works for guests).
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Quotes;

use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Wholesale\Roles;
use Dev3\AiB2bWholesale\Woo\Storefront_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Basket.
 */
class Basket {

	const KEY = 'dev3bq_basket';
	const MAX = 100;

	/**
	 * Makes sure the WooCommerce session + cart are loaded (REST requests do not load them).
	 *
	 * @return bool
	 */
	public static function boot() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		if ( ( ! WC()->session || ! WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( WC()->cart ) {
			WC()->cart->get_cart(); // Loads the cart from the session before anything changes it.
		}
		return (bool) WC()->session;
	}

	/**
	 * Raw lines.
	 *
	 * @return array [ key => {product_id, variation_id, qty} ]
	 */
	public static function raw() {
		if ( ! self::boot() ) {
			return array();
		}
		$lines = WC()->session->get( self::KEY, array() );
		return is_array( $lines ) ? $lines : array();
	}

	/**
	 * Saves lines.
	 *
	 * @param array $lines Lines.
	 */
	private static function store( array $lines ) {
		if ( ! self::boot() ) {
			return;
		}
		WC()->session->set( self::KEY, array_slice( $lines, 0, self::MAX, true ) );
		if ( ! headers_sent() && ! WC()->session->has_session() && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	/**
	 * Adds a product.
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation.
	 * @param int $qty          Qty.
	 * @return array|\WP_Error Basket view.
	 */
	public static function add( $product_id, $variation_id = 0, $qty = 1 ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product || 'publish' !== get_post_status( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) || $product->is_type( 'variable' ) || ! Engine::visible_for( $product, Roles::user_role() ) ) {
			return new \WP_Error( 'dev3bq_bad_product', __( 'This product cannot be added to a quote.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$lines = self::raw();
		$pid   = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$vid   = $product->get_parent_id() ? $product->get_id() : 0;
		$key   = $pid . ':' . $vid;
		$qty   = min( 100000, max( 1, (int) $qty ) );
		if ( isset( $lines[ $key ] ) ) {
			$lines[ $key ]['qty'] = min( 100000, $lines[ $key ]['qty'] + $qty );
		} else {
			$lines[ $key ] = array(
				'product_id'   => $pid,
				'variation_id' => $vid,
				'qty'          => $qty,
			);
		}
		self::store( $lines );
		return self::view();
	}

	/**
	 * Updates quantities (0 removes).
	 *
	 * @param array $qtys [ key => qty ].
	 * @return array Basket view.
	 */
	public static function update( array $qtys ) {
		$lines = self::raw();
		foreach ( $qtys as $key => $qty ) {
			$key = (string) $key;
			if ( ! isset( $lines[ $key ] ) ) {
				continue;
			}
			if ( (int) $qty <= 0 ) {
				unset( $lines[ $key ] );
			} else {
				$lines[ $key ]['qty'] = min( 100000, (int) $qty );
			}
		}
		self::store( $lines );
		return self::view();
	}

	/**
	 * Empties the basket.
	 */
	public static function clear() {
		self::store( array() );
	}

	/**
	 * Copies the cart into the basket.
	 *
	 * @param bool $empty_cart Empty the cart afterwards.
	 * @return array Basket view.
	 */
	public static function from_cart( $empty_cart = false ) {
		self::boot();
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				self::add( (int) $item['product_id'], (int) $item['variation_id'], (int) $item['quantity'] );
			}
			if ( $empty_cart ) {
				WC()->cart->empty_cart();
			}
		}
		return self::view();
	}

	/**
	 * Lines for Quotes::create().
	 *
	 * @return array
	 */
	public static function lines() {
		return array_values( self::raw() );
	}

	/**
	 * Shopper view (prices only when the visitor may see them).
	 *
	 * @return array { lines[{key, product_id, variation_id, name, sku, qty, price, url, image}], count }
	 */
	public static function view() {
		$role  = Roles::user_role();
		$out   = array();
		$count = 0;
		foreach ( self::raw() as $key => $line ) {
			$product = wc_get_product( $line['variation_id'] ? $line['variation_id'] : $line['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$price  = Storefront_Pricing::prices_hidden() ? '' : html_entity_decode( wp_strip_all_tags( wc_price( wc_get_price_to_display( $product, array( 'price' => Engine::price( $product, $role, $line['qty'] )['price'] ) ) ) ), ENT_QUOTES, 'UTF-8' );
			$image  = wp_get_attachment_image_url( $product->get_image_id() ? $product->get_image_id() : get_post_thumbnail_id( $line['product_id'] ), 'woocommerce_gallery_thumbnail' );
			$out[]  = array(
				'key'          => (string) $key,
				'product_id'   => (int) $line['product_id'],
				'variation_id' => (int) $line['variation_id'],
				'name'         => wp_strip_all_tags( $product->get_name() ),
				'sku'          => (string) $product->get_sku(),
				'qty'          => (int) $line['qty'],
				'price'        => $price,
				'url'          => get_permalink( $line['product_id'] ),
				'image'        => $image ? $image : '',
			);
			$count += (int) $line['qty'];
		}
		return array(
			'lines' => $out,
			'count' => $count,
		);
	}
}
