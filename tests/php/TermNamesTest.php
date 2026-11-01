<?php
/**
 * Term names reach JSON as plain text, decoded once (regression: "Mugs &amp;amp; Drinkware").
 * React escapes on render, so the server must never send entities.
 *
 * @package Dev3\AiB2bWholesale
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiB2bWholesale\Ai\Matcher;
use Dev3\AiB2bWholesale\Data\Text;

class TermNamesTest extends Dev3bq_Base_Case {

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	/**
	 * Category whose stored name is exactly $stored (bypasses sanitizing, like an import would).
	 *
	 * @param string $stored Stored name.
	 * @return int
	 */
	private function raw_cat( $stored ) {
		global $wpdb;
		$term = wp_insert_term( 'tmp-' . wp_generate_password( 6, false ), 'product_cat' );
		$wpdb->update( $wpdb->terms, array( 'name' => $stored ), array( 'term_id' => $term['term_id'] ) ); // phpcs:ignore
		clean_term_cache( $term['term_id'], 'product_cat' );
		return (int) $term['term_id'];
	}

	public function test_plain_decodes_entities_until_stable() {
		$this->assertSame( 'Mugs & Drinkware', Text::plain( 'Mugs &amp; Drinkware' ) );
		$this->assertSame( 'Mugs & Drinkware', Text::plain( 'Mugs &amp;amp; Drinkware' ) );
		$this->assertSame( 'Mugs & Drinkware', Text::plain( 'Mugs & Drinkware' ) );
		$this->assertSame( "Kids' <Cups>", Text::plain( 'Kids&#039; &lt;Cups&gt;' ) );
		$this->assertSame( 'Café', Text::plain( 'Caf&eacute;' ) );
	}

	public function test_rules_and_categories_routes_send_plain_names() {
		$term   = wp_insert_term( 'Mugs & Drinkware', 'product_cat' );
		$mugs   = (int) $term['term_id'];
		$legacy = $this->raw_cat( 'Tea &amp;amp; Coffee' );
		$this->assertSame( 'Mugs &amp; Drinkware', get_term( $mugs, 'product_cat' )->name, 'WordPress stores the name encoded' );
		$mug = $this->product( 'Mug & Saucer' );

		$this->rest( 'POST', 'rules', array( 'name' => 'Mug & cup deal', 'scope' => 'categories', 'targets' => array( $mugs, $legacy ), 'type' => 'percent', 'amount' => 10 ) );
		$res   = $this->rest( 'POST', 'rules', array( 'name' => 'Saucers', 'scope' => 'products', 'targets' => array( $mug->get_id() ), 'type' => 'percent', 'amount' => 5 ) );
		$names = (array) $res->get_data()['names'];
		$this->assertSame( 'Mugs & Drinkware', $names[ 'c' . $mugs ] );
		$this->assertSame( 'Tea & Coffee', $names[ 'c' . $legacy ] );
		$this->assertSame( 'Mug & Saucer', $names[ 'p' . $mug->get_id() ] );
		$this->assertContains( 'Mug & cup deal', wp_list_pluck( $res->get_data()['rules'], 'name' ) );

		$cats = wp_list_pluck( $this->rest( 'GET', 'categories' )->get_data()['categories'], 'name', 'id' );
		$this->assertSame( 'Mugs & Drinkware', $cats[ $mugs ] );
		$this->assertSame( 'Tea & Coffee', $cats[ $legacy ] );
		$this->assertStringNotContainsString( '&amp;', wp_json_encode( $this->rest( 'GET', 'rules' )->get_data() ) );
	}

	public function test_quick_order_categories_and_ai_description_are_plain() {
		$term = wp_insert_term( 'Mugs & Drinkware', 'product_cat' );
		$mug  = $this->product( 'Kraft mug', '10', array( 'cats' => array( (int) $term['term_id'] ) ) );

		$html = do_shortcode( '[dev3bq_quick_order]' );
		$this->assertSame( 1, preg_match( '/data-categories="([^"]*)"/', $html, $m ) );
		$cats = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
		$this->assertContains( 'Mugs & Drinkware', wp_list_pluck( $cats, 'label' ) );

		$this->assertSame( 'Mugs & Drinkware', Matcher::describe( $mug )['category'] );
	}
}
