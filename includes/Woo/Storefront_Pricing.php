<?php
/**
 * Storefront pricing: price HTML per role, cart prices with quantity breaks, guest price hiding,
 * wholesale-only visibility and the quantity-break table.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Woo;

use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Settings;
use Dev3\AiB2bWholesale\Wholesale\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront pricing hooks.
 */
class Storefront_Pricing {

	/**
	 * Set true around internal queries that must see every product.
	 *
	 * @var bool
	 */
	public static $bypass = false;

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'variation_data' ), 20, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'variation_hash' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'cart_prices' ), 20 );
		add_filter( 'woocommerce_quantity_input_args', array( $this, 'qty_args' ), 20, 2 );
		add_filter( 'woocommerce_product_is_visible', array( $this, 'is_visible' ), 20, 2 );
		add_action( 'pre_get_posts', array( $this, 'hide_from_queries' ) );
		add_filter( 'woocommerce_related_products', array( $this, 'filter_ids' ), 20 );
		add_action( 'template_redirect', array( $this, 'guard_single' ) );
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'break_table' ) );
	}

	/**
	 * Whether we are rendering for shoppers (front end, AJAX or REST) rather than an admin screen.
	 *
	 * @return bool
	 */
	public static function storefront() {
		return ! is_admin() || wp_doing_ajax();
	}

	/**
	 * Prices hidden for this visitor.
	 *
	 * @return bool
	 */
	public static function prices_hidden() {
		return 'hide' === Settings::get( 'guest_prices', 'show' ) && ! is_user_logged_in();
	}

	/**
	 * Price HTML.
	 *
	 * @param string      $html    HTML.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function price_html( $html, $product ) {
		if ( ! self::storefront() || ! $product instanceof \WC_Product ) {
			return $html;
		}
		if ( self::prices_hidden() ) {
			return self::login_html();
		}
		$role = Roles::user_role();
		if ( $product->is_type( 'variable' ) ) {
			return $this->variable_html( $html, $product, $role );
		}
		if ( '' === $product->get_price() ) {
			return $html;
		}
		$res = Engine::price( $product, $role, 1 );
		if ( 'none' === $res['source'] ) {
			return $html;
		}
		return self::format( $product, $res, $role );
	}

	/**
	 * "Log in to see prices" link.
	 *
	 * @return string
	 */
	public static function login_html() {
		$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
		return '<a class="dev3bq-login-price" href="' . esc_url( $url ) . '">' . esc_html( Settings::text( 'guest_price_text' ) ) . '</a>';
	}

	/**
	 * Formats a computed price.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $res     Engine result.
	 * @param string      $role    Role.
	 * @return string
	 */
	public static function format( $product, array $res, $role ) {
		$price   = wc_get_price_to_display( $product, array( 'price' => $res['price'] ) );
		$regular = wc_get_price_to_display( $product, array( 'price' => $res['regular'] ) );
		$def     = '' !== $role ? Roles::get( $role ) : null;
		$retail  = Settings::get( 'show_retail_price', true ) && ( ! $def || $def['show_retail'] );
		$html    = $retail && $regular > $price ? wc_format_sale_price( $regular, $price ) : wc_price( $price );
		$html   .= $product->get_price_suffix( $res['price'] );
		if ( '' !== $res['label'] ) {
			$html .= ' <small class="dev3bq-price-label">' . esc_html( $res['label'] ) . '</small>';
		}
		return '<span class="dev3bq-price">' . $html . '</span>';
	}

	/**
	 * Variable product range.
	 *
	 * @param string               $html    Original.
	 * @param \WC_Product_Variable $product Product.
	 * @param string               $role    Role.
	 * @return string
	 */
	private function variable_html( $html, $product, $role ) {
		$prices  = array();
		$changed = false;
		foreach ( $product->get_visible_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child || '' === $child->get_price() ) {
				continue;
			}
			$res      = Engine::price( $child, $role, 1 );
			$changed  = $changed || 'none' !== $res['source'];
			$prices[] = wc_get_price_to_display( $child, array( 'price' => $res['price'] ) );
		}
		if ( ! $changed || ! $prices ) {
			return $html;
		}
		$min   = min( $prices );
		$max   = max( $prices );
		$out   = $min === $max ? wc_price( $min ) : wc_format_price_range( $min, $max );
		$def   = '' !== $role ? Roles::get( $role ) : null;
		$label = $def ? ( '' !== $def['label'] ? $def['label'] : __( 'Wholesale price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) : '';
		return '<span class="dev3bq-price">' . $out . $product->get_price_suffix() . ( $label ? ' <small class="dev3bq-price-label">' . esc_html( $label ) . '</small>' : '' ) . '</span>';
	}

	/**
	 * Purchasable for this visitor.
	 *
	 * @param bool        $ok      Purchasable.
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public function purchasable( $ok, $product ) {
		if ( ! $ok || ! self::storefront() ) {
			return $ok;
		}
		if ( self::prices_hidden() ) {
			return false;
		}
		return Engine::visible_for( $product, Roles::user_role() );
	}

	/**
	 * Variation JSON on the product page.
	 *
	 * @param array                 $data      Data.
	 * @param \WC_Product_Variable  $product   Parent.
	 * @param \WC_Product_Variation $variation Variation.
	 * @return array
	 */
	public function variation_data( $data, $product, $variation ) {
		unset( $product );
		if ( self::prices_hidden() ) {
			$data['price_html'] = self::login_html();
			return $data;
		}
		$role = Roles::user_role();
		$res  = Engine::price( $variation, $role, 1 );
		if ( 'none' !== $res['source'] ) {
			$data['display_price'] = wc_get_price_to_display( $variation, array( 'price' => $res['price'] ) );
			$data['price_html']    = '<span class="price">' . self::format( $variation, $res, $role ) . '</span>';
		}
		$q = Engine::qty_rules( $variation, $role );
		if ( $q['min'] > 1 ) {
			$data['min_qty'] = $q['min'];
		}
		return $data;
	}

	/**
	 * Variation price cache varies by role and visibility.
	 *
	 * @param array $hash Hash parts.
	 * @return array
	 */
	public function variation_hash( $hash ) {
		$hash[] = 'dev3bq:' . Roles::user_role() . ':' . ( self::prices_hidden() ? 'h' : 's' ) . ':' . get_option( 'dev3bq_price_version', 1 );
		return $hash;
	}

	/**
	 * Cart prices with quantity breaks.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function cart_prices( $cart ) {
		if ( ! $cart instanceof \WC_Cart || ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {
			return;
		}
		$role   = Roles::user_role();
		$parent = 'parent' === Settings::get( 'break_count', 'line' );
		$counts = array();
		foreach ( $cart->get_cart() as $item ) {
			$pid            = (int) $item['product_id'];
			$counts[ $pid ] = ( isset( $counts[ $pid ] ) ? $counts[ $pid ] : 0 ) + (int) $item['quantity'];
		}
		foreach ( $cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$qty = $parent ? $counts[ (int) $item['product_id'] ] : (int) $item['quantity'];
			$res = Engine::price( $product, $role, $qty );
			if ( 'none' !== $res['source'] ) {
				$product->set_price( $res['price'] );
			}
		}
	}

	/**
	 * Quantity input min/step for wholesale buyers.
	 *
	 * @param array       $args    Args.
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public function qty_args( $args, $product ) {
		if ( ! $product instanceof \WC_Product || ! self::storefront() ) {
			return $args;
		}
		$q = Engine::qty_rules( $product, Roles::user_role() );
		if ( $q['step'] > 1 ) {
			$args['step'] = $q['step'];
		}
		if ( $q['min'] > 1 ) {
			$args['min_value'] = $q['min'];
			if ( ! is_cart() && ( empty( $args['input_value'] ) || (int) $args['input_value'] < $q['min'] ) ) {
				$args['input_value'] = $q['min'];
			}
		}
		return $args;
	}

	/**
	 * Catalog visibility.
	 *
	 * @param bool $visible    Visible.
	 * @param int  $product_id Product.
	 * @return bool
	 */
	public function is_visible( $visible, $product_id ) {
		if ( ! $visible || ! self::storefront() ) {
			return $visible;
		}
		$product = wc_get_product( $product_id );
		return $product ? Engine::visible_for( $product, Roles::user_role() ) : $visible;
	}

	/**
	 * Meta query that hides products this visitor may not see.
	 *
	 * @return array
	 */
	public static function visibility_meta_query() {
		$hidden = '' === Roles::user_role() ? 'wholesale' : 'retail';
		return array(
			'relation' => 'OR',
			array(
				'key'     => Engine::META_VISIBILITY,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Engine::META_VISIBILITY,
				'value'   => $hidden,
				'compare' => '!=',
			),
		);
	}

	/**
	 * Hides products from front-end product queries (shop, search, blocks, shortcodes).
	 *
	 * @param \WP_Query $query Query.
	 */
	public function hide_from_queries( $query ) {
		if ( is_admin() || self::$bypass || ! $query instanceof \WP_Query || current_user_can( 'edit_products' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}
		$type = (array) $query->get( 'post_type' );
		if ( ! in_array( 'product', $type, true ) && ! ( $query->is_main_query() && ( $query->is_search() || is_post_type_archive( 'product' ) || is_tax( get_object_taxonomies( 'product' ) ) ) ) ) {
			return;
		}
		if ( $query->is_singular() && $query->is_main_query() ) {
			return; // Single pages are guarded in template_redirect so the 404 is explicit.
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = self::visibility_meta_query();
		$query->set( 'meta_query', $meta );
	}

	/**
	 * Filters related product ids.
	 *
	 * @param array $ids Ids.
	 * @return array
	 */
	public function filter_ids( $ids ) {
		$role = Roles::user_role();
		return array_values(
			array_filter(
				(array) $ids,
				static function ( $id ) use ( $role ) {
					$p = wc_get_product( $id );
					return $p && Engine::visible_for( $p, $role );
				}
			)
		);
	}

	/**
	 * 404 for hidden single products.
	 */
	public function guard_single() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( $product && ! Engine::visible_for( $product, Roles::user_role() ) && ! current_user_can( 'edit_post', $product->get_id() ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Quantity-break table on the product page.
	 */
	public function break_table() {
		global $product;
		if ( ! $product instanceof \WC_Product || ! Settings::get( 'show_break_table', true ) || self::prices_hidden() || $product->is_type( 'variable' ) ) {
			return;
		}
		echo self::break_table_html( $product, Roles::user_role() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * Break table markup.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $role    Role.
	 * @return string
	 */
	public static function break_table_html( $product, $role ) {
		$res   = Engine::price( $product, $role, 1 );
		$tiers = $res['breaks'];
		if ( count( $tiers ) < 2 ) {
			return '';
		}
		$base = max( 0.0001, (float) $res['regular'] );
		$rows = '';
		$n    = count( $tiers );
		foreach ( $tiers as $i => $t ) {
			$to    = $i + 1 < $n ? (int) $tiers[ $i + 1 ]['min'] - 1 : 0;
			$range = $to ? ( $t['min'] === $to ? (string) $t['min'] : $t['min'] . '–' . $to ) : $t['min'] . '+';
			$save  = (int) round( ( 1 - $t['price'] / $base ) * 100 );
			$rows .= '<tr><th scope="row">' . esc_html( $range ) . '</th><td>' . wp_kses_post( wc_price( wc_get_price_to_display( $product, array( 'price' => $t['price'] ) ) ) ) . '</td><td>' . ( $save > 0 ? esc_html( $save . '%' ) : '&mdash;' ) . '</td></tr>';
		}
		return '<div class="dev3bq-breaks"><table class="dev3bq-breaks__table"><caption>' . esc_html__( 'Buy more, save more', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Quantity', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'Unit price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'You save', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
	}
}
