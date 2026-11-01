<?php
/**
 * Admin REST routes (dev3bq/v1). Every route needs the REST nonce + the manage capability.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Rest;

use Dev3\AiB2bWholesale\Ai\Assistant;
use Dev3\AiB2bWholesale\Core3\Api\Ai;
use Dev3\AiB2bWholesale\Core3\Rest\Base_Controller;
use Dev3\AiB2bWholesale\Data\Text;
use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Pricing\Rules;
use Dev3\AiB2bWholesale\Quotes\Pdf_Quote;
use Dev3\AiB2bWholesale\Quotes\Quotes;
use Dev3\AiB2bWholesale\Quotes\Service;
use Dev3\AiB2bWholesale\Settings;
use Dev3\AiB2bWholesale\Wholesale\Applications;
use Dev3\AiB2bWholesale\Wholesale\Roles;
use Dev3\AiB2bWholesale\Woo\Checkout_Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Routes.
 */
class Admin_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$read  = \WP_REST_Server::READABLE;
		$write = \WP_REST_Server::CREATABLE;
		$del   = \WP_REST_Server::DELETABLE;
		$this->route( '/dashboard', $read, 'dashboard' );
		$this->route( '/roles', $read, 'roles' );
		$this->route( '/roles', $write, 'save_role' );
		$this->route( '/roles/(?P<key>[a-z0-9_\-]+)', $del, 'delete_role' );
		$this->route( '/rules', $read, 'rules' );
		$this->route( '/rules', $write, 'save_rule' );
		$this->route( '/rules/(?P<id>\d+)', $write, 'save_rule' );
		$this->route( '/rules/(?P<id>\d+)', $del, 'delete_rule' );
		$this->route( '/price-preview', $write, 'price_preview' );
		$this->route( '/products', $read, 'products' );
		$this->route( '/categories', $read, 'categories' );
		$this->route( '/pages', $read, 'pages' );
		$this->route( '/setup-page', $write, 'setup_page' );
		$this->route( '/applications', $read, 'applications' );
		$this->route( '/applications/(?P<id>\d+)/approve', $write, 'approve' );
		$this->route( '/applications/(?P<id>\d+)/reject', $write, 'reject' );
		$this->route( '/applications/(?P<id>\d+)/screen', $write, 'screen', 'can_ai' );
		$this->route( '/applications/(?P<id>\d+)/ai-email', $write, 'application_email', 'can_ai' );
		$this->route( '/customers', $read, 'customers' );
		$this->route( '/customers/search', $read, 'customer_search' );
		$this->route( '/customers/(?P<id>\d+)', $write, 'save_customer' );
		$this->route( '/quotes', $read, 'quotes' );
		$this->route( '/quotes', $write, 'create_quote' );
		$this->route( '/quotes/(?P<id>\d+)', $read, 'quote' );
		$this->route( '/quotes/(?P<id>\d+)', $write, 'save_quote' );
		$this->route( '/quotes/(?P<id>\d+)/send', $write, 'send' );
		$this->route( '/quotes/(?P<id>\d+)/order', $write, 'to_order' );
		$this->route( '/quotes/(?P<id>\d+)/status', $write, 'status' );
		$this->route( '/quotes/(?P<id>\d+)/duplicate', $write, 'duplicate' );
		$this->route( '/quotes/(?P<id>\d+)/message', $write, 'message' );
		$this->route( '/quotes/(?P<id>\d+)/reprice', $write, 'reprice' );
		$this->route( '/quotes/(?P<id>\d+)/ai-draft', $write, 'ai_draft', 'can_ai' );
		$this->route( '/quotes/(?P<id>\d+)/ai-email', $write, 'ai_email', 'can_ai' );
	}

	/**
	 * Route helper.
	 *
	 * @param string $path       Path.
	 * @param string $methods    Methods.
	 * @param string $callback   Method name.
	 * @param string $permission Permission method.
	 */
	private function route( $path, $methods, $callback, $permission = 'can_manage' ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, $permission ),
			)
		);
	}

	/**
	 * AI routes: manage + generate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_ai( \WP_REST_Request $request ) {
		$ok = $this->can_manage( $request );
		return true === $ok ? $this->can_generate( $request ) : $ok;
	}

	/**
	 * Quote or 404.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function quote_or_error( \WP_REST_Request $request ) {
		$quote = Quotes::get( (int) $request['id'] );
		return $quote ? $quote : new \WP_Error( 'dev3bq_no_quote', __( 'Quote not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 404 ) );
	}

	/**
	 * GET /dashboard.
	 *
	 * @return \WP_REST_Response
	 */
	public function dashboard() {
		$stats   = Quotes::stats( 30 );
		$revenue = 0.0;
		$orders  = 0;
		$ids     = wc_get_orders(
			array(
				'limit'        => 500,
				'return'       => 'ids',
				'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
				'meta_key'     => Checkout_Rules::ORDER_ROLE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		);
		foreach ( (array) $ids as $oid ) {
			$o = wc_get_order( $oid );
			if ( $o ) {
				$revenue += (float) $o->get_total();
				++$orders;
			}
		}
		$customers = Roles::users( array( 'per_page' => 1 ) );
		$recent    = Quotes::query( array( 'per_page' => 6 ) );
		$pages     = array(
			'quote_page_id'        => (int) Settings::get( 'quote_page_id', 0 ),
			'quick_order_page_id'  => (int) Settings::get( 'quick_order_page_id', 0 ),
			'registration_page_id' => (int) Settings::get( 'registration_page_id', 0 ),
		);
		return $this->respond(
			array(
				'quotes'       => $stats,
				'applications' => Applications::pending_count(),
				'customers'    => $customers['total'],
				'revenue'      => array(
					'value'  => round( $revenue, 2 ),
					'orders' => $orders,
				),
				'recent'       => array_map( array( __CLASS__, 'brief' ), $recent['rows'] ),
				'counts'       => $recent['counts'],
				'setup'        => array(
					'roles' => count( Roles::all() ),
					'rules' => count( Rules::all() ),
					'pages' => $pages,
					'ai'    => Ai::status(),
				),
			)
		);
	}

	/**
	 * List row.
	 *
	 * @param array $q Quote.
	 * @return array
	 */
	public static function brief( array $q ) {
		$labels = Quotes::status_labels();
		return array(
			'id'           => $q['id'],
			'number'       => $q['number'],
			'status'       => $q['status'],
			'status_label' => $labels[ $q['status'] ] ?? $q['status'],
			'name'         => $q['name'],
			'company'      => $q['company'],
			'email'        => $q['email'],
			'role'         => $q['role'],
			'total'        => $q['total'],
			'currency'     => $q['currency'],
			'source'       => $q['source'],
			'order_id'     => $q['order_id'],
			'valid_until'  => $q['valid_until'],
			'updated_at'   => mysql_to_rfc3339( $q['updated_at'] ),
			'created_at'   => mysql_to_rfc3339( $q['created_at'] ),
		);
	}

	/**
	 * Full quote for the editor.
	 *
	 * @param array $q Quote.
	 * @return array
	 */
	public static function present( array $q ) {
		$out   = self::brief( $q );
		$role  = Roles::get( $q['role'] );
		$order = $q['order_id'] ? wc_get_order( $q['order_id'] ) : null;
		$user  = $q['user_id'] ? get_userdata( $q['user_id'] ) : null;
		$out  += array(
			'user_id'      => $q['user_id'],
			'user'         => $user ? array(
				'id'       => $user->ID,
				'name'     => $user->display_name,
				'edit_url' => get_edit_user_link( $user->ID ),
			) : null,
			'phone'        => $q['phone'],
			'role_name'    => $role ? $role['name'] : __( 'Retail', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'request'      => $q['request'],
			'message'      => $q['message'],
			'admin_note'   => $q['admin_note'],
			'subtotal'     => $q['subtotal'],
			'discount'     => $q['discount'],
			'shipping'     => $q['shipping'],
			'tax'          => $q['tax'],
			'tax_mode'     => $q['tax_mode'],
			'terms'        => $q['terms'],
			'terms_hint'   => Settings::text( 'quote_terms' ),
			'sent_at'      => $q['sent_at'] ? mysql_to_rfc3339( $q['sent_at'] ) : '',
			'items'        => Quotes::items( $q['id'] ),
			'events'       => Quotes::events( $q['id'] ),
			'pdf_url'      => Pdf_Quote::admin_url( $q ),
			'customer_url' => Service::customer_url( $q ),
			'order'        => $order ? array(
				'id'           => $order->get_id(),
				'number'       => $order->get_order_number(),
				'status'       => $order->get_status(),
				'status_label' => wc_get_order_status_name( $order->get_status() ),
				'edit_url'     => $order->get_edit_order_url(),
				'pay_url'      => $order->needs_payment() ? $order->get_checkout_payment_url() : '',
				'total'        => (float) $order->get_total(),
			) : null,
			'editable'     => in_array( $q['status'], Quotes::OPEN, true ) || in_array( $q['status'], array( 'expired', 'declined' ), true ),
		);
		return $out;
	}

	/**
	 * GET /roles (+ gateway and shipping options).
	 *
	 * @return \WP_REST_Response
	 */
	public function roles() {
		$gateways = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) {
			$gateways[] = array(
				'value'   => (string) $id,
				'label'   => wp_strip_all_tags( $gw->get_method_title() ? $gw->get_method_title() : $gw->get_title() ),
				'enabled' => 'yes' === $gw->enabled,
			);
		}
		$shipping = array();
		foreach ( WC()->shipping()->get_shipping_methods() as $id => $method ) {
			$shipping[] = array(
				'value' => (string) $id,
				'label' => wp_strip_all_tags( $method->get_method_title() ),
			);
		}
		$roles = array_values( Roles::all() );
		foreach ( $roles as $i => $r ) {
			$roles[ $i ]['users'] = count(
				get_users(
					array(
						'role'   => Roles::WP_PREFIX . $r['key'],
						'fields' => 'ID',
						'number' => 1000,
					)
				)
			);
		}
		return $this->respond(
			array(
				'roles'    => $roles,
				'gateways' => $gateways,
				'shipping' => $shipping,
			)
		);
	}

	/**
	 * POST /roles.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_role( \WP_REST_Request $request ) {
		$input  = (array) $request->get_json_params();
		$is_new = empty( $input['original_key'] );
		if ( $is_new && ! empty( $input['key'] ) && Roles::get( sanitize_key( $input['key'] ) ) ) {
			return $this->respond( new \WP_Error( 'dev3bq_role_exists', __( 'A role with this key already exists.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) ) );
		}
		if ( ! $is_new ) {
			$input['key'] = sanitize_key( $input['original_key'] );
		}
		$saved = Roles::save( $input );
		update_option( 'dev3bq_price_version', (int) get_option( 'dev3bq_price_version', 1 ) + 1, false );
		return is_wp_error( $saved ) ? $this->respond( $saved ) : $this->roles();
	}

	/**
	 * DELETE /roles/<key>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_role( \WP_REST_Request $request ) {
		if ( ! Roles::delete( sanitize_key( $request['key'] ) ) ) {
			return $this->respond( new \WP_Error( 'dev3bq_no_role', __( 'Role not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		return $this->roles();
	}

	/**
	 * GET /rules.
	 *
	 * @return \WP_REST_Response
	 */
	public function rules() {
		$rules = Rules::all();
		$names = array();
		foreach ( $rules as $r ) {
			foreach ( $r['targets'] as $t ) {
				if ( 'products' === $r['scope'] ) {
					$p                 = wc_get_product( $t );
					$names[ 'p' . $t ] = $p ? Text::plain( wp_strip_all_tags( $p->get_name() ) ) : '#' . $t;
				} elseif ( 'categories' === $r['scope'] ) {
					$term              = get_term( $t, 'product_cat' );
					$names[ 'c' . $t ] = $term instanceof \WP_Term ? Text::term_name( $term ) : '#' . $t;
				}
			}
		}
		return $this->respond(
			array(
				'rules' => $rules,
				'names' => (object) $names,
			)
		);
	}

	/**
	 * POST /rules(/<id>).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_rule( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id && ! Rules::get( $id ) ) {
			return $this->respond( new \WP_Error( 'dev3bq_no_rule', __( 'Rule not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		$saved = Rules::save( (array) $request->get_json_params(), $id );
		if ( is_wp_error( $saved ) ) {
			return $this->respond( $saved );
		}
		update_option( 'dev3bq_price_version', (int) get_option( 'dev3bq_price_version', 1 ) + 1, false );
		return $this->rules();
	}

	/**
	 * DELETE /rules/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_rule( \WP_REST_Request $request ) {
		Rules::delete( (int) $request['id'] );
		update_option( 'dev3bq_price_version', (int) get_option( 'dev3bq_price_version', 1 ) + 1, false );
		return $this->rules();
	}

	/**
	 * POST /price-preview { product_id, role, qty }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function price_preview( \WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['product_id'] );
		if ( ! $product || $product->is_type( 'variable' ) ) {
			return $this->respond( new \WP_Error( 'dev3bq_bad_product', __( 'Pick a simple product or a variation.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$role = sanitize_key( (string) $request['role'] );
		if ( '' !== $role && ! Roles::get( $role ) ) {
			$role = '';
		}
		$qty = max( 1, (int) $request['qty'] );
		$res = Engine::price( $product, $role, $qty );
		$q   = Engine::qty_rules( $product, $role );
		return $this->respond(
			$res + array(
				'qty'     => $qty,
				'total'   => Engine::round( $res['price'] * $qty ),
				'min_qty' => $q['min'],
				'step'    => $q['step'],
				'visible' => Engine::visible_for( $product, $role ),
				'name'    => wp_strip_all_tags( $product->get_name() ),
			)
		);
	}

	/**
	 * GET /products?search=&role=&include=1,2 — simple products and variations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function products( \WP_REST_Request $request ) {
		$role    = sanitize_key( (string) $request['role'] );
		$role    = Roles::get( $role ) ? $role : '';
		$include = array_filter( array_map( 'absint', explode( ',', (string) $request['include'] ) ) );
		$search  = trim( sanitize_text_field( (string) $request['search'] ) );
		$parents = 'parents' === $request['mode'];
		if ( $include ) {
			$ids = $include;
		} elseif ( '' !== $search ) {
			$ids = \Dev3\AiB2bWholesale\Ai\Matcher::search( $search, 30 );
		} else {
			$ids = wc_get_products(
				array(
					'limit'   => 20,
					'status'  => 'publish',
					'return'  => 'ids',
					'orderby' => 'date',
				)
			);
		}
		$out = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			if ( $p->is_type( 'variable' ) && ! $parents ) {
				foreach ( array_slice( $p->get_children(), 0, 20 ) as $cid ) {
					$c = wc_get_product( $cid );
					if ( $c ) {
						$out[ $cid ] = $this->product_row( $c, $role );
					}
				}
				continue;
			}
			if ( $parents && $p->is_type( 'variation' ) ) {
				$p = wc_get_product( $p->get_parent_id() );
				if ( ! $p ) {
					continue;
				}
			}
			$out[ $p->get_id() ] = $this->product_row( $p, $role );
		}
		return $this->respond( array( 'products' => array_values( array_slice( $out, 0, 40 ) ) ) );
	}

	/**
	 * Product picker row.
	 *
	 * @param \WC_Product $p    Product.
	 * @param string      $role Role.
	 * @return array
	 */
	private function product_row( $p, $role ) {
		$priceable = ! $p->is_type( 'variable' );
		$res       = $priceable ? Engine::price( $p, $role, 1 ) : null;
		return array(
			'id'           => $p->get_id(),
			'product_id'   => $p->get_parent_id() ? $p->get_parent_id() : $p->get_id(),
			'variation_id' => $p->get_parent_id() ? $p->get_id() : 0,
			'name'         => wp_strip_all_tags( $p->get_name() ),
			'sku'          => (string) $p->get_sku(),
			'type'         => $p->get_type(),
			'regular'      => $res ? $res['regular'] : 0,
			'price'        => $res ? $res['price'] : 0,
			'stock'        => $p->managing_stock() ? (int) $p->get_stock_quantity() : null,
			'in_stock'     => $p->is_in_stock(),
			'image'        => (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ),
		);
	}

	/**
	 * GET /categories.
	 *
	 * @return \WP_REST_Response
	 */
	public function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 300,
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[] = array(
				'id'    => (int) $t->term_id,
				'name'  => Text::term_name( $t ),
				'count' => (int) $t->count,
			);
		}
		return $this->respond( array( 'categories' => $out ) );
	}

	/**
	 * GET /applications.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function applications( \WP_REST_Request $request ) {
		return $this->respond(
			Applications::query(
				array(
					'status' => sanitize_key( (string) $request['status'] ),
					'page'   => (int) $request['page'],
					'search' => sanitize_text_field( (string) $request['search'] ),
				)
			)
		);
	}

	/**
	 * POST /applications/<id>/approve.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function approve( \WP_REST_Request $request ) {
		return $this->respond( Applications::approve( (int) $request['id'], sanitize_key( (string) $request['role'] ), sanitize_key( (string) $request['tax_exempt'] ), (string) $request['note'] ) );
	}

	/**
	 * POST /applications/<id>/reject.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reject( \WP_REST_Request $request ) {
		return $this->respond( Applications::reject( (int) $request['id'], (string) $request['reason'] ) );
	}

	/**
	 * POST /applications/<id>/screen (AI).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function screen( \WP_REST_Request $request ) {
		return $this->respond( Assistant::screen_application( (int) $request['id'] ) );
	}

	/**
	 * POST /applications/<id>/ai-email (AI) { kind: approved|declined }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function application_email( \WP_REST_Request $request ) {
		$kind = 'declined' === $request['kind'] ? 'declined' : 'approved';
		return $this->respond( Assistant::write_email( $kind, (int) $request['id'], (string) $request['points'] ) );
	}

	/**
	 * GET /customers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function customers( \WP_REST_Request $request ) {
		return $this->respond(
			Roles::users(
				array(
					'search'   => sanitize_text_field( (string) $request['search'] ),
					'page'     => (int) $request['page'],
					'role'     => sanitize_key( (string) $request['role'] ),
					'per_page' => 20,
				)
			)
		);
	}

	/**
	 * GET /customers/search?term= (any customer, for new quotes).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function customer_search( \WP_REST_Request $request ) {
		$term  = trim( sanitize_text_field( (string) $request['term'] ) );
		$users = '' === $term ? array() : get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_email', 'user_login', 'display_name' ),
				'number'         => 10,
			)
		);
		$out   = array();
		foreach ( $users as $u ) {
			$role  = Roles::get( Roles::user_role( $u->ID ) );
			$out[] = array(
				'id'        => $u->ID,
				'name'      => $u->display_name,
				'email'     => $u->user_email,
				'company'   => (string) get_user_meta( $u->ID, 'billing_company', true ),
				'phone'     => (string) get_user_meta( $u->ID, 'billing_phone', true ),
				'role_name' => $role ? $role['name'] : '',
			);
		}
		return $this->respond( array( 'users' => $out ) );
	}

	/**
	 * POST /customers/<id> { role, tax_exempt, note }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_customer( \WP_REST_Request $request ) {
		$uid  = (int) $request['id'];
		$user = get_userdata( $uid );
		if ( ! $user ) {
			return $this->respond( new \WP_Error( 'dev3bq_no_user', __( 'Customer not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		if ( user_can( $uid, 'manage_options' ) && ! current_user_can( 'manage_options' ) ) {
			return $this->respond( new \WP_Error( 'dev3bq_forbidden', __( 'You cannot change this account.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 403 ) ) );
		}
		if ( null !== $request['role'] ) {
			Roles::assign( $uid, sanitize_key( (string) $request['role'] ) );
		}
		if ( null !== $request['tax_exempt'] ) {
			$v = sanitize_key( (string) $request['tax_exempt'] );
			if ( in_array( $v, array( 'yes', 'no' ), true ) ) {
				update_user_meta( $uid, '_dev3bq_tax_exempt', $v );
			} else {
				delete_user_meta( $uid, '_dev3bq_tax_exempt' );
			}
		}
		if ( null !== $request['note'] ) {
			update_user_meta( $uid, '_dev3bq_note', mb_substr( sanitize_textarea_field( (string) $request['note'] ), 0, 1000 ) );
		}
		return $this->respond( Roles::user_row( $uid ) );
	}

	/**
	 * GET /quotes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function quotes( \WP_REST_Request $request ) {
		$res         = Quotes::query(
			array(
				'status'   => sanitize_key( (string) $request['status'] ),
				'search'   => sanitize_text_field( (string) $request['search'] ),
				'page'     => (int) $request['page'],
				'per_page' => (int) ( $request['per_page'] ? $request['per_page'] : 20 ),
			)
		);
		$res['rows'] = array_map( array( __CLASS__, 'brief' ), $res['rows'] );
		return $this->respond( $res );
	}

	/**
	 * POST /quotes (admin creates a quote).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_quote( \WP_REST_Request $request ) {
		$uid  = (int) $request['user_id'];
		$user = $uid ? get_userdata( $uid ) : null;
		$id   = Quotes::create(
			array(
				'user_id' => $user ? $user->ID : 0,
				'email'   => $user ? $user->user_email : (string) $request['email'],
				'name'    => $request['name'] ? (string) $request['name'] : ( $user ? $user->display_name : '' ),
				'company' => $request['company'] ? (string) $request['company'] : ( $user ? (string) get_user_meta( $user->ID, 'billing_company', true ) : '' ),
				'phone'   => (string) $request['phone'],
				'request' => (string) $request['request'],
				'status'  => 'draft',
				'source'  => 'admin',
			),
			is_array( $request['items'] ) ? $request['items'] : array()
		);
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		Quotes::add_event( $id, 'status', 'admin', __( 'Quote created by the store.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
		return $this->respond( self::present( Quotes::get( $id ) ), 201 );
	}

	/**
	 * GET /quotes/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function quote( \WP_REST_Request $request ) {
		$q = $this->quote_or_error( $request );
		return is_wp_error( $q ) ? $this->respond( $q ) : $this->respond( self::present( $q ) );
	}

	/**
	 * POST /quotes/<id> (save fields and lines).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_quote( \WP_REST_Request $request ) {
		$q = $this->quote_or_error( $request );
		if ( is_wp_error( $q ) ) {
			return $this->respond( $q );
		}
		if ( in_array( $q['status'], array( 'accepted', 'cancelled' ), true ) ) {
			return $this->respond( new \WP_Error( 'dev3bq_quote_locked', __( 'Accepted or cancelled quotes cannot be edited. Duplicate it instead.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) ) );
		}
		$p      = (array) $request->get_json_params();
		$fields = array();
		foreach ( array( 'name', 'company', 'phone' ) as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$fields[ $k ] = mb_substr( sanitize_text_field( (string) $p[ $k ] ), 0, 150 );
			}
		}
		if ( isset( $p['email'] ) && is_email( $p['email'] ) ) {
			$fields['email'] = sanitize_email( $p['email'] );
		}
		foreach ( array( 'request', 'message', 'admin_note', 'terms' ) as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$fields[ $k ] = mb_substr( sanitize_textarea_field( (string) $p[ $k ] ), 0, 4000 );
			}
		}
		foreach ( array( 'shipping', 'discount' ) as $k ) {
			if ( isset( $p[ $k ] ) ) {
				$fields[ $k ] = Engine::round( (float) $p[ $k ] );
			}
		}
		if ( isset( $p['tax_mode'] ) && in_array( $p['tax_mode'], Quotes::TAX_MODES, true ) ) {
			$fields['tax_mode'] = $p['tax_mode'];
		}
		if ( isset( $p['valid_until'] ) ) {
			$ts                    = strtotime( (string) $p['valid_until'] );
			$fields['valid_until'] = $ts ? gmdate( 'Y-m-d', $ts ) : null;
		}
		if ( isset( $p['role'] ) && ( '' === $p['role'] || Roles::get( sanitize_key( $p['role'] ) ) ) ) {
			$fields['role'] = sanitize_key( $p['role'] );
		}
		if ( 'requested' === $q['status'] ) {
			$fields['status'] = 'draft';
		}
		if ( $fields ) {
			Quotes::update( $q['id'], $fields );
		}
		if ( isset( $p['items'] ) && is_array( $p['items'] ) ) {
			Quotes::set_items( $q['id'], $p['items'] );
		}
		if ( ! empty( $p['ai_accepted'] ) ) {
			Quotes::add_event( $q['id'], 'ai', 'admin', __( 'DEV3 AI draft accepted.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) );
		}
		Quotes::recalc( $q['id'] );
		return $this->respond( self::present( Quotes::get( $q['id'] ) ) );
	}

	/**
	 * POST /quotes/<id>/send { message }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function send( \WP_REST_Request $request ) {
		$q = $this->quote_or_error( $request );
		if ( is_wp_error( $q ) ) {
			return $this->respond( $q );
		}
		$res = Service::send( $q['id'], null !== $request['message'] ? (string) $request['message'] : null );
		return is_wp_error( $res ) ? $this->respond( $res ) : $this->respond( self::present( $res ) );
	}

	/**
	 * POST /quotes/<id>/order (admin converts).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function to_order( \WP_REST_Request $request ) {
		$q = $this->quote_or_error( $request );
		if ( is_wp_error( $q ) ) {
			return $this->respond( $q );
		}
		$order = Service::accept( $q['id'], 'admin' );
		return is_wp_error( $order ) ? $this->respond( $order ) : $this->respond( self::present( Quotes::get( $q['id'] ) ) );
	}

	/**
	 * POST /quotes/<id>/status { status: draft|declined|cancelled }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function status( \WP_REST_Request $request ) {
		$q = $this->quote_or_error( $request );
		if ( is_wp_error( $q ) ) {
			return $this->respond( $q );
		}
		$to = sanitize_key( (string) $request['status'] );
		$ok = false;
		if ( 'declined' === $to ) {
			$ok = Service::decline( $q['id'], (string) $request['reason'], 'admin' );
			$ok = ! is_wp_error( $ok );
		} elseif ( 'cancelled' === $to ) {
			$ok = Quotes::transition( $q['id'], 'cancelled', array( 'requested', 'draft', 'sent', 'expired', 'declined' ) );
		} elseif ( 'draft' === $to ) {
			$ok = Quotes::transition( $q['id'], 'draft', array( 'requested', 'sent', 'expired', 'declined' ) );
		}
		if ( ! $ok ) {
			return $this->respond( new \WP_Error( 'dev3bq_quote_locked', __( 'The quote cannot move to that status.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) ) );
		}
		$labels = Quotes::status_labels();
		if ( 'declined' !== $to ) {
			/* translators: %s: status */
			Quotes::add_event( $q['id'], 'status', 'admin', sprintf( __( 'Status changed to %s.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $labels[ $to ] ) );
		}
		return $this->respond( self::present( Quotes::get( $q['id'] ) ) );
	}

	/**
	 * POST /quotes/<id>/duplicate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function duplicate( \WP_REST_Request $request ) {
		$id = Service::duplicate( (int) $request['id'] );
		return is_wp_error( $id ) ? $this->respond( $id ) : $this->respond( self::present( Quotes::get( $id ) ), 201 );
	}

	/**
	 * POST /quotes/<id>/message { message }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function message( \WP_REST_Request $request ) {
		$q = $this->quote_or_error( $request );
		if ( is_wp_error( $q ) ) {
			return $this->respond( $q );
		}
		$ok = Service::message( $q['id'], (string) $request['message'], 'admin' );
		return is_wp_error( $ok ) ? $this->respond( $ok ) : $this->respond( self::present( Quotes::get( $q['id'] ) ) );
	}

	/**
	 * POST /quotes/<id>/reprice.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reprice( \WP_REST_Request $request ) {
		$q = $this->quote_or_error( $request );
		if ( is_wp_error( $q ) ) {
			return $this->respond( $q );
		}
		Quotes::reprice( $q['id'] );
		return $this->respond( self::present( Quotes::get( $q['id'] ) ) );
	}

	/**
	 * POST /quotes/<id>/ai-draft { text } (AI, nothing saved).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_draft( \WP_REST_Request $request ) {
		return $this->respond( Assistant::draft_quote( (int) $request['id'], (string) $request['text'] ) );
	}

	/**
	 * POST /quotes/<id>/ai-email { kind, points } (AI, nothing sent).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_email( \WP_REST_Request $request ) {
		$q = $this->quote_or_error( $request );
		if ( is_wp_error( $q ) ) {
			return $this->respond( $q );
		}
		$kind = sanitize_key( (string) $request['kind'] );
		if ( in_array( $kind, array( 'approved', 'declined' ), true ) ) {
			$kind = 'cover';
		}
		return $this->respond( Assistant::write_email( $kind ? $kind : 'cover', $q['id'], (string) $request['points'] ) );
	}

	/**
	 * Page list for the settings pickers.
	 *
	 * @return array
	 */
	private static function page_list() {
		$out = array();
		foreach ( get_pages(
			array(
				'number'      => 300,
				'post_status' => 'publish,draft',
			)
		) as $page ) {
			$out[] = array(
				'id'    => (int) $page->ID,
				'title' => '' !== $page->post_title ? $page->post_title : '#' . $page->ID,
			);
		}
		return $out;
	}

	/**
	 * GET /pages.
	 *
	 * @return \WP_REST_Response
	 */
	public function pages() {
		return $this->respond( array( 'pages' => self::page_list() ) );
	}

	/**
	 * POST /setup-page { kind: quote|quick_order|registration } creates a page with the shortcode.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function setup_page( \WP_REST_Request $request ) {
		$kinds = array(
			'quote'        => array( __( 'Request a quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '[dev3bq_request_quote]' ),
			'quick_order'  => array( __( 'Quick order', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '[dev3bq_quick_order]' ),
			'registration' => array( __( 'Wholesale registration', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '[dev3bq_wholesale_registration]' ),
		);
		$kind  = (string) $request['kind'];
		if ( ! isset( $kinds[ $kind ] ) || ! current_user_can( 'publish_pages' ) ) {
			return $this->respond( new \WP_Error( 'dev3bq_bad_page', __( 'That page cannot be created.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $kinds[ $kind ][0],
				'post_content' => '<!-- wp:shortcode -->' . $kinds[ $kind ][1] . '<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		return $this->respond(
			array(
				'id'    => (int) $id,
				'pages' => self::page_list(),
			),
			201
		);
	}
}
