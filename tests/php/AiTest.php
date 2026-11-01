<?php
/**
 * DEV3 AI features with mocked Gemini HTTP: quote drafts, emails, screening, shopper list builder.
 *
 * @package Dev3\AiB2bWholesale
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiB2bWholesale\Ai\Assistant;
use Dev3\AiB2bWholesale\Ai\Matcher;
use Dev3\AiB2bWholesale\Core3\Core\Settings_Store;
use Dev3\AiB2bWholesale\Pricing\Rules;
use Dev3\AiB2bWholesale\Quotes\Quotes;
use Dev3\AiB2bWholesale\Quotes\Service;
use Dev3\AiB2bWholesale\Wholesale\Applications;

class AiTest extends Dev3bq_Base_Case {

	/**
	 * Products.
	 *
	 * @var array
	 */
	private $p = array();

	public function set_up() {
		parent::set_up();
		\Dev3\AiB2bWholesale\Ai\Prompts_Library::register();
		$this->p['mug']  = $this->product( 'Kraft mug 12oz', '10', array( 'sku' => 'MUG-KRAFT-12' ) );
		$this->p['book'] = $this->product( 'A5 notebook dotted', '4', array( 'sku' => 'NB-A5' ) );
		$this->p['pen']  = $this->product( 'Bamboo pen', '1.5', array( 'sku' => 'PEN-B' ) );
		Rules::save( array( 'role' => 'distributor', 'type' => 'percent', 'amount' => 20, 'breaks' => array( array( 'min' => 100, 'type' => 'price', 'amount' => 6 ) ) ) );
	}

	public function test_matcher_tokens_split_and_parse_without_ai() {
		$this->assertContains( 'mug', Matcher::tokens( 'Please quote 120 kraft mugs' ) );
		$this->assertNotContains( 'please', Matcher::tokens( 'Please quote 120 kraft mugs' ) );
		$split = Matcher::split( "120 kraft mugs\nNB-A5 x 30, bamboo pen: 200" );
		$this->assertSame( array( 120, 30, 200 ), wp_list_pluck( $split, 'qty' ) );
		$res = Matcher::parse( "120 kraft mugs\nNB-A5 x 30\n5 unicorn saddles" );
		$this->assertSame( array( $this->p['mug']->get_id(), $this->p['book']->get_id() ), wp_list_pluck( $res['lines'], 'product_id' ) );
		$this->assertSame( array( 'unicorn saddles' ), $res['unmatched'] );
		$this->assertNotEmpty( Matcher::candidates( 'kraft mugs and notebooks' ) );
	}

	public function test_quote_draft_uses_real_products_and_rule_prices() {
		$this->with_key();
		$uid = $this->user( 'distributor' );
		$id  = Quotes::create( array( 'user_id' => $uid, 'request' => 'Hi, we need 120 kraft mugs and 30 A5 notebooks, plus 10 golden spoons.' ) );
		$mug = $this->p['mug']->get_id();
		$this->http['generativelanguage'] = function ( $url, $args ) use ( $mug ) {
			$body = json_decode( $args['body'], true );
			$text = $body['contents'][0]['parts'][0]['text'];
			$this->assertStringContainsString( 'MUG-KRAFT-12', $text, 'Catalogue candidates are sent' );
			$this->assertStringNotContainsString( '"price"', $text, 'Prices never go to the AI' );
			return $this->gemini(
				array(
					'lines'     => array(
						array( 'product_id' => $mug, 'qty' => 120, 'confidence' => 'high', 'note' => '' ),
						array( 'product_id' => $this->p['book']->get_id(), 'qty' => 30, 'confidence' => 'medium' ),
						array( 'product_id' => 987654, 'qty' => 10, 'confidence' => 'low' ),
					),
					'unmatched' => array( array( 'text' => 'golden spoons', 'reason' => 'Not in the catalogue' ) ),
					'questions' => array( 'Which delivery address?' ),
					'message'   => 'Thanks Anna, here is a draft.',
				)
			);
		};
		$before = count( Quotes::items( $id ) );
		$draft  = Assistant::draft_quote( $id );
		$this->assertIsArray( $draft );
		$this->assertCount( 2, $draft['lines'], 'Hallucinated product dropped' );
		$this->assertSame( 1, $draft['dropped'] );
		$this->assertSame( 6.0, $draft['lines'][0]['unit_price'], '100+ tier from the rule' );
		$this->assertSame( 720.0, $draft['lines'][0]['line_total'] );
		$this->assertSame( 3.2, $draft['lines'][1]['unit_price'] );
		$this->assertCount( 2, $draft['unmatched'] );
		$this->assertSame( array( 'Which delivery address?' ), $draft['questions'] );
		$this->assertSame( $before, count( Quotes::items( $id ) ), 'Nothing saved until the admin accepts' );
		$this->assertSame( 8192, $this->requests[0]['body']['generationConfig']['maxOutputTokens'] );
		$this->assertSame( 2048, $this->requests[0]['body']['generationConfig']['thinkingConfig']['thinkingBudget'], 'Heavy profile' );
	}

	public function test_quote_draft_errors_without_key_or_text() {
		$id = Quotes::create( array( 'email' => 'a@b.test', 'request' => '' ) );
		$this->assertWPError( Assistant::draft_quote( $id ) );
		$id2 = Quotes::create( array( 'email' => 'a@b.test', 'request' => '120 kraft mugs' ) );
		$err = Assistant::draft_quote( $id2 );
		$this->assertWPError( $err );
		$this->assertSame( 'dev3bq_no_api_key', $err->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_ai_draft_route_and_accept_via_save() {
		$this->with_key();
		do_action( 'rest_api_init' );
		$id                               = Quotes::create( array( 'email' => 'a@b.test', 'request' => '20 bamboo pens' ) );
		$pen                              = $this->p['pen']->get_id();
		$this->http['generativelanguage'] = $this->gemini( array( 'lines' => array( array( 'product_id' => $pen, 'qty' => 20, 'confidence' => 'high' ) ), 'unmatched' => array(), 'questions' => array(), 'message' => '' ) );
		$res                              = $this->rest( 'POST', "quotes/{$id}/ai-draft", array() );
		$this->assertSame( 200, $res->get_status() );
		$lines = $res->get_data()['lines'];
		$save  = $this->rest( 'POST', "quotes/{$id}", array( 'items' => $lines, 'ai_accepted' => true ) );
		$this->assertSame( 30.0, $save->get_data()['total'] );
		$this->assertContains( 'ai', wp_list_pluck( $save->get_data()['events'], 'type' ) );
	}

	public function test_email_writer_draft() {
		$this->with_key();
		$id = Quotes::create( array( 'email' => 'a@b.test', 'name' => 'Anna Buyer' ), array( array( 'product_id' => $this->p['mug']->get_id(), 'qty' => 10 ) ) );
		Service::send( $id );
		Service::message( $id, 'Could you ship by Friday?', 'customer' );
		$this->http['generativelanguage'] = function ( $url, $args ) {
			$text = json_decode( $args['body'], true )['contents'][0]['parts'][0]['text'];
			$this->assertStringContainsString( 'ship by Friday', $text, 'Latest customer reply is part of the facts' );
			$this->assertStringNotContainsString( 'a@b.test', $text, 'No email address sent' );
			return $this->gemini( array( 'subject' => 'Re: your quote', 'body' => 'Hi Anna, yes we can ship by Friday.' ) );
		};
		$res = Assistant::write_email( 'reply', $id, 'Mention free delivery' );
		$this->assertSame( 'Re: your quote', $res['subject'] );
		$this->assertStringContainsString( 'Friday', $res['body'] );
		$this->assertSame( 'sent', Quotes::get( $id )['status'], 'Writing an email changes nothing' );
		$this->assertWPError( Assistant::write_email( 'poem', $id ) );
	}

	public function test_application_screening_keeps_role_valid() {
		$this->with_key();
		wp_set_current_user( 0 );
		$app = Applications::submit( array( 'first_name' => 'Kai', 'email' => 'kai@gmail.com', 'company' => 'Kai Trading', 'country' => 'DE', 'tax_id' => '123' ) );
		$this->http['generativelanguage'] = $this->gemini(
			array(
				'summary'        => 'Small trader using a free email address.',
				'suggested_role' => 'platinum',
				'signals'        => array( array( 'label' => 'Free email domain', 'tone' => 'warning' ), array( 'label' => 'Has a tax id', 'tone' => 'bogus' ) ),
				'questions'      => array( 'Do you have a website?' ),
			)
		);
		$res = Assistant::screen_application( $app['id'] );
		$this->assertSame( 'wholesale', $res['suggested_role'], 'Unknown role falls back to the requested one' );
		$this->assertSame( 'neutral', $res['signals'][1]['tone'] );
		$this->assertSame( 'Small trader using a free email address.', Applications::get( $app['id'] )['ai']['summary'], 'Stored for the list badge' );
	}

	public function test_shopper_parse_uses_ai_then_falls_back() {
		$this->with_key();
		$book                             = $this->p['book']->get_id();
		$this->http['generativelanguage'] = $this->gemini( array( 'lines' => array( array( 'product_id' => $book, 'qty' => 30 ), array( 'product_id' => 424242, 'qty' => 1 ) ), 'unmatched' => array( 'staples' ) ) );
		$res                              = Assistant::parse_for_shopper( '30 dotted notebooks and staples', '' );
		$this->assertTrue( $res['ai'] );
		$this->assertSame( array( $book ), wp_list_pluck( $res['lines'], 'product_id' ) );
		$this->assertSame( array( 'staples' ), $res['unmatched'] );
		// Same text again comes from the cache (no second request).
		Assistant::parse_for_shopper( '30 dotted notebooks and staples', '' );
		$this->assertCount( 1, $this->requests );

		Settings_Store::update( array( 'public_ai' => false ) );
		Settings_Store::flush();
		$off = Assistant::parse_for_shopper( 'NB-A5 x 5', '' );
		$this->assertFalse( $off['ai'] );
		$this->assertSame( 5, $off['lines'][0]['qty'] );
		$this->assertCount( 1, $this->requests, 'Storefront AI switch respected' );

		Settings_Store::update( array( 'public_ai' => true ) );
		Settings_Store::flush();
		$this->http['generativelanguage'] = array( 500, array( 'error' => array( 'message' => 'boom', 'status' => 'INTERNAL' ) ) );
		$down                             = Assistant::parse_for_shopper( '7 bamboo pens', '' );
		$this->assertFalse( $down['ai'], 'Gemini error → non-AI parser' );
		$this->assertSame( $this->p['pen']->get_id(), $down['lines'][0]['product_id'] );
	}

	public function test_public_parse_route() {
		do_action( 'rest_api_init' );
		$res = $this->rest( 'POST', 'public/parse', array( 'text' => "MUG-KRAFT-12 x 12\nNB-A5, 3" ), '' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['ai'] );
		$this->assertSame( array( 12, 3 ), wp_list_pluck( $res->get_data()['lines'], 'qty' ) );
		$this->assertSame( '$10.00', $res->get_data()['lines'][0]['price'] );
	}
}
