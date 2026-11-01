<?php
/**
 * Pricing engine, rules, product-level prices, cart prices and storefront display.
 *
 * @package Dev3\AiB2bWholesale
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Pricing\Product_Meta;
use Dev3\AiB2bWholesale\Pricing\Rules;
use Dev3\AiB2bWholesale\Settings;
use Dev3\AiB2bWholesale\Core3\Core\Settings_Store;
use Dev3\AiB2bWholesale\Wholesale\Roles;
use Dev3\AiB2bWholesale\Woo\Storefront_Pricing;

class PricingTest extends Dev3bq_Base_Case {

	private function distributor_rule( array $extra = array() ) {
		return Rules::save(
			array_merge(
				array(
					'name'   => 'Distributor',
					'role'   => 'distributor',
					'scope'  => 'all',
					'type'   => 'percent',
					'amount' => 20,
					'breaks' => array(
						array( 'min' => 50, 'type' => 'percent', 'amount' => 25 ),
						array( 'min' => 100, 'type' => 'price', 'amount' => 6 ),
					),
				),
				$extra
			)
		);
	}

	public function test_seed_roles_exist_as_wp_roles() {
		$this->assertNotNull( Roles::get( 'wholesale' ) );
		$this->assertNotNull( Roles::get( 'distributor' ) );
		$this->assertNotNull( get_role( 'dev3bq_distributor' ) );
		$this->assertTrue( get_role( 'dev3bq_distributor' )->has_cap( Roles::CAP ) );
		$uid = $this->user( 'distributor' );
		$this->assertSame( 'distributor', Roles::user_role( $uid ) );
		$this->assertSame( '', Roles::user_role( $this->user( 'customer' ) ) );
	}

	public function test_acceptance_rule_with_quantity_breaks() {
		$this->distributor_rule();
		$p = $this->product( 'Kraft mug', '10' );
		$this->assertSame( 8.0, Engine::price( $p, 'distributor', 1 )['price'] );
		$this->assertSame( 7.5, Engine::price( $p, 'distributor', 60 )['price'] );
		$this->assertSame( 50, Engine::price( $p, 'distributor', 60 )['break'] );
		$this->assertSame( 6.0, Engine::price( $p, 'distributor', 120 )['price'] );
		$this->assertSame( 'rule', Engine::price( $p, 'distributor', 1 )['source'] );
		$this->assertCount( 3, Engine::price( $p, 'distributor', 1 )['breaks'] );
		// Retail is untouched; wholesale gets its own default discount.
		$this->assertSame( 10.0, Engine::price( $p, '', 1 )['price'] );
		$this->assertSame( 'none', Engine::price( $p, '', 1 )['source'] );
		$this->assertSame( 9.0, Engine::price( $p, 'wholesale', 1 )['price'] );
		$this->assertSame( 'role', Engine::price( $p, 'wholesale', 1 )['source'] );
	}

	public function test_product_level_price_beats_rules() {
		$this->distributor_rule();
		$p = $this->product(
			'Kraft mug',
			'10',
			array(
				'meta' => array(
					Engine::META_PRICES => array( 'distributor' => 7 ),
					Engine::META_BREAKS => array( 'distributor' => array( array( 'min' => 200, 'price' => 5 ) ) ),
				),
			)
		);
		$this->assertSame( 7.0, Engine::price( $p, 'distributor', 1 )['price'] );
		$this->assertSame( 'product', Engine::price( $p, 'distributor', 1 )['source'] );
		$this->assertSame( 7.0, Engine::price( $p, 'distributor', 150 )['price'], 'Product price ignores rule breaks' );
		$this->assertSame( 5.0, Engine::price( $p, 'distributor', 200 )['price'] );
	}

	public function test_priority_scope_dates_and_everyone_rules() {
		$cat  = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Mugs' ) );
		$sub  = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Kraft', 'parent' => $cat ) );
		$mug  = $this->product( 'Kraft mug', '10', array( 'cats' => array( $sub ) ) );
		$note = $this->product( 'Notebook', '4' );
		Rules::save( array( 'name' => 'Mugs', 'role' => 'all_wholesale', 'scope' => 'categories', 'targets' => array( $cat ), 'type' => 'fixed', 'amount' => 3, 'priority' => 20 ) );
		Rules::save( array( 'name' => 'All', 'role' => 'all_wholesale', 'scope' => 'all', 'type' => 'percent', 'amount' => 50, 'priority' => 10 ) );
		$this->assertSame( 7.0, Engine::price( $mug, 'distributor', 1 )['price'], 'Higher priority category rule wins (child category counts)' );
		$this->assertSame( 2.0, Engine::price( $note, 'distributor', 1 )['price'] );
		Rules::save( array( 'name' => 'Everyone 5+', 'role' => 'everyone', 'scope' => 'products', 'targets' => array( $note->get_id() ), 'type' => 'percent', 'amount' => 0, 'breaks' => array( array( 'min' => 5, 'type' => 'percent', 'amount' => 10 ) ) ) );
		Engine::flush();
		$this->assertSame( 4.0, Engine::price( $note, '', 1 )['price'] );
		$this->assertSame( 3.6, Engine::price( $note, '', 5 )['price'], 'Tiered pricing for retail too' );
		Rules::save( array( 'name' => 'Future', 'role' => 'everyone', 'scope' => 'all', 'type' => 'percent', 'amount' => 90, 'priority' => 99, 'starts_at' => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) ) );
		Engine::flush();
		$this->assertSame( 4.0, Engine::price( $note, '', 1 )['price'], 'Rules that have not started are ignored' );
	}

	public function test_rule_validation() {
		$this->assertWPError( Rules::save( array( 'role' => 'nobody' ) ) );
		$this->assertWPError( Rules::save( array( 'role' => 'wholesale', 'type' => 'percent', 'amount' => 150 ) ) );
		$this->assertWPError( Rules::save( array( 'role' => 'wholesale', 'scope' => 'products', 'targets' => array() ) ) );
		$rule = Rules::save( array( 'role' => 'wholesale', 'breaks' => array( array( 'min' => 1, 'amount' => 5 ), array( 'min' => 20, 'amount' => 5 ), array( 'min' => 10, 'amount' => 4 ) ) ) );
		$this->assertSame( array( 10, 20 ), wp_list_pluck( $rule['breaks'], 'min' ), 'Breaks below 2 dropped and sorted' );
	}

	public function test_sale_price_is_the_base_and_variations_inherit_parent_prices() {
		$p = $this->product( 'Sale mug', '10', array( 'sale' => '9' ) );
		$this->assertSame( 8.1, Engine::price( $p, 'wholesale', 1 )['price'] );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Tee' );
		$parent->set_status( 'publish' );
		$parent->save();
		$var = new WC_Product_Variation();
		$var->set_parent_id( $parent->get_id() );
		$var->set_regular_price( '20' );
		$var->save();
		update_post_meta( $parent->get_id(), Engine::META_PRICES, array( 'wholesale' => 12 ) );
		$this->assertSame( 12.0, Engine::price( wc_get_product( $var->get_id() ), 'wholesale', 1 )['price'] );
		update_post_meta( $var->get_id(), Engine::META_PRICES, array( 'wholesale' => 11 ) );
		Engine::flush();
		$this->assertSame( 11.0, Engine::price( wc_get_product( $var->get_id() ), 'wholesale', 1 )['price'] );
	}

	public function test_product_meta_cleaners() {
		$this->assertSame(
			array( array( 'min' => 50, 'price' => 7.5 ), array( 'min' => 100, 'price' => 6.0 ) ),
			Product_Meta::clean_breaks( '100:6, 50:7.50, 1:9, junk' )
		);
		$this->assertSame( array( 'distributor' => 7.25 ), Product_Meta::clean_prices( array( 'distributor' => '7.25', 'nobody' => '3', 'wholesale' => '' ) ) );
	}

	public function test_cart_prices_follow_quantity_breaks() {
		$this->distributor_rule();
		$p = $this->product( 'Kraft mug', '10' );
		wp_set_current_user( $this->user( 'distributor' ) );
		$cart = $this->cart();
		$key  = $cart->add_to_cart( $p->get_id(), 60 );
		$this->assertNotEmpty( $key );
		$cart->calculate_totals();
		$this->assertSame( 450.0, (float) $cart->get_subtotal() );
		$cart->set_quantity( $key, 120 );
		$cart->calculate_totals();
		$this->assertSame( 720.0, (float) $cart->get_subtotal() );
		$cart->calculate_totals();
		$this->assertSame( 720.0, (float) $cart->get_subtotal(), 'Recalculating never stacks discounts' );
	}

	public function test_break_count_by_parent_combines_variations() {
		Rules::save( array( 'role' => 'wholesale', 'scope' => 'all', 'type' => 'percent', 'amount' => 0, 'breaks' => array( array( 'min' => 10, 'type' => 'percent', 'amount' => 50 ) ) ) );
		Settings_Store::update( array( 'break_count' => 'parent' ) );
		Settings_Store::flush();
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Tee' );
		$parent->set_status( 'publish' );
		$attr = new WC_Product_Attribute();
		$attr->set_name( 'size' );
		$attr->set_options( array( 'S', 'M' ) );
		$attr->set_variation( true );
		$attr->set_visible( true );
		$parent->set_attributes( array( $attr ) );
		$parent->save();
		$ids = array();
		foreach ( array( 'S', 'M' ) as $size ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $parent->get_id() );
			$v->set_regular_price( '10' );
			$v->set_attributes( array( 'size' => $size ) );
			$v->save();
			$ids[ $size ] = $v->get_id();
		}
		wp_set_current_user( $this->user( 'wholesale' ) );
		$cart = $this->cart();
		$cart->add_to_cart( $parent->get_id(), 5, $ids['S'], array( 'attribute_size' => 'S' ) );
		$cart->add_to_cart( $parent->get_id(), 5, $ids['M'], array( 'attribute_size' => 'M' ) );
		$cart->calculate_totals();
		$this->assertSame( 50.0, (float) $cart->get_subtotal(), '5 + 5 of one product reach the 10+ tier' );
	}

	public function test_price_html_label_and_guest_hiding() {
		$this->distributor_rule();
		$p = $this->product( 'Kraft mug', '10' );
		wp_set_current_user( $this->user( 'distributor' ) );
		$html = $p->get_price_html();
		$this->assertStringContainsString( 'dev3bq-price-label', $html );
		$this->assertStringContainsString( '8.00', $html );
		$this->assertStringContainsString( '<del', $html, 'Retail price struck through' );
		wp_set_current_user( 0 );
		Settings_Store::update( array( 'guest_prices' => 'hide' ) );
		Settings_Store::flush();
		$guest = wc_get_product( $p->get_id() );
		$this->assertStringContainsString( 'Log in to see prices', $guest->get_price_html() );
		$this->assertFalse( $guest->is_purchasable() );
		$this->assertStringContainsString( 'Buy more', Storefront_Pricing::break_table_html( $p, 'distributor' ) );
	}

	public function test_wholesale_only_products_are_hidden_from_retail() {
		$hidden  = $this->product( 'Pallet of mugs', '500', array( 'meta' => array( Engine::META_VISIBILITY => 'wholesale' ) ) );
		$visible = $this->product( 'Single mug', '10' );
		wp_set_current_user( 0 );
		$this->assertFalse( wc_get_product( $hidden->get_id() )->is_purchasable() );
		$this->assertFalse( Engine::visible_for( $hidden, '' ) );
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'fields'           => 'ids',
				'suppress_filters' => false,
				'posts_per_page'   => -1,
			)
		);
		$this->assertContains( $visible->get_id(), $ids );
		$this->assertNotContains( $hidden->get_id(), $ids, 'Front-end product queries hide wholesale-only products' );
		wp_set_current_user( $this->user( 'wholesale' ) );
		$this->assertTrue( wc_get_product( $hidden->get_id() )->is_purchasable() );
		$ids = get_posts( array( 'post_type' => 'product', 'fields' => 'ids', 'suppress_filters' => false, 'posts_per_page' => -1 ) );
		$this->assertContains( $hidden->get_id(), $ids );
	}

	public function test_qty_rules_and_input_args() {
		$p = $this->product( 'Carton mug', '10', array( 'meta' => array( Engine::META_MIN_QTY => 12, Engine::META_STEP => 6 ) ) );
		$this->assertSame( array( 'min' => 1, 'step' => 1 ), Engine::qty_rules( $p, '' ) );
		$this->assertSame( array( 'min' => 12, 'step' => 6 ), Engine::qty_rules( $p, 'wholesale' ) );
		wp_set_current_user( $this->user( 'wholesale' ) );
		$args = apply_filters( 'woocommerce_quantity_input_args', array( 'input_value' => 1, 'min_value' => 1, 'step' => 1 ), $p );
		$this->assertSame( 12, $args['min_value'] );
		$this->assertSame( 6, $args['step'] );
	}

	public function test_role_save_and_delete_moves_users_to_customer() {
		$role = Roles::save( array( 'name' => 'Gold Partner', 'discount' => 30, 'gateways' => array( 'bacs' ) ) );
		$this->assertSame( 'gold_partner', $role['key'] );
		$this->assertNotNull( get_role( 'dev3bq_gold_partner' ) );
		$uid = $this->user( 'gold_partner' );
		$this->assertSame( 'gold_partner', Roles::user_role( $uid ) );
		$this->assertTrue( Roles::delete( 'gold_partner' ) );
		$this->assertNull( get_role( 'dev3bq_gold_partner' ) );
		$this->assertContains( 'customer', get_userdata( $uid )->roles );
		$this->assertWPError( Roles::save( array( 'name' => '', 'key' => 'everyone' ) ) );
	}
}
