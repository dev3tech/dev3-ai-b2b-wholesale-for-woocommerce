<?php
/**
 * Order screen: "Quote" meta box linking back to the quote (HPOS and legacy screens).
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Woo;

use Dev3\AiB2bWholesale\Quotes\Quotes;
use Dev3\AiB2bWholesale\Quotes\Service;
use Dev3\AiB2bWholesale\Wholesale\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Order admin.
 */
class Order_Admin {

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ), 30 );
	}

	/**
	 * Registers the box on both order screens.
	 */
	public function meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && function_exists( 'wc_get_container' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'dev3bq-quote', __( 'B2B', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( $this, 'render' ), $screen, 'side', 'default' );
	}

	/**
	 * Box content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post (legacy) or order (HPOS).
	 */
	public function render( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$role  = Roles::get( (string) $order->get_meta( Checkout_Rules::ORDER_ROLE_META ) );
		$quote = Quotes::get( (int) $order->get_meta( Service::ORDER_META ) );
		if ( $role ) {
			/* translators: %s: role name */
			echo '<p>' . esc_html( sprintf( __( 'Wholesale role: %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $role['name'] ) ) . '</p>';
		}
		if ( 'yes' === $order->get_meta( 'is_vat_exempt' ) ) {
			echo '<p>' . esc_html__( 'Tax exempt', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</p>';
		}
		if ( $quote ) {
			echo '<p>' . esc_html__( 'Created from quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3BQ_SLUG . '#/quotes/' . $quote['id'] ) ) . '"><strong>' . esc_html( $quote['number'] ) . '</strong></a></p>';
		}
		if ( ! $role && ! $quote ) {
			echo '<p>' . esc_html__( 'Retail order.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</p>';
		}
	}
}
