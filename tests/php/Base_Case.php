<?php
/**
 * Shared set-up: clean plugin tables + roles, fake HTTP, product/user/cart helpers.
 *
 * @package Dev3\AiB2bWholesale
 */

use Dev3\AiB2bWholesale\Core3\Api\Ai;
use Dev3\AiB2bWholesale\Core3\Core\Cache;
use Dev3\AiB2bWholesale\Core3\Core\Schema;
use Dev3\AiB2bWholesale\Core3\Core\Settings_Store;
use Dev3\AiB2bWholesale\Core3\Data\Usage_Repository;
use Dev3\AiB2bWholesale\Data\Tables;
use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Pricing\Rules;
use Dev3\AiB2bWholesale\Wholesale\Roles;

abstract class Dev3bq_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake responses: url substring => [ status, body ] (or callable).
	 *
	 * @var array
	 */
	protected $http = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3BQ_DB_VERSION );
		global $wpdb;
		foreach ( Tables::all() as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore -- DML, so the test transaction can roll it back.
		}
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		delete_option( Roles::OPTION );
		Roles::flush();
		Roles::sync_wp_roles();
		Rules::flush();
		Engine::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client     = null;
		$this->requests = array();
		$this->http     = array();
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'dev3bq_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3bq_jobs_use_action_scheduler', '__return_false' );
		reset_phpmailer_instance();
		WC()->mailer()->init();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3bq_jobs_use_action_scheduler', '__return_false' );
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Saves the API key so AI calls reach the fake HTTP layer.
	 */
	protected function with_key() {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Settings_Store::flush();
	}

	/**
	 * Gemini-shaped JSON response.
	 *
	 * @param array $data Structured output.
	 * @return array
	 */
	protected function gemini( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 120,
					'candidatesTokenCount' => 80,
					'totalTokenCount'      => 200,
				),
			),
		);
	}

	/**
	 * pre_http_request handler.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return array|\WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'body' => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null,
			'raw'  => isset( $args['body'] ) ? (string) $args['body'] : '',
		);
		foreach ( $this->http as $needle => $reply ) {
			if ( false !== strpos( $url, $needle ) ) {
				if ( is_callable( $reply ) ) {
					$reply = $reply( $url, $args );
				}
				return array(
					'headers'  => array(),
					'body'     => is_string( $reply[1] ) ? $reply[1] : wp_json_encode( $reply[1] ),
					'response' => array(
						'code'    => $reply[0],
						'message' => 'x',
					),
					'cookies'  => array(),
				);
			}
		}
		return new WP_Error( 'http_blocked', 'No fake for ' . $url );
	}

	/**
	 * Simple product.
	 *
	 * @param string $name  Name.
	 * @param string $price Regular price.
	 * @param array  $o     sku, cats, sale, meta.
	 * @return WC_Product_Simple
	 */
	protected function product( $name = 'Kraft mug', $price = '10', array $o = array() ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( $price );
		if ( isset( $o['sale'] ) ) {
			$p->set_sale_price( $o['sale'] );
		}
		if ( isset( $o['sku'] ) ) {
			$p->set_sku( $o['sku'] );
		}
		if ( isset( $o['cats'] ) ) {
			$p->set_category_ids( $o['cats'] );
		}
		foreach ( isset( $o['meta'] ) ? $o['meta'] : array() as $k => $v ) {
			$p->update_meta_data( $k, $v );
		}
		$p->save();
		return wc_get_product( $p->get_id() );
	}

	/**
	 * User with a role (WP role or a wholesale role key).
	 *
	 * @param string $role customer|administrator|shop_manager|<wholesale key>.
	 * @param array  $meta User meta.
	 * @return int
	 */
	protected function user( $role = 'customer', array $meta = array() ) {
		$wholesale = Roles::get( $role );
		$id        = self::factory()->user->create(
			array(
				'role'       => $wholesale ? 'customer' : $role,
				'user_email' => 'u' . wp_generate_password( 6, false ) . '@buyer.test',
			)
		);
		if ( $wholesale ) {
			Roles::assign( $id, $role );
		}
		foreach ( $meta as $k => $v ) {
			update_user_meta( $id, $k, $v );
		}
		return $id;
	}

	/**
	 * Loads an empty cart for the current user.
	 *
	 * @return WC_Cart
	 */
	protected function cart() {
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->customer = new WC_Customer( get_current_user_id(), true );
		return WC()->cart;
	}

	/**
	 * Admin (or other role) REST request.
	 *
	 * @param string      $method Method.
	 * @param string      $route  Route under dev3bq/v1.
	 * @param array       $params Params.
	 * @param string|null $role   Role (null = keep the current user, '' = logged out).
	 * @param bool        $nonce  Send the REST nonce.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $params = array(), $role = 'administrator', $nonce = true ) {
		static $users = array();
		if ( null !== $role ) {
			if ( '' === $role ) {
				wp_set_current_user( 0 );
			} else {
				if ( ! isset( $users[ $role ] ) || ! get_userdata( $users[ $role ] ) ) {
					$users[ $role ] = $this->user( $role );
				}
				wp_set_current_user( $users[ $role ] );
			}
		}
		$req = new WP_REST_Request( $method, '/dev3bq/v1/' . ltrim( $route, '/' ) );
		if ( $nonce && get_current_user_id() ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( 'GET' === $method ) {
			foreach ( $params as $k => $v ) {
				$req->set_param( $k, $v );
			}
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $req );
	}

	/**
	 * Sent mails.
	 *
	 * @return array
	 */
	protected function mails() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}
}
