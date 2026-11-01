<?php
/**
 * REST permissions and the admin + storefront routes.
 *
 * @package Dev3\AiB2bWholesale
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiB2bWholesale\Quotes\Basket;
use Dev3\AiB2bWholesale\Quotes\Quotes;
use Dev3\AiB2bWholesale\Quotes\Service;
use Dev3\AiB2bWholesale\Wholesale\Applications;
use Dev3\AiB2bWholesale\Wholesale\Roles;

class RestTest extends Dev3bq_Base_Case {

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	public function test_admin_routes_need_capability_and_nonce() {
		foreach ( array( 'dashboard', 'quotes', 'roles', 'rules', 'applications', 'customers' ) as $route ) {
			$this->assertSame( 403, $this->rest( 'GET', $route, array(), 'customer' )->get_status(), "customer: {$route}" );
			$this->assertContains( $this->rest( 'GET', $route, array(), '' )->get_status(), array( 401, 403 ), "guest: {$route}" );
			$this->assertSame( 403, $this->rest( 'GET', $route, array(), 'administrator', false )->get_status(), "no nonce: {$route}" );
			$this->assertSame( 200, $this->rest( 'GET', $route, array(), 'shop_manager' )->get_status(), "shop manager: {$route}" );
		}
		$this->assertSame( 403, $this->rest( 'POST', 'quotes/1/ai-draft', array(), 'customer' )->get_status() );
	}

	public function test_admin_quote_editor_flow() {
		$mug = $this->product( 'Kraft mug', '10' );
		$uid = $this->user( 'distributor' );
		$res = $this->rest( 'POST', 'quotes', array( 'user_id' => $uid, 'request' => '50 mugs please' ) );
		$this->assertSame( 201, $res->get_status() );
		$id = $res->get_data()['id'];
		$this->assertSame( 'draft', $res->get_data()['status'] );
		$res = $this->rest(
			'POST',
			"quotes/{$id}",
			array(
				'items'    => array( array( 'product_id' => $mug->get_id(), 'qty' => 50, 'unit_price' => '' ) ),
				'shipping' => 12,
				'message'  => 'Our best offer.',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 8.0, $data['items'][0]['unit_price'], 'Blank price = distributor default discount' );
		$this->assertSame( 412.0, $data['total'] );
		$this->assertStringContainsString( 'dev3bq_pdf', $data['pdf_url'] );
		$this->assertStringNotContainsString( '&amp;', $data['pdf_url'] );
		$sent = $this->rest( 'POST', "quotes/{$id}/send", array( 'message' => 'Our best offer.' ) );
		$this->assertSame( 'sent', $sent->get_data()['status'] );
		$this->assertSame( 409, $this->rest( 'POST', "quotes/{$id}/status", array( 'status' => 'accepted' ) )->get_status() );
		$order = $this->rest( 'POST', "quotes/{$id}/order" );
		$this->assertSame( 'accepted', $order->get_data()['status'] );
		$this->assertNotEmpty( $order->get_data()['order']['pay_url'] );
		$this->assertSame( 409, $this->rest( 'POST', "quotes/{$id}", array( 'shipping' => 1 ) )->get_status(), 'Accepted quotes are locked' );
		$dup = $this->rest( 'POST', "quotes/{$id}/duplicate" );
		$this->assertSame( 201, $dup->get_status() );
		$list = $this->rest( 'GET', 'quotes', array( 'status' => 'open' ) )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 1, $list['counts']['accepted'] );
	}

	public function test_roles_rules_preview_and_customers_routes() {
		$res = $this->rest( 'POST', 'roles', array( 'name' => 'Gold', 'discount' => 25, 'tax_exempt' => true ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertContains( 'gold', wp_list_pluck( $res->get_data()['roles'], 'key' ) );
		$this->assertSame( 409, $this->rest( 'POST', 'roles', array( 'name' => 'Gold', 'key' => 'gold' ) )->get_status() );
		$mug  = $this->product( 'Kraft mug', '10' );
		$rule = $this->rest( 'POST', 'rules', array( 'name' => 'Gold mugs', 'role' => 'gold', 'scope' => 'products', 'targets' => array( $mug->get_id() ), 'type' => 'price', 'amount' => 6.5 ) );
		$this->assertSame( 200, $rule->get_status() );
		$this->assertSame( 'Kraft mug', ( (array) $rule->get_data()['names'] )[ 'p' . $mug->get_id() ] );
		$prev = $this->rest( 'POST', 'price-preview', array( 'product_id' => $mug->get_id(), 'role' => 'gold', 'qty' => 4 ) )->get_data();
		$this->assertSame( 6.5, $prev['price'] );
		$this->assertSame( 26.0, $prev['total'] );
		$this->assertSame( 'Gold mugs', $prev['rule_name'] );
		$uid = $this->user( 'customer' );
		$cus = $this->rest( 'POST', "customers/{$uid}", array( 'role' => 'gold', 'tax_exempt' => 'no' ) );
		$this->assertSame( 'gold', $cus->get_data()['role'] );
		$this->assertFalse( $cus->get_data()['exempt_now'] );
		$this->assertSame( 1, $this->rest( 'GET', 'customers', array( 'role' => 'gold' ) )->get_data()['total'] );
		$this->assertSame( 200, $this->rest( 'DELETE', 'roles/gold' )->get_status() );
		$this->assertSame( '', Roles::user_role( $uid ) );
	}

	public function test_setup_page_creates_a_page_with_the_shortcode() {
		$res = $this->rest( 'POST', 'setup-page', array( 'kind' => 'quick_order' ) );
		$this->assertSame( 201, $res->get_status() );
		$this->assertStringContainsString( '[dev3bq_quick_order]', get_post( $res->get_data()['id'] )->post_content );
		$this->assertSame( 400, $this->rest( 'POST', 'setup-page', array( 'kind' => 'evil' ) )->get_status() );
	}

	public function test_public_products_respect_role_prices_and_visibility() {
		\Dev3\AiB2bWholesale\Pricing\Rules::save( array( 'role' => 'distributor', 'type' => 'percent', 'amount' => 20, 'breaks' => array( array( 'min' => 50, 'type' => 'percent', 'amount' => 30 ) ) ) );
		$this->product( 'Kraft mug', '10', array( 'sku' => 'MUG-K' ) );
		$this->product( 'Pallet', '900', array( 'meta' => array( '_dev3bq_visibility' => 'wholesale' ) ) );
		$guest = $this->rest( 'GET', 'public/products', array(), '' )->get_data();
		$this->assertSame( array( 'Kraft mug' ), wp_list_pluck( $guest['rows'], 'name' ) );
		$this->assertSame( '$10.00', $guest['rows'][0]['price'], 'Entities decoded for the storefront' );
		$this->rest( 'GET', 'public/products', array(), 'distributor' );
		$dist = $this->rest( 'GET', 'public/products', array( 'search' => 'MUG-K' ), null )->get_data();
		$this->assertSame( '$8.00', $dist['rows'][0]['price'] );
		$this->assertCount( 2, $dist['rows'][0]['breaks'] );
		$all = $this->rest( 'GET', 'public/products', array(), null )->get_data();
		$this->assertCount( 2, $all['rows'], 'Wholesale buyers see wholesale-only products' );
	}

	public function test_public_cart_basket_quote_view_and_accept() {
		$mug = $this->product( 'Kraft mug', '10' );
		wp_set_current_user( 0 );
		$this->cart();
		$cart = $this->rest( 'POST', 'public/cart', array( 'lines' => array( array( 'product_id' => $mug->get_id(), 'qty' => 4 ) ) ), '' )->get_data();
		$this->assertSame( 1, $cart['added'] );
		$this->assertSame( 4, $cart['count'] );
		$basket = $this->rest( 'POST', 'public/basket/from-cart', array(), '' )->get_data();
		$this->assertSame( 4, $basket['count'] );
		$bad = $this->rest( 'POST', 'public/quote', array( 'email' => 'nope' ), '' );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertArrayHasKey( 'email', $bad->get_data()['fields'] );
		$this->assertArrayHasKey( 'name', $bad->get_data()['fields'] );
		$ok = $this->rest( 'POST', 'public/quote', array( 'email' => 'g@guest.test', 'name' => 'Gina', 'request' => 'Gift boxes?' ), '' );
		$this->assertSame( 201, $ok->get_status() );
		$number = $ok->get_data()['number'];
		$this->assertSame( 0, Basket::view()['count'], 'Basket cleared after submit' );
		$quote = Quotes::get_by_number( $number );
		$this->assertSame( 404, $this->rest( 'GET', "public/quotes/{$number}", array( 'key' => 'nope' ), '' )->get_status() );
		$view = $this->rest( 'GET', "public/quotes/{$number}", array( 'key' => $quote['access_key'] ), '' )->get_data();
		$this->assertSame( '', $view['items'][0]['unit'], 'No prices before the store sends the quote' );
		$this->assertFalse( $view['can_accept'] );
		Service::send( $quote['id'] );
		$view = $this->rest( 'GET', "public/quotes/{$number}", array( 'key' => $quote['access_key'] ), '' )->get_data();
		$this->assertTrue( $view['can_accept'] );
		$this->assertStringContainsString( 'key=', $view['pdf_url'] );
		$msg = $this->rest( 'POST', "public/quotes/{$number}/message", array( 'key' => $quote['access_key'], 'message' => 'Can we pick up?' ), '' );
		$this->assertSame( 200, $msg->get_status() );
		$acc = $this->rest( 'POST', "public/quotes/{$number}/accept", array( 'key' => $quote['access_key'] ), '' )->get_data();
		$this->assertSame( 'accepted', $acc['status'] );
		$this->assertNotEmpty( $acc['pay_url'] );
		$this->assertSame( 409, $this->rest( 'POST', "public/quotes/{$number}/accept", array( 'key' => $quote['access_key'] ), '' )->get_status() );
	}

	public function test_public_cross_origin_and_rate_limit() {
		$req = new WP_REST_Request( 'GET', '/dev3bq/v1/public/basket' );
		$req->set_header( 'Origin', 'https://evil.test' );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
		add_filter( 'dev3bq_public_rate_limit', static fn() => 2 );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$codes = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$codes[] = $this->rest( 'POST', 'public/register', array( 'hp' => 'bot' ), '' )->get_status();
		}
		$this->assertSame( 429, end( $codes ) );
	}

	public function test_public_register_and_honeypot() {
		$hp = $this->rest( 'POST', 'public/register', array( 'hp' => 'spam', 'email' => 'bot@x.test', 'company' => 'Bot' ), '' );
		$this->assertSame( 200, $hp->get_status() );
		$this->assertSame( 0, Applications::query()['total'], 'Honeypot hits are dropped silently' );
		$res = $this->rest( 'POST', 'public/register', array( 'first_name' => 'Ola', 'email' => 'ola@shop.test', 'company' => 'Ola AS' ), '' );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'pending', $res->get_data()['status'] );
		$bad = $this->rest( 'POST', 'public/register', array( 'email' => 'x' ), '' );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertArrayHasKey( 'company', $bad->get_data()['fields'] );
	}
}
