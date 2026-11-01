<?php
/**
 * Storefront widgets: shortcodes, blocks, "Add to quote" and "Request a quote for this cart" buttons, PDF downloads.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Frontend;

use Dev3\AiB2bWholesale\Core3\Api\Ai;
use Dev3\AiB2bWholesale\Data\Text;
use Dev3\AiB2bWholesale\Quotes\Pdf_Quote;
use Dev3\AiB2bWholesale\Quotes\Quotes;
use Dev3\AiB2bWholesale\Quotes\Service;
use Dev3\AiB2bWholesale\Settings;
use Dev3\AiB2bWholesale\Wholesale\Applications;
use Dev3\AiB2bWholesale\Wholesale\Roles;
use Dev3\AiB2bWholesale\Woo\Storefront_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront.
 */
class Storefront {

	const HANDLE = 'dev3bq-storefront';

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'init', array( $this, 'blocks' ) );
		add_shortcode( 'dev3bq_quick_order', array( $this, 'quick_order' ) );
		add_shortcode( 'dev3bq_request_quote', array( $this, 'request_quote' ) );
		add_shortcode( 'dev3bq_wholesale_registration', array( $this, 'registration' ) );
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'product_button' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'product_button_unpurchasable' ), 31 );
		add_filter( 'render_block_woocommerce/add-to-cart-form', array( $this, 'block_product_button' ), 10, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'loop_link' ), 20, 2 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'cart_button' ), 30 );
		add_filter( 'render_block_woocommerce/cart', array( $this, 'block_cart_button' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'customer_pdf' ), 5 );
		add_action( 'admin_post_dev3bq_pdf', array( $this, 'admin_pdf' ) );
	}

	/**
	 * Registers the storefront bundle.
	 */
	public function register_assets() {
		$file = DEV3BQ_PATH . 'build/storefront.asset.php';
		if ( wp_script_is( self::HANDLE, 'registered' ) || ! is_readable( $file ) ) {
			return;
		}
		$asset = include $file;
		wp_register_script( self::HANDLE, DEV3BQ_URL . 'build/storefront.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'dev3-ai-b2b-wholesale-for-woocommerce', DEV3BQ_PATH . 'languages' );
		wp_register_style( self::HANDLE, DEV3BQ_URL . 'build/storefront.css', array(), $asset['version'] );
		wp_add_inline_script( self::HANDLE, 'window.dev3bqShop = ' . wp_json_encode( self::config() ) . ';', 'before' );
		if ( is_product() || is_cart() || is_shop() || is_product_taxonomy() ) {
			wp_enqueue_style( self::HANDLE );
			wp_enqueue_script( self::HANDLE );
		}
	}

	/**
	 * Boot config (no secrets; nonce only for logged-in shoppers).
	 *
	 * @return array
	 */
	public static function config() {
		$roles = array();
		foreach ( Applications::signup_roles() as $r ) {
			$roles[] = array(
				'value' => $r['key'],
				'label' => $r['name'],
			);
		}
		return array(
			'restUrl'      => esc_url_raw( rest_url( 'dev3bq/v1/' ) ),
			'nonce'        => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'loggedIn'     => is_user_logged_in(),
			'role'         => Roles::user_role(),
			'pricesHidden' => Storefront_Pricing::prices_hidden(),
			'quoteUrl'     => Settings::page_url( 'quote_page_id' ),
			'cartUrl'      => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			'accountUrl'   => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '',
			'aiParse'      => (bool) Settings::get( 'public_parse', true ) && Ai::enabled( 'storefront' ),
			'signupRoles'  => $roles,
			'countries'    => function_exists( 'WC' ) ? array_map( static fn( $n ) => html_entity_decode( $n, ENT_QUOTES, 'UTF-8' ), WC()->countries->get_countries() ) : array(),
			'baseCountry'  => function_exists( 'WC' ) ? WC()->countries->get_base_country() : '',
			'user'         => self::user_prefill(),
		);
	}

	/**
	 * Contact prefill for logged-in shoppers.
	 *
	 * @return array
	 */
	private static function user_prefill() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return array();
		}
		return array(
			'name'    => $user->display_name,
			'email'   => $user->user_email,
			'company' => (string) get_user_meta( $user->ID, 'billing_company', true ),
			'phone'   => (string) get_user_meta( $user->ID, 'billing_phone', true ),
		);
	}

	/**
	 * Enqueues the bundle.
	 */
	public static function enqueue() {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			( new self() )->register_assets();
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Mount point.
	 *
	 * @param string $mode  quick-order|request-quote|registration|quote-view.
	 * @param array  $attrs data-* attributes.
	 * @param string $inner Server-rendered fallback.
	 * @return string
	 */
	public static function mount( $mode, array $attrs = array(), $inner = '' ) {
		self::enqueue();
		$data = '';
		foreach ( $attrs as $k => $v ) {
			$data .= ' data-' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		$loading = '<div class="dev3bq-shop__loading" role="status"><span class="dev3bq-shop__spinner" aria-hidden="true"></span>' . esc_html__( 'Loading…', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</div>';
		return '<div class="dev3bq-shop dev3bq-shop--' . esc_attr( $mode ) . '" data-dev3bq="' . esc_attr( $mode ) . '"' . $data . '>' . ( '' !== $inner ? $inner : $loading ) . '<noscript><p>' . esc_html__( 'Please enable JavaScript to use this form.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</p></noscript></div>';
	}

	/**
	 * [dev3bq_quick_order category="" per_page="20"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function quick_order( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'per_page' => 20,
			),
			(array) $atts,
			'dev3bq_quick_order'
		);
		$cats = array();
		foreach ( (array) get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 100,
			)
		) as $t ) {
			if ( $t instanceof \WP_Term ) {
				$cats[] = array(
					'value' => $t->slug,
					'label' => Text::term_name( $t ),
				);
			}
		}
		return self::mount(
			'quick-order',
			array(
				'category'   => sanitize_title( $atts['category'] ),
				'per-page'   => min( 50, max( 5, (int) $atts['per_page'] ) ),
				'categories' => wp_json_encode( $cats ),
			)
		);
	}

	/**
	 * [dev3bq_request_quote] (also shows a quote when the page has ?dev3bq_quote=&key=).
	 *
	 * @return string
	 */
	public function request_quote() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view; access is checked with the quote key.
		$number = isset( $_GET['dev3bq_quote'] ) ? sanitize_text_field( wp_unslash( $_GET['dev3bq_quote'] ) ) : '';
		$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		if ( '' !== $number ) {
			return self::mount(
				'quote-view',
				array(
					'number' => $number,
					'key'    => $key,
				)
			);
		}
		return self::mount( 'request-quote' );
	}

	/**
	 * [dev3bq_wholesale_registration].
	 *
	 * @return string
	 */
	public function registration() {
		if ( '' !== Roles::user_role() ) {
			self::enqueue();
			return '<div class="dev3bq-shop"><div class="dev3bq-shop__card dev3bq-shop__done"><p class="dev3bq-shop__title">' . esc_html__( 'You have a wholesale account', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</p><p>' . esc_html__( 'Your wholesale prices are active whenever you are logged in.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</p><p><a class="dev3bq-shop__btn" href="' . esc_url( Settings::page_url( 'quick_order_page_id' ) ) . '">' . esc_html__( 'Start an order', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</a></p></div></div>';
		}
		if ( ! Settings::get( 'registration_open', true ) || ! Applications::signup_roles() ) {
			return '<p>' . esc_html__( 'Wholesale registration is closed at the moment.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</p>';
		}
		return self::mount( 'registration' );
	}

	/**
	 * Gutenberg blocks (server-rendered).
	 */
	public function blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$file = DEV3BQ_PATH . 'build/blocks.asset.php';
		if ( is_readable( $file ) ) {
			$asset = include $file;
			wp_register_script( 'dev3bq-blocks', DEV3BQ_URL . 'build/blocks.js', $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( 'dev3bq-blocks', 'dev3-ai-b2b-wholesale-for-woocommerce', DEV3BQ_PATH . 'languages' );
		}
		$common = array(
			'editor_script' => 'dev3bq-blocks',
			'supports'      => array(
				'html'  => false,
				'align' => array( 'wide', 'full' ),
			),
		);
		register_block_type(
			'dev3bq/quick-order',
			$common + array(
				'attributes'      => array(
					'category' => array(
						'type'    => 'string',
						'default' => '',
					),
					'perPage'  => array(
						'type'    => 'number',
						'default' => 20,
					),
				),
				'render_callback' => fn( $a ) => $this->quick_order(
					array(
						'category' => $a['category'] ?? '',
						'per_page' => $a['perPage'] ?? 20,
					)
				),
			)
		);
		register_block_type( 'dev3bq/request-quote', $common + array( 'render_callback' => fn() => $this->request_quote() ) );
		register_block_type( 'dev3bq/registration', $common + array( 'render_callback' => fn() => $this->registration() ) );
	}

	/**
	 * Whether the "Add to quote" button shows for a product.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public static function quote_button_for( $product ) {
		if ( ! $product instanceof \WC_Product || ! in_array( $product->get_type(), array( 'simple', 'variable' ), true ) ) {
			return false;
		}
		$role = Roles::user_role();
		$def  = '' !== $role ? Roles::get( $role ) : null;
		if ( $def && 'always' === $def['quote'] ) {
			return true;
		}
		if ( $def && 'never' === $def['quote'] ) {
			return false;
		}
		switch ( (string) Settings::get( 'quote_button', 'everyone' ) ) {
			case 'off':
				return false;
			case 'guests':
				return ! is_user_logged_in();
			case 'wholesale':
				return '' !== $role;
			case 'hidden_price':
				return Storefront_Pricing::prices_hidden() || ! $product->is_purchasable();
			default:
				return true;
		}
	}

	/**
	 * Button markup.
	 *
	 * @param \WC_Product $product Product.
	 * @param bool        $standalone Outside the add-to-cart form (adds its own qty box).
	 * @return string
	 */
	public static function button_html( $product, $standalone = false ) {
		self::enqueue();
		$qty = '';
		if ( $standalone ) {
			$qty = '<label class="dev3bq-quote-btn__qty"><span>' . esc_html__( 'Quantity', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</span><input type="number" min="1" step="1" value="1" inputmode="numeric" class="dev3bq-quote-qty"></label>';
		}
		$label = Storefront_Pricing::prices_hidden() && ! $product->is_purchasable() ? __( 'Request a quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Add to quote', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		return '<div class="dev3bq-quote-btn' . ( $standalone ? ' dev3bq-quote-btn--standalone' : '' ) . '" data-product="' . esc_attr( (string) $product->get_id() ) . '" data-variable="' . ( $product->is_type( 'variable' ) ? '1' : '0' ) . '">' . $qty . '<button type="button" class="dev3bq-add-quote button alt">' . esc_html( $label ) . '</button><span class="dev3bq-quote-btn__status" role="status" aria-live="polite"></span></div>';
	}

	/**
	 * Inside the add-to-cart form.
	 */
	public function product_button() {
		global $product;
		if ( $product instanceof \WC_Product && self::quote_button_for( $product ) ) {
			echo self::button_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button_html.
		}
	}

	/**
	 * Classic themes: products that cannot be added to the cart still get a quote button.
	 */
	public function product_button_unpurchasable() {
		global $product;
		if ( $product instanceof \WC_Product && ! $product->is_purchasable() && self::quote_button_for( $product ) && ! $product->is_type( 'variable' ) ) {
			echo self::button_html( $product, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button_html.
		}
	}

	/**
	 * Block themes: the add-to-cart block renders nothing for unpurchasable products.
	 *
	 * @param string $html  Block HTML.
	 * @param array  $block Block.
	 * @return string
	 */
	public function block_product_button( $html, $block ) {
		unset( $block );
		global $product;
		$p = $product instanceof \WC_Product ? $product : wc_get_product( get_the_ID() );
		if ( $p && ! $p->is_purchasable() && ! $p->is_type( 'variable' ) && self::quote_button_for( $p ) ) {
			$html .= self::button_html( $p, true );
		}
		return $html;
	}

	/**
	 * Shop loop: unpurchasable products link to the product page with "Request a quote".
	 *
	 * @param string      $link    Link.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function loop_link( $link, $product ) {
		if ( $product instanceof \WC_Product && ! $product->is_purchasable() && Storefront_Pricing::prices_hidden() && Settings::get( 'guest_quote_instead', true ) && self::quote_button_for( $product ) ) {
			return '<a href="' . esc_url( $product->get_permalink() ) . '" class="button dev3bq-loop-quote">' . esc_html__( 'Request a quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</a>';
		}
		return $link;
	}

	/**
	 * Cart button markup.
	 *
	 * @param bool $block After the Cart block (aligned with it).
	 * @return string
	 */
	public static function cart_button_html( $block = false ) {
		if ( ! Settings::get( 'cart_to_quote', true ) || 'off' === Settings::get( 'quote_button', 'everyone' ) || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}
		self::enqueue();
		return '<div class="dev3bq-cart-quote' . ( $block ? ' dev3bq-cart-quote--block alignwide' : '' ) . '"><button type="button" class="dev3bq-cart-to-quote button">' . esc_html__( 'Request a quote for this cart', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</button><span class="dev3bq-quote-btn__status" role="status" aria-live="polite"></span></div>';
	}

	/**
	 * Classic cart.
	 */
	public function cart_button() {
		echo self::cart_button_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * Block cart.
	 *
	 * @param string $html  HTML.
	 * @param array  $block Block.
	 * @return string
	 */
	public function block_cart_button( $html, $block ) {
		unset( $block );
		return $html . self::cart_button_html( true );
	}

	/**
	 * Streams a PDF.
	 *
	 * @param array $quote Quote.
	 */
	private static function stream_pdf( array $quote ) {
		$pdf = Pdf_Quote::render( $quote );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( 'quote-' . $quote['number'] . '.pdf' ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Robots-Tag: noindex' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/**
	 * Customer PDF: ?dev3bq_pdf=<number>&key=<key> (or the logged-in owner).
	 */
	public function customer_pdf() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- access is checked with the quote key / owner.
		if ( empty( $_GET['dev3bq_pdf'] ) ) {
			return;
		}
		$number = sanitize_text_field( wp_unslash( $_GET['dev3bq_pdf'] ) );
		$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		$quote = Quotes::get_by_number( $number );
		if ( ! $quote || ! Service::can_view( $quote, $key ) || ! in_array( $quote['status'], array( 'sent', 'accepted', 'declined', 'expired' ), true ) ) {
			wp_die( esc_html__( 'Quote not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '', array( 'response' => 404 ) );
		}
		self::stream_pdf( $quote );
	}

	/**
	 * Admin PDF (admin-post.php?action=dev3bq_pdf&dev3bq_pdf=<number>&_wpnonce=).
	 */
	public function admin_pdf() {
		$number = isset( $_GET['dev3bq_pdf'] ) ? sanitize_text_field( wp_unslash( $_GET['dev3bq_pdf'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below with the quote id.
		$quote  = Quotes::get_by_number( $number );
		if ( ! $quote || ! current_user_can( \Dev3\AiB2bWholesale\Core3\Core\Capabilities::manage() ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'dev3bq_pdf_' . $quote['id'] ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		self::stream_pdf( $quote );
	}
}
