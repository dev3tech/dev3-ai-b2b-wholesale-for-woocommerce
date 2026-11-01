<?php
/**
 * Minimums, tax exemption, payment and shipping per role, order meta, HPOS/blocks compatibility.
 *
 * @package Dev3\AiB2bWholesale
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiB2bWholesale\Core3\Core\Settings_Store;
use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Wholesale\Roles;
use Dev3\AiB2bWholesale\Woo\Checkout_Rules;

class CheckoutRulesTest extends Dev3bq_Base_Case {

	public function test_hpos_and_blocks_compatibility_declared() {
		$features = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
		$this->assertContains( plugin_basename( DEV3BQ_FILE ), $features->get_compatible_plugins_for_feature( 'custom_order_tables', true )['compatible'] );
		$this->assertContains( plugin_basename( DEV3BQ_FILE ), $features->get_compatible_plugins_for_feature( 'cart_checkout_blocks', true )['compatible'] );
	}

	public function test_minimum_order_value_and_items_and_steps() {
		Roles::save( array_merge( Roles::get( 'distributor' ), array( 'min_value' => 250, 'min_qty' => 30 ) ) );
		$p = $this->product( 'Kraft mug', '10', array( 'meta' => array( Engine::META_STEP => 6 ) ) );
		wp_set_current_user( $this->user( 'distributor' ) );
		$cart = $this->cart();
		$cart->add_to_cart( $p->get_id(), 25 );
		$cart->calculate_totals();
		$errors = Checkout_Rules::errors( $cart, 'distributor' );
		$this->assertCount( 3, $errors );
		$this->assertStringContainsString( 'at least', $errors[0] );
		$this->assertStringContainsString( '50.00', $errors[0], 'Shortfall: 250 - 25 × 8' );
		$this->assertStringContainsString( '5 more', $errors[1] );
		$this->assertStringContainsString( 'multiples of 6', $errors[2] );
		$this->assertSame( array(), Checkout_Rules::errors( $cart, '' ), 'Retail carts have no wholesale minimums' );

		wc_clear_notices();
		do_action( 'woocommerce_check_cart_items' );
		$this->assertSame( 3, wc_notice_count( 'error' ), 'Notices block classic and block checkout' );
		wc_clear_notices();

		$cart->empty_cart();
		$cart->add_to_cart( $p->get_id(), 36 );
		$cart->calculate_totals();
		$this->assertSame( array(), Checkout_Rules::errors( $cart, 'distributor' ) );
	}

	public function test_tax_exempt_by_role_and_override() {
		Roles::save( array_merge( Roles::get( 'wholesale' ), array( 'tax_exempt' => true ) ) );
		$uid = $this->user( 'wholesale' );
		$this->assertTrue( Roles::tax_exempt( $uid ) );
		$this->assertTrue( Checkout_Rules::apply_exemption( new WC_Customer( $uid ) ) );
		update_user_meta( $uid, '_dev3bq_tax_exempt', 'no' );
		$this->assertFalse( Roles::tax_exempt( $uid ) );
		$retail = $this->user( 'customer', array( '_dev3bq_tax_exempt' => 'yes' ) );
		$this->assertTrue( Checkout_Rules::apply_exemption( new WC_Customer( $retail ) ), 'Per-user override' );
		$this->assertFalse( Checkout_Rules::apply_exemption( new WC_Customer( $this->user( 'customer' ) ) ) );
	}

	public function test_tax_exempt_cart_has_no_tax() {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_shipping' => '1',
				'tax_rate_class'    => '',
			)
		);
		Roles::save( array_merge( Roles::get( 'wholesale' ), array( 'tax_exempt' => true ) ) );
		$p = $this->product( 'Kraft mug', '10' );
		wp_set_current_user( $this->user( 'customer' ) );
		$cart = $this->cart();
		$cart->add_to_cart( $p->get_id(), 1 );
		$cart->calculate_totals();
		$this->assertEquals( 2.0, (float) $cart->get_total_tax(), 'Retail pays VAT' );
		$cart->empty_cart();
		wp_set_current_user( $this->user( 'wholesale' ) );
		$cart = $this->cart();
		$cart->add_to_cart( $p->get_id(), 1 );
		$cart->calculate_totals();
		$this->assertEquals( 0.0, (float) $cart->get_total_tax(), 'Exempt role pays no tax' );
	}

	public function test_payment_gateways_per_role_and_retail_hidden() {
		$all = array(
			'bacs'   => 'BACS',
			'cheque' => 'Cheque',
			'cod'    => 'COD',
			'stripe' => 'Card',
		);
		Settings_Store::update( array( 'retail_hidden_gateways' => array( 'bacs' ) ) );
		Settings_Store::flush();
		Roles::save( array_merge( Roles::get( 'distributor' ), array( 'gateways' => array( 'bacs', 'stripe' ) ) ) );
		$this->assertSame( array( 'cheque', 'cod', 'stripe' ), array_keys( Checkout_Rules::filter_gateways( $all, '' ) ) );
		$this->assertSame( array( 'bacs', 'stripe' ), array_keys( Checkout_Rules::filter_gateways( $all, 'distributor' ) ) );
		$this->assertSame( array_keys( $all ), array_keys( Checkout_Rules::filter_gateways( $all, 'wholesale' ) ), 'Empty list = every method' );
		wp_set_current_user( $this->user( 'distributor' ) );
		$this->assertSame( array( 'bacs', 'stripe' ), array_keys( apply_filters( 'woocommerce_available_payment_gateways', $all ) ) );
	}

	public function test_shipping_per_role_free_threshold_and_retail_hidden() {
		$rates = array(
			'flat_rate:1'     => new WC_Shipping_Rate( 'flat_rate:1', 'Flat', 9, array(), 'flat_rate' ),
			'local_pickup:2'  => new WC_Shipping_Rate( 'local_pickup:2', 'Pickup', 0, array(), 'local_pickup' ),
			'free_shipping:3' => new WC_Shipping_Rate( 'free_shipping:3', 'Free', 0, array(), 'free_shipping' ),
		);
		Settings_Store::update( array( 'retail_hidden_shipping' => array( 'local_pickup' ) ) );
		Settings_Store::flush();
		Roles::save( array_merge( Roles::get( 'distributor' ), array( 'shipping' => array( 'flat_rate', 'local_pickup' ), 'free_shipping' => 500 ) ) );
		$this->assertSame( array( 'flat_rate:1', 'free_shipping:3' ), array_keys( Checkout_Rules::filter_rates( $rates, '' ) ) );
		$this->assertSame( array( 'flat_rate:1', 'local_pickup:2' ), array_keys( Checkout_Rules::filter_rates( $rates, 'distributor', 100 ) ) );
		$this->assertSame( array( 'dev3bq_free', 'flat_rate:1', 'local_pickup:2' ), array_keys( Checkout_Rules::filter_rates( $rates, 'distributor', 600 ) ) );
		$packages = apply_filters( 'woocommerce_cart_shipping_packages', array( array( 'contents' => array() ) ) );
		$this->assertArrayHasKey( 'dev3bq_role', $packages[0], 'Role is part of the package so cached rates vary' );
	}

	public function test_order_records_role() {
		$uid   = $this->user( 'distributor' );
		$order = wc_create_order( array( 'customer_id' => $uid ) );
		do_action( 'woocommerce_checkout_create_order', $order, array() );
		$this->assertSame( 'distributor', $order->get_meta( Checkout_Rules::ORDER_ROLE_META ) );
	}
}
