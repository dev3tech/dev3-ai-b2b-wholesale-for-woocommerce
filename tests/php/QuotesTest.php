<?php
/**
 * Quote lifecycle: basket → request, pricing, send with PDF, accept → order (HPOS and legacy), cron.
 *
 * @package Dev3\AiB2bWholesale
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiB2bWholesale\Core3\Core\Settings_Store;
use Dev3\AiB2bWholesale\Pricing\Rules;
use Dev3\AiB2bWholesale\Quotes\Basket;
use Dev3\AiB2bWholesale\Quotes\Pdf;
use Dev3\AiB2bWholesale\Quotes\Pdf_Quote;
use Dev3\AiB2bWholesale\Quotes\Quotes;
use Dev3\AiB2bWholesale\Quotes\Service;

class QuotesTest extends Dev3bq_Base_Case {

	private function quote( array $data = array(), array $lines = array() ) {
		$id = Quotes::create( array_merge( array( 'email' => 'anna@buyer.test', 'name' => 'Anna Buyer', 'company' => 'Anna GmbH' ), $data ), $lines );
		$this->assertIsInt( $id );
		return $id;
	}

	public function test_create_numbers_prices_and_totals() {
		Rules::save( array( 'role' => 'distributor', 'type' => 'percent', 'amount' => 20, 'breaks' => array( array( 'min' => 100, 'type' => 'price', 'amount' => 6 ) ) ) );
		$mug  = $this->product( 'Kraft mug', '10', array( 'sku' => 'MUG-1' ) );
		$uid  = $this->user( 'distributor' );
		$id   = $this->quote( array( 'user_id' => $uid ), array( array( 'product_id' => $mug->get_id(), 'qty' => 120 ), array( 'name' => 'Custom print', 'qty' => 1, 'unit_price' => 50 ) ) );
		$q    = Quotes::get( $id );
		$this->assertSame( 'Q-0' . ( 1000 + $id ), $q['number'] );
		$this->assertSame( 'distributor', $q['role'] );
		$this->assertSame( 32, strlen( $q['access_key'] ) );
		$items = Quotes::items( $id );
		$this->assertSame( 6.0, $items[0]['unit_price'], 'Rule price at the quoted quantity' );
		$this->assertSame( 'MUG-1', $items[0]['sku'] );
		$this->assertSame( 50.0, $items[1]['unit_price'] );
		$this->assertSame( 770.0, $q['subtotal'] );
		Quotes::update( $id, array( 'shipping' => 30, 'discount' => 20 ) );
		$q = Quotes::get( $id );
		$this->assertSame( 780.0, $q['total'] );
		$this->assertWPError( Quotes::create( array( 'email' => 'nope' ) ) );
	}

	public function test_estimated_tax_and_exempt_mode() {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate' => '10.0000', 'tax_rate_name' => 'Tax', 'tax_rate_priority' => '1', 'tax_rate_order' => '1', 'tax_rate_class' => '' ) );
		$mug = $this->product( 'Kraft mug', '10' );
		$id  = $this->quote( array(), array( array( 'product_id' => $mug->get_id(), 'qty' => 10 ) ) );
		$this->assertSame( 10.0, Quotes::get( $id )['tax'] );
		$this->assertSame( 110.0, Quotes::get( $id )['total'] );
		Quotes::update( $id, array( 'tax_mode' => 'exempt' ) );
		$this->assertSame( 0.0, Quotes::get( $id )['tax'] );
	}

	public function test_basket_from_cart_and_submit_request() {
		$mug  = $this->product( 'Kraft mug', '10' );
		$book = $this->product( 'A5 notebook', '4' );
		$cart = $this->cart();
		$cart->add_to_cart( $mug->get_id(), 3 );
		$view = Basket::from_cart();
		$this->assertSame( 3, $view['count'] );
		Basket::add( $book->get_id(), 0, 5 );
		Basket::add( $book->get_id(), 0, 5 );
		$this->assertSame( 13, Basket::view()['count'], 'Same product adds up' );
		$this->assertWPError( Basket::add( 999999 ) );
		$id = Service::submit_request( array( 'email' => 'shop@buyer.test', 'name' => 'Sam', 'request' => 'Also need lids', 'source' => 'cart' ), Basket::lines() );
		$this->assertIsInt( $id );
		$q = Quotes::get( $id );
		$this->assertSame( 'requested', $q['status'] );
		$this->assertCount( 2, Quotes::items( $id ) );
		$subjects = wp_list_pluck( $this->mails(), 'subject' );
		$this->assertTrue( (bool) preg_grep( '/New quote request/', $subjects ), 'Store notified' );
		$this->assertTrue( (bool) preg_grep( '/received your quote request/', $subjects ), 'Customer confirmation' );
		$this->assertWPError( Service::submit_request( array( 'email' => 'a@b.test' ), array() ) );
	}

	public function test_pdf_is_a_valid_document() {
		$mug = $this->product( 'Kraft mug with a very long name that needs wrapping across more than one line in the PDF table', '10', array( 'sku' => 'MUG' ) );
		$lines = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$lines[] = array( 'product_id' => $mug->get_id(), 'qty' => $i + 1, 'note' => 'Line ' . $i );
		}
		$id  = $this->quote( array( 'name' => 'Zoë Ünal' ), $lines );
		$pdf = Pdf_Quote::render( Quotes::get( $id ) );
		$this->assertStringStartsWith( '%PDF-1.4', $pdf );
		$this->assertStringContainsString( '%%EOF', $pdf );
		$this->assertGreaterThanOrEqual( 2, substr_count( $pdf, '/Type /Page ' ), '40 lines need more than one page' );
		$this->assertSame( 2, count( Pdf::wrap( 'alpha beta gamma delta', Pdf::width( 'alpha beta gamma', 10 ), 10 ) ) );
		$this->assertTrue( Pdf::encodable( '€ 12,50' ) );
		$this->assertFalse( Pdf::encodable( '₹' ) );
	}

	public function test_send_emails_pdf_and_customer_accepts_into_order_on_both_storages() {
		global $wpdb;
		$has_hpos = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders' ) );
		$this->assertTrue( $has_hpos );
		foreach ( array( 'yes', 'no' ) as $mode ) {
			update_option( 'woocommerce_custom_orders_table_enabled', $mode );
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
			$this->assertSame( 'yes' === $mode, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
			reset_phpmailer_instance();
			$mug = $this->product( 'Kraft mug', '10' );
			$uid = $this->user( 'distributor', array( 'billing_country' => 'DE', 'billing_city' => 'Berlin' ) );
			$id  = $this->quote( array( 'user_id' => $uid ), array( array( 'product_id' => $mug->get_id(), 'qty' => 50, 'unit_price' => 7.25 ) ) );
			Quotes::update( $id, array( 'shipping' => 25, 'discount' => 12.5 ) );
			$sent = Service::send( $id, 'Hi Anna, here is our offer.' );
			$this->assertSame( 'sent', $sent['status'] );
			$this->assertNotEmpty( $sent['sent_at'] );
			$mail = null;
			foreach ( $this->mails() as $m ) {
				if ( false !== strpos( $m['subject'], 'Your quote' ) ) {
					$mail = $m;
				}
			}
			$this->assertNotNull( $mail, "Quote email sent ({$mode})" );
			$this->assertStringContainsString( 'here is our offer', $mail['body'] );
			$attachments = tests_retrieve_phpmailer_instance()->getAttachments();
			$this->assertNotEmpty( $attachments, 'PDF attached' );
			$this->assertStringEndsWith( '.pdf', $attachments[0][2] );

			// Rules changing later must not change the accepted prices.
			Rules::save( array( 'role' => 'distributor', 'type' => 'price', 'amount' => 1 ) );
			$order = Service::accept( $id, 'customer' );
			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( 'pending', $order->get_status() );
			$this->assertEquals( 362.5, (float) $order->get_subtotal() );
			$this->assertEquals( 362.5 + 25 - 12.5, (float) $order->get_total() );
			$this->assertSame( (string) $id, (string) $order->get_meta( Service::ORDER_META ) );
			$this->assertSame( 'distributor', $order->get_meta( '_dev3bq_role' ) );
			$this->assertSame( 'Berlin', $order->get_billing_city() );
			$this->assertStringContainsString( 'order-pay', $order->get_checkout_payment_url() );
			if ( 'yes' === $mode ) {
				$this->assertSame( (string) $order->get_id(), (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order->get_id() ) ) ); // phpcs:ignore
			} else {
				$this->assertSame( 'shop_order', get_post_type( $order->get_id() ) );
			}
			$q = Quotes::get( $id );
			$this->assertSame( 'accepted', $q['status'] );
			$this->assertSame( $order->get_id(), $q['order_id'] );
			$this->assertWPError( Service::accept( $id, 'customer' ), 'Second accept fails' );
			$this->assertCount( 1, wc_get_orders( array( 'meta_key' => Service::ORDER_META, 'meta_value' => $id, 'return' => 'ids' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( wc_get_orders( array( 'limit' => -1, 'status' => array_keys( wc_get_order_statuses() ) ) ) as $o ) {
				$o->delete( true );
			}
			Rules::flush();
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3bq_rules" ); // phpcs:ignore
			Rules::flush();
			\Dev3\AiB2bWholesale\Pricing\Engine::flush();
		}
	}

	public function test_customer_cannot_accept_unsent_or_expired_quotes_and_can_decline() {
		$mug = $this->product( 'Kraft mug', '10' );
		$id  = $this->quote( array(), array( array( 'product_id' => $mug->get_id(), 'qty' => 2 ) ) );
		$this->assertWPError( Service::accept( $id, 'customer' ), 'Requested quotes are not priced yet' );
		Service::send( $id );
		Quotes::update( $id, array( 'valid_until' => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) ) );
		$res = Service::accept( $id, 'customer' );
		$this->assertWPError( $res );
		$this->assertSame( 'dev3bq_quote_expired', $res->get_error_code() );
		$this->assertSame( 'expired', Quotes::get( $id )['status'] );

		$id2 = $this->quote( array(), array( array( 'product_id' => $mug->get_id(), 'qty' => 2 ) ) );
		Service::send( $id2 );
		$this->assertTrue( Service::decline( $id2, 'Too expensive' ) );
		$this->assertSame( 'declined', Quotes::get( $id2 )['status'] );
		$this->assertWPError( Service::decline( $id2 ) );
		$this->assertStringContainsString( 'Too expensive', implode( ' ', wp_list_pluck( Quotes::events( $id2, true ), 'message' ) ) );
	}

	public function test_admin_can_convert_a_draft_to_an_order() {
		$mug = $this->product( 'Kraft mug', '10' );
		$id  = $this->quote( array( 'status' => 'draft' ), array( array( 'product_id' => $mug->get_id(), 'qty' => 3 ) ) );
		$order = Service::accept( $id, 'admin' );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertEquals( 30.0, (float) $order->get_total() );
		$this->assertSame( 'dev3bq_quote', $order->get_created_via() );
	}

	public function test_daily_cron_sends_one_reminder_and_expires() {
		Settings_Store::update( array( 'followup_days' => 3 ) );
		Settings_Store::flush();
		$mug = $this->product( 'Kraft mug', '10' );
		$a   = $this->quote( array(), array( array( 'product_id' => $mug->get_id(), 'qty' => 1 ) ) );
		$b   = $this->quote( array(), array( array( 'product_id' => $mug->get_id(), 'qty' => 1 ) ) );
		Service::send( $a );
		Service::send( $b );
		Quotes::update( $a, array( 'sent_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ) );
		Quotes::update( $b, array( 'valid_until' => gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) ) );
		reset_phpmailer_instance();
		$res = Service::daily();
		$this->assertSame( array( 'reminded' => 1, 'expired' => 1 ), $res );
		$this->assertSame( 1, Quotes::get( $a )['followup_sent'] );
		$this->assertSame( 'expired', Quotes::get( $b )['status'] );
		$subjects = implode( ' | ', wp_list_pluck( $this->mails(), 'subject' ) );
		$this->assertStringContainsString( 'reminder', $subjects );
		$this->assertStringContainsString( 'expired', $subjects );
		$this->assertSame( array( 'reminded' => 0, 'expired' => 0 ), Service::daily(), 'Only once' );
	}

	public function test_messages_duplicate_and_reprice() {
		Rules::save( array( 'role' => 'wholesale', 'type' => 'percent', 'amount' => 10 ) );
		$mug = $this->product( 'Kraft mug', '10' );
		$id  = $this->quote( array( 'role' => 'wholesale' ), array( array( 'product_id' => $mug->get_id(), 'qty' => 1, 'unit_price' => 5 ) ) );
		$this->assertSame( 5.0, Quotes::items( $id )[0]['unit_price'] );
		Quotes::reprice( $id );
		$this->assertSame( 9.0, Quotes::items( $id )[0]['unit_price'] );
		reset_phpmailer_instance();
		$this->assertTrue( Service::message( $id, 'Can you do 8?', 'customer' ) );
		$this->assertTrue( Service::message( $id, 'Yes, 8.50 is fine.', 'admin' ) );
		$this->assertWPError( Service::message( $id, '   ', 'admin' ) );
		$this->assertCount( 2, $this->mails() );
		$copy = Service::duplicate( $id );
		$this->assertSame( 'draft', Quotes::get( $copy )['status'] );
		$this->assertCount( 1, Quotes::items( $copy ) );
		$this->assertNotSame( Quotes::get( $id )['number'], Quotes::get( $copy )['number'] );
	}

	public function test_access_rules() {
		$id    = $this->quote();
		$quote = Quotes::get( $id );
		wp_set_current_user( 0 );
		$this->assertTrue( Service::can_view( $quote, $quote['access_key'] ) );
		$this->assertFalse( Service::can_view( $quote, 'wrong' ) );
		$this->assertFalse( Service::can_view( $quote, '' ) );
		$owner = $this->user( 'customer' );
		$id2   = $this->quote( array( 'user_id' => $owner ) );
		wp_set_current_user( $owner );
		$this->assertTrue( Service::can_view( Quotes::get( $id2 ), '' ) );
		wp_set_current_user( $this->user( 'customer' ) );
		$this->assertFalse( Service::can_view( Quotes::get( $id2 ), '' ) );
	}
}
