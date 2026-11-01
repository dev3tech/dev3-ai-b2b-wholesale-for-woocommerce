<?php
/**
 * Cart/checkout rules per role: minimums, tax exemption, payment gateways, shipping methods, order meta.
 *
 * Works for the classic and the block checkout: `woocommerce_check_cart_items` is run by both
 * (the Store API turns its notices into errors), and both read gateways/rates through the same filters.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Woo;

use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Settings;
use Dev3\AiB2bWholesale\Wholesale\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout rules.
 */
class Checkout_Rules {

	const ORDER_ROLE_META = '_dev3bq_role';

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'vat_exempt' ), 1 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'gateways' ), 20 );
		add_filter( 'woocommerce_package_rates', array( $this, 'rates' ), 20, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'packages' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'order_meta' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'order_meta' ), 20 );
	}

	/**
	 * Minimum/step problems for a cart and role.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @param string   $role Role.
	 * @return string[] Messages.
	 */
	public static function errors( $cart, $role ) {
		$def = '' !== $role ? Roles::get( $role ) : null;
		if ( ! $def || ! $cart instanceof \WC_Cart || $cart->is_empty() ) {
			return array();
		}
		$errors = array();
		$value  = (float) $cart->get_subtotal() + ( $cart->display_prices_including_tax() ? (float) $cart->get_subtotal_tax() : 0 );
		if ( $def['min_value'] > 0 && $value + 0.0001 < $def['min_value'] ) {
			$errors[] = sprintf(
				/* translators: 1: role name, 2: minimum amount, 3: missing amount */
				__( '%1$s orders need at least %2$s. Add %3$s more to check out.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				$def['name'],
				wp_strip_all_tags( wc_price( $def['min_value'] ) ),
				wp_strip_all_tags( wc_price( $def['min_value'] - $value ) )
			);
		}
		$count = (int) $cart->get_cart_contents_count();
		if ( $def['min_qty'] > 0 && $count < $def['min_qty'] ) {
			$errors[] = sprintf(
				/* translators: 1: role name, 2: minimum items, 3: missing items */
				__( '%1$s orders need at least %2$d items. Add %3$d more to check out.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				$def['name'],
				$def['min_qty'],
				$def['min_qty'] - $count
			);
		}
		foreach ( $cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$q   = Engine::qty_rules( $product, $role );
			$qty = (int) $item['quantity'];
			if ( $qty < $q['min'] ) {
				/* translators: 1: product name, 2: minimum quantity */
				$errors[] = sprintf( __( '%1$s: the minimum for wholesale orders is %2$d.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $product->get_name(), $q['min'] );
			} elseif ( $q['step'] > 1 && 0 !== $qty % $q['step'] ) {
				/* translators: 1: product name, 2: pack size */
				$errors[] = sprintf( __( '%1$s: please order in multiples of %2$d.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $product->get_name(), $q['step'] );
			}
		}
		/**
		 * Filters cart rule errors for wholesale buyers.
		 *
		 * @param string[] $errors Messages.
		 * @param \WC_Cart $cart   Cart.
		 * @param string   $role   Role.
		 */
		return (array) apply_filters( 'dev3bq_cart_errors', $errors, $cart, $role );
	}

	/**
	 * Adds notices (cart, checkout, Store API).
	 */
	public function check_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( self::errors( WC()->cart, Roles::user_role() ) as $message ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Marks a tax-exempt buyer as VAT exempt (never un-marks: other plugins may exempt too).
	 *
	 * @param \WC_Customer $customer Customer.
	 * @return bool Exempt now.
	 */
	public static function apply_exemption( $customer ) {
		if ( ! $customer instanceof \WC_Customer ) {
			return false;
		}
		$id = (int) $customer->get_id();
		if ( $id && Roles::tax_exempt( $id ) ) {
			$customer->set_is_vat_exempt( true );
		}
		return (bool) $customer->get_is_vat_exempt();
	}

	/**
	 * Before every cart calculation (classic cart/checkout and the Store API).
	 */
	public function vat_exempt() {
		if ( function_exists( 'WC' ) && WC()->customer ) {
			self::apply_exemption( WC()->customer );
		}
	}

	/**
	 * Gateways allowed for a role.
	 *
	 * @param array  $gateways Gateways keyed by id.
	 * @param string $role     Role.
	 * @return array
	 */
	public static function filter_gateways( array $gateways, $role ) {
		$def = '' !== $role ? Roles::get( $role ) : null;
		if ( $def ) {
			if ( $def['gateways'] ) {
				$gateways = array_intersect_key( $gateways, array_flip( $def['gateways'] ) );
			}
			return $gateways;
		}
		return array_diff_key( $gateways, array_flip( (array) Settings::get( 'retail_hidden_gateways', array() ) ) );
	}

	/**
	 * Payment gateways filter.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public function gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {
			return $gateways;
		}
		return self::filter_gateways( $gateways, Roles::user_role() );
	}

	/**
	 * Shipping rates allowed for a role (by method type, e.g. flat_rate) plus the role's free-shipping threshold.
	 *
	 * @param array  $rates   Rates keyed by rate id.
	 * @param string $role    Role.
	 * @param float  $value   Package contents cost.
	 * @return array
	 */
	public static function filter_rates( array $rates, $role, $value = 0.0 ) {
		$def = '' !== $role ? Roles::get( $role ) : null;
		if ( $def ) {
			if ( $def['shipping'] ) {
				$allowed = $def['shipping'];
				$rates   = array_filter(
					$rates,
					static fn( $rate ) => in_array( $rate instanceof \WC_Shipping_Rate ? $rate->get_method_id() : '', $allowed, true )
				);
			}
			if ( $def['free_shipping'] > 0 && $value >= $def['free_shipping'] && class_exists( '\WC_Shipping_Rate' ) ) {
				$free  = new \WC_Shipping_Rate( 'dev3bq_free', __( 'Free wholesale shipping', 'dev3-ai-b2b-wholesale-for-woocommerce' ), 0, array(), 'dev3bq_free' );
				$rates = array( 'dev3bq_free' => $free ) + $rates;
			}
			return $rates;
		}
		$hidden = (array) Settings::get( 'retail_hidden_shipping', array() );
		if ( ! $hidden ) {
			return $rates;
		}
		return array_filter(
			$rates,
			static fn( $rate ) => ! in_array( $rate instanceof \WC_Shipping_Rate ? $rate->get_method_id() : '', $hidden, true )
		);
	}

	/**
	 * Rates filter.
	 *
	 * @param array $rates   Rates.
	 * @param array $package Package.
	 * @return array
	 */
	public function rates( $rates, $package ) {
		if ( ! is_array( $rates ) ) {
			return $rates;
		}
		$role = isset( $package['dev3bq_role'] ) ? (string) $package['dev3bq_role'] : Roles::user_role();
		return self::filter_rates( $rates, $role, isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0 );
	}

	/**
	 * Packages carry the role so cached rates vary by role.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public function packages( $packages ) {
		$role = Roles::user_role();
		foreach ( $packages as $i => $package ) {
			$packages[ $i ]['dev3bq_role'] = $role;
		}
		return $packages;
	}

	/**
	 * Records the buyer's role on the order (CRUD meta, HPOS-safe).
	 *
	 * @param \WC_Order $order Order.
	 */
	public function order_meta( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$role = Roles::user_role( (int) $order->get_customer_id() );
		if ( '' !== $role ) {
			$order->update_meta_data( self::ORDER_ROLE_META, $role );
		}
	}
}
