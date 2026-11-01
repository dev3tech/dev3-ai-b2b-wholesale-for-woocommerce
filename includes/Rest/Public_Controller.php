<?php
/**
 * Storefront REST routes (dev3bq/v1/public/*): same-origin + per-IP rate limit, no nonce required
 * (page caches serve stale nonces). Logged-in shoppers send their REST nonce so prices use their role.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Rest;

use Dev3\AiB2bWholesale\Ai\Assistant;
use Dev3\AiB2bWholesale\Core3\Api\Api_Error;
use Dev3\AiB2bWholesale\Core3\Rest\Base_Controller;
use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Quotes\Basket;
use Dev3\AiB2bWholesale\Quotes\Pdf_Quote;
use Dev3\AiB2bWholesale\Quotes\Quotes;
use Dev3\AiB2bWholesale\Quotes\Service;
use Dev3\AiB2bWholesale\Settings;
use Dev3\AiB2bWholesale\Wholesale\Applications;
use Dev3\AiB2bWholesale\Wholesale\Roles;
use Dev3\AiB2bWholesale\Woo\Storefront_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Public routes.
 */
class Public_Controller extends Base_Controller {

	const LIMITS = array(
		'products' => 60,
		'cart'     => 20,
		'basket'   => 40,
		'quote'    => 5,
		'parse'    => 6,
		'register' => 5,
		'view'     => 30,
		'action'   => 10,
	);

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$read  = \WP_REST_Server::READABLE;
		$write = \WP_REST_Server::CREATABLE;
		$this->route( '/public/products', $read, 'products', 'products' );
		$this->route( '/public/cart', $write, 'cart', 'cart' );
		$this->route( '/public/basket', $read, 'basket', 'basket' );
		$this->route( '/public/basket', $write, 'basket_update', 'basket' );
		$this->route( '/public/basket/from-cart', $write, 'from_cart', 'basket' );
		$this->route( '/public/quote', $write, 'submit_quote', 'quote' );
		$this->route( '/public/parse', $write, 'parse', 'parse' );
		$this->route( '/public/register', $write, 'register', 'register' );
		$this->route( '/public/quotes/(?P<number>[A-Za-z0-9\-_]+)', $read, 'view', 'view' );
		$this->route( '/public/quotes/(?P<number>[A-Za-z0-9\-_]+)/(?P<act>accept|decline|message)', $write, 'act', 'action' );
	}

	/**
	 * Route helper.
	 *
	 * @param string $path     Path.
	 * @param string $methods  Methods.
	 * @param string $callback Callback.
	 * @param string $bucket   Rate-limit bucket.
	 */
	private function route( $path, $methods, $callback, $bucket ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => function ( \WP_REST_Request $request ) use ( $bucket ) {
					return $this->public_permission( $request, 'dev3bq_' . $bucket, self::LIMITS[ $bucket ] );
				},
			)
		);
	}

	/**
	 * Price HTML → plain text (entities decoded, for textContent on the storefront).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain( $html ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Error response that keeps field errors.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	private function fail( \WP_Error $error ) {
		$res  = $this->respond( $error );
		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['fields'] ) ) {
			$body           = $res->get_data();
			$body['fields'] = $data['fields'];
			$res->set_data( $body );
		}
		return $res;
	}

	/**
	 * Quick-order row for one product/variation.
	 *
	 * @param \WC_Product $p    Product.
	 * @param string      $role Role.
	 * @return array
	 */
	private function row( $p, $role ) {
		$hidden = Storefront_Pricing::prices_hidden();
		$res    = Engine::price( $p, $role, 1 );
		$q      = Engine::qty_rules( $p, $role );
		$breaks = array();
		if ( ! $hidden && count( $res['breaks'] ) > 1 ) {
			foreach ( $res['breaks'] as $b ) {
				$breaks[] = array(
					'min'   => (int) $b['min'],
					'price' => self::plain( wc_price( wc_get_price_to_display( $p, array( 'price' => $b['price'] ) ) ) ),
				);
			}
		}
		$image = $p->get_image_id() ? $p->get_image_id() : ( $p->get_parent_id() ? get_post_thumbnail_id( $p->get_parent_id() ) : 0 );
		$img   = $image ? wp_get_attachment_image_url( $image, 'woocommerce_gallery_thumbnail' ) : '';
		return array(
			'id'           => $p->get_id(),
			'product_id'   => $p->get_parent_id() ? $p->get_parent_id() : $p->get_id(),
			'variation_id' => $p->get_parent_id() ? $p->get_id() : 0,
			'name'         => wp_strip_all_tags( $p->get_name() ),
			'sku'          => (string) $p->get_sku(),
			'url'          => $p->get_permalink(),
			'image'        => $img ? $img : '',
			'in_stock'     => $p->is_in_stock(),
			'stock'        => $p->managing_stock() ? (int) $p->get_stock_quantity() : null,
			'price'        => $hidden ? '' : self::plain( wc_price( wc_get_price_to_display( $p, array( 'price' => $res['price'] ) ) ) ),
			'regular'      => ! $hidden && $res['regular'] > $res['price'] ? self::plain( wc_price( wc_get_price_to_display( $p, array( 'price' => $res['regular'] ) ) ) ) : '',
			'breaks'       => $breaks,
			'min'          => $q['min'],
			'step'         => $q['step'],
			'purchasable'  => $p->is_purchasable() && $p->is_in_stock(),
		);
	}

	/**
	 * Products shown to this visitor, expanded to variations.
	 *
	 * @param \WC_Product[] $products Products.
	 * @param string        $role     Role.
	 * @return array
	 */
	private function rows( array $products, $role ) {
		$out = array();
		foreach ( $products as $p ) {
			if ( ! $p instanceof \WC_Product || ! Engine::visible_for( $p, $role ) ) {
				continue;
			}
			if ( $p->is_type( 'variable' ) ) {
				foreach ( $p->get_visible_children() as $cid ) {
					$c = wc_get_product( $cid );
					if ( $c && $c->variation_is_visible() ) {
						$out[ $cid ] = $this->row( $c, $role );
					}
				}
			} elseif ( in_array( $p->get_type(), array( 'simple', 'variation' ), true ) ) {
				$out[ $p->get_id() ] = $this->row( $p, $role );
			}
		}
		return array_values( $out );
	}

	/**
	 * GET /public/products?search=&category=&page=&per_page=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function products( \WP_REST_Request $request ) {
		$role     = Roles::user_role();
		$search   = mb_substr( trim( sanitize_text_field( (string) $request['search'] ) ), 0, 80 );
		$category = sanitize_title( (string) $request['category'] );
		$per      = min( 50, max( 5, (int) ( $request['per_page'] ? $request['per_page'] : 20 ) ) );
		$page     = max( 1, (int) $request['page'] );
		if ( '' !== $search ) {
			$ids = \Dev3\AiB2bWholesale\Ai\Matcher::search( $search, 80 );
			$all = array();
			foreach ( $ids as $id ) {
				$p = wc_get_product( $id );
				if ( ! $p ) {
					continue;
				}
				$parent = $p->get_parent_id() ? wc_get_product( $p->get_parent_id() ) : $p;
				if ( ! $parent || 'publish' !== $parent->get_status() || 'hidden' === $parent->get_catalog_visibility() ) {
					continue;
				}
				if ( '' !== $category && ! has_term( $category, 'product_cat', $parent->get_id() ) ) {
					continue;
				}
				$all[] = $p;
			}
			$rows  = $this->rows( $all, $role );
			$total = count( $rows );
			$rows  = array_slice( $rows, ( $page - 1 ) * $per, $per );
		} else {
			$args = array(
				'status'     => 'publish',
				'limit'      => $per,
				'page'       => $page,
				'paginate'   => true,
				'orderby'    => 'title',
				'order'      => 'ASC',
				'visibility' => 'catalog',
				'type'       => array( 'simple', 'variable' ),
			);
			if ( '' !== $category ) {
				$args['category'] = array( $category );
			}
			$res   = wc_get_products( $args );
			$rows  = $this->rows( $res->products, $role );
			$total = (int) $res->total;
		}
		return $this->respond(
			array(
				'rows'         => $rows,
				'total'        => $total,
				'pages'        => max( 1, (int) ceil( $total / $per ) ),
				'page'         => $page,
				'pricesHidden' => Storefront_Pricing::prices_hidden(),
			)
		);
	}

	/**
	 * Lines from the request body.
	 *
	 * @param mixed $raw Raw.
	 * @return array
	 */
	private function lines( $raw ) {
		$out = array();
		foreach ( array_slice( is_array( $raw ) ? $raw : array(), 0, 100 ) as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$qty = (int) ( $line['qty'] ?? 0 );
			if ( $qty < 1 ) {
				continue;
			}
			$out[] = array(
				'product_id'   => absint( $line['product_id'] ?? 0 ),
				'variation_id' => absint( $line['variation_id'] ?? 0 ),
				'qty'          => min( 100000, $qty ),
			);
		}
		return $out;
	}

	/**
	 * POST /public/cart { lines } — adds several lines in one request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function cart( \WP_REST_Request $request ) {
		if ( ! Basket::boot() || ! WC()->cart ) {
			return $this->respond( new \WP_Error( 'dev3bq_no_cart', __( 'The cart is not available right now.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 503 ) ) );
		}
		$added  = 0;
		$errors = array();
		foreach ( $this->lines( $request['lines'] ) as $line ) {
			$p = wc_get_product( $line['variation_id'] ? $line['variation_id'] : $line['product_id'] );
			if ( ! $p || ! $p->is_purchasable() || ! Engine::visible_for( $p, Roles::user_role() ) ) {
				$errors[] = $p ? sprintf( /* translators: %s: product */ __( '%s cannot be bought online.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), wp_strip_all_tags( $p->get_name() ) ) : __( 'A product was not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
				continue;
			}
			$pid  = $p->get_parent_id() ? $p->get_parent_id() : $p->get_id();
			$vid  = $p->get_parent_id() ? $p->get_id() : 0;
			$attr = $vid ? $p->get_variation_attributes() : array();
			wc_clear_notices();
			$key = WC()->cart->add_to_cart( $pid, $line['qty'], $vid, $attr );
			if ( $key ) {
				++$added;
			} else {
				$notices  = wc_get_notices( 'error' );
				$errors[] = $notices ? wp_strip_all_tags( $notices[0]['notice'] ) : sprintf( /* translators: %s: product */ __( '%s could not be added.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), wp_strip_all_tags( $p->get_name() ) );
			}
			wc_clear_notices();
		}
		WC()->cart->calculate_totals();
		if ( WC()->session && ! headers_sent() && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		return $this->respond(
			array(
				'added'    => $added,
				'errors'   => $errors,
				'count'    => WC()->cart->get_cart_contents_count(),
				'total'    => self::plain( WC()->cart->get_cart_subtotal() ),
				'cart_url' => wc_get_cart_url(),
			)
		);
	}

	/**
	 * GET /public/basket.
	 *
	 * @return \WP_REST_Response
	 */
	public function basket() {
		return $this->respond( Basket::view() + array( 'quote_url' => Settings::page_url( 'quote_page_id' ) ) );
	}

	/**
	 * POST /public/basket { action: add|update|clear, product_id, variation_id, qty, qtys, lines }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function basket_update( \WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request['action'] );
		if ( 'clear' === $action ) {
			Basket::clear();
		} elseif ( 'update' === $action ) {
			$qtys = array();
			foreach ( (array) $request['qtys'] as $k => $v ) {
				$qtys[ preg_replace( '/[^0-9:]/', '', (string) $k ) ] = (int) $v;
			}
			Basket::update( $qtys );
		} else {
			$lines = $this->lines( $request['lines'] );
			if ( ! $lines ) {
				$lines = $this->lines(
					array(
						array(
							'product_id'   => $request['product_id'],
							'variation_id' => $request['variation_id'],
							'qty'          => $request['qty'] ? $request['qty'] : 1,
						),
					)
				);
			}
			foreach ( $lines as $line ) {
				$res = Basket::add( $line['product_id'], $line['variation_id'], $line['qty'] );
				if ( is_wp_error( $res ) ) {
					return $this->respond( $res );
				}
			}
		}
		return $this->basket();
	}

	/**
	 * POST /public/basket/from-cart.
	 *
	 * @return \WP_REST_Response
	 */
	public function from_cart() {
		Basket::from_cart( false );
		return $this->basket();
	}

	/**
	 * POST /public/quote { name, email, company, phone, request, hp }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function submit_quote( \WP_REST_Request $request ) {
		if ( '' !== trim( (string) $request['hp'] ) ) {
			return $this->respond(
				array(
					'number'  => '',
					'message' => __( 'Thanks! We will be in touch.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				)
			);
		}
		$user  = wp_get_current_user();
		$email = $user && $user->ID ? $user->user_email : sanitize_email( (string) $request['email'] );
		$errs  = array();
		if ( ! is_email( $email ) ) {
			$errs['email'] = __( 'Enter a valid email address.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
		$name = trim( sanitize_text_field( (string) $request['name'] ) );
		if ( '' === $name && ! ( $user && $user->ID ) ) {
			$errs['name'] = __( 'Enter your name.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
		if ( $errs ) {
			return $this->fail(
				new \WP_Error(
					'dev3bq_invalid',
					__( 'Please check the highlighted fields.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
					array(
						'status' => 400,
						'fields' => $errs,
					)
				)
			);
		}
		$lines = Basket::lines();
		$id    = Service::submit_request(
			array(
				'user_id' => $user ? (int) $user->ID : 0,
				'email'   => $email,
				'name'    => '' !== $name ? $name : ( $user ? $user->display_name : '' ),
				'company' => (string) $request['company'],
				'phone'   => (string) $request['phone'],
				'request' => (string) $request['request'],
				'source'  => in_array( $request['source'], array( 'cart', 'quick', 'text' ), true ) ? $request['source'] : ( $lines ? 'form' : 'text' ),
			),
			$lines
		);
		if ( is_wp_error( $id ) ) {
			return $this->fail( $id );
		}
		Basket::clear();
		$quote = Quotes::get( $id );
		return $this->respond(
			array(
				'number'   => $quote['number'],
				/* translators: %s: quote number */
				'message'  => sprintf( __( 'Thanks! Your request %s is in. We will email you a priced quote soon.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $quote['number'] ),
				'view_url' => Service::customer_url( $quote ),
			),
			201
		);
	}

	/**
	 * POST /public/parse { text } — list builder (AI with fallback). Lines are proposals only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function parse( \WP_REST_Request $request ) {
		$role = Roles::user_role();
		$res  = Assistant::parse_for_shopper( (string) $request['text'], $role );
		$out  = array();
		foreach ( $res['lines'] as $line ) {
			$p = wc_get_product( $line['product_id'] );
			if ( $p ) {
				$row        = $this->row( $p, $role );
				$row['qty'] = max( $row['min'], (int) $line['qty'] );
				$out[]      = $row;
			}
		}
		return $this->respond(
			array(
				'lines'     => $out,
				'unmatched' => $res['unmatched'],
				'ai'        => (bool) $res['ai'],
			)
		);
	}

	/**
	 * POST /public/register.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function register( \WP_REST_Request $request ) {
		if ( '' !== trim( (string) $request['hp'] ) ) {
			return $this->respond(
				array(
					'status'  => 'pending',
					'message' => __( 'Thanks! We received your application.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				)
			);
		}
		$res = Applications::submit( (array) $request->get_params() );
		return is_wp_error( $res ) ? $this->fail( $res ) : $this->respond( $res, 201 );
	}

	/**
	 * Quote the visitor may see, or 404 (never reveals whether the number exists).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function visible_quote( \WP_REST_Request $request ) {
		$quote = Quotes::get_by_number( (string) $request['number'] );
		if ( ! $quote || ! Service::can_view( $quote, (string) $request['key'] ) ) {
			return Api_Error::make( 'dev3bq_no_quote', __( 'Quote not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '', 404 );
		}
		return $quote;
	}

	/**
	 * Customer view of a quote.
	 *
	 * @param array $q Quote.
	 * @return array
	 */
	public static function customer_view( array $q ) {
		$labels = Quotes::status_labels();
		$priced = in_array( $q['status'], array( 'sent', 'accepted', 'declined', 'expired' ), true );
		$cur    = array( 'currency' => $q['currency'] );
		$items  = array();
		foreach ( Quotes::items( $q['id'] ) as $i ) {
			$items[] = array(
				'name'  => $i['name'],
				'sku'   => $i['sku'],
				'qty'   => $i['qty'],
				'note'  => $i['note'],
				'unit'  => $priced ? self::plain( wc_price( $i['unit_price'], $cur ) ) : '',
				'total' => $priced ? self::plain( wc_price( $i['line_total'], $cur ) ) : '',
			);
		}
		$totals = array();
		if ( $priced ) {
			$totals[] = array( __( 'Subtotal', 'dev3-ai-b2b-wholesale-for-woocommerce' ), self::plain( wc_price( $q['subtotal'], $cur ) ) );
			if ( $q['discount'] > 0 ) {
				$totals[] = array( __( 'Discount', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '-' . self::plain( wc_price( $q['discount'], $cur ) ) );
			}
			if ( $q['shipping'] > 0 ) {
				$totals[] = array( __( 'Shipping', 'dev3-ai-b2b-wholesale-for-woocommerce' ), self::plain( wc_price( $q['shipping'], $cur ) ) );
			}
			if ( 'exempt' === $q['tax_mode'] ) {
				$totals[] = array( __( 'Tax', 'dev3-ai-b2b-wholesale-for-woocommerce' ), __( 'Exempt', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
			} elseif ( $q['tax'] > 0 ) {
				$totals[] = array( __( 'Estimated tax', 'dev3-ai-b2b-wholesale-for-woocommerce' ), self::plain( wc_price( $q['tax'], $cur ) ) );
			}
			$totals[] = array( __( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ), self::plain( wc_price( $q['total'], $cur ) ) );
		}
		$order = $q['order_id'] ? wc_get_order( $q['order_id'] ) : null;
		$today = gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		return array(
			'number'       => $q['number'],
			'status'       => $q['status'],
			'status_label' => $labels[ $q['status'] ] ?? $q['status'],
			'created'      => date_i18n( get_option( 'date_format' ), strtotime( $q['created_at'] ) ),
			'valid_until'  => $q['valid_until'] && $priced ? date_i18n( get_option( 'date_format' ), strtotime( $q['valid_until'] ) ) : '',
			'items'        => $items,
			'totals'       => $totals,
			'request'      => $q['request'],
			'message'      => $priced ? $q['message'] : '',
			'terms'        => $priced ? ( '' !== $q['terms'] ? $q['terms'] : Settings::text( 'quote_terms' ) ) : '',
			'events'       => Quotes::events( $q['id'], true ),
			'pdf_url'      => $priced ? Pdf_Quote::customer_url( $q ) : '',
			'pay_url'      => $order && $order->needs_payment() ? $order->get_checkout_payment_url() : '',
			'order_number' => $order ? $order->get_order_number() : '',
			'can_accept'   => 'sent' === $q['status'] && ( ! $q['valid_until'] || $q['valid_until'] >= $today ),
			'can_decline'  => 'sent' === $q['status'],
			'can_message'  => in_array( $q['status'], Quotes::OPEN, true ),
		);
	}

	/**
	 * GET /public/quotes/<number>?key=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function view( \WP_REST_Request $request ) {
		$q = $this->visible_quote( $request );
		return is_wp_error( $q ) ? $this->respond( $q ) : $this->respond( self::customer_view( $q ) );
	}

	/**
	 * POST /public/quotes/<number>/(accept|decline|message) { key, reason, message }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function act( \WP_REST_Request $request ) {
		$q = $this->visible_quote( $request );
		if ( is_wp_error( $q ) ) {
			return $this->respond( $q );
		}
		$act = (string) $request['act'];
		if ( 'accept' === $act ) {
			$order = Service::accept( $q['id'], 'customer' );
			if ( is_wp_error( $order ) ) {
				return $this->respond( $order );
			}
			$view            = self::customer_view( Quotes::get( $q['id'] ) );
			$view['notice']  = __( 'Thank you! Your order is ready. You can pay for it now.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
			$view['pay_url'] = $order->needs_payment() ? $order->get_checkout_payment_url() : $view['pay_url'];
			return $this->respond( $view );
		}
		$res = 'decline' === $act ? Service::decline( $q['id'], (string) $request['reason'], 'customer' ) : Service::message( $q['id'], (string) $request['message'], 'customer' );
		if ( is_wp_error( $res ) ) {
			return $this->respond( $res );
		}
		$view           = self::customer_view( Quotes::get( $q['id'] ) );
		$view['notice'] = 'decline' === $act ? __( 'The quote was declined. Thanks for letting us know.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Message sent. We will reply by email.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		return $this->respond( $view );
	}
}
