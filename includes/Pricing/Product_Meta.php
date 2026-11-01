<?php
/**
 * Product edit screen: "Wholesale" product data tab and variation fields.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Pricing;

use Dev3\AiB2bWholesale\Wholesale\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Product meta UI + saving.
 */
class Product_Meta {

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
		add_action( 'admin_head', array( $this, 'icon_css' ) );
	}

	/**
	 * "Breaks" text ("50:7.5, 100:6") or array → [{min, price}].
	 *
	 * @param mixed $raw Raw.
	 * @return array
	 */
	public static function clean_breaks( $raw ) {
		$pairs = array();
		if ( is_string( $raw ) ) {
			foreach ( preg_split( '/[,;\n]+/', $raw ) as $part ) {
				$bits = array_map( 'trim', explode( ':', $part ) );
				if ( 2 === count( $bits ) ) {
					$pairs[] = array(
						'min'   => $bits[0],
						'price' => $bits[1],
					);
				}
			}
		} elseif ( is_array( $raw ) ) {
			$pairs = $raw;
		}
		$out = array();
		foreach ( $pairs as $p ) {
			if ( ! is_array( $p ) || ! isset( $p['min'], $p['price'] ) || ! is_numeric( $p['price'] ) ) {
				continue;
			}
			$min = (int) $p['min'];
			if ( $min < 2 ) {
				continue;
			}
			$out[ $min ] = array(
				'min'   => $min,
				'price' => max( 0, round( (float) wc_format_decimal( $p['price'] ), 4 ) ),
			);
		}
		ksort( $out );
		return array_values( array_slice( $out, 0, 12 ) );
	}

	/**
	 * Breaks → editable text.
	 *
	 * @param array $breaks Breaks.
	 * @return string
	 */
	public static function breaks_text( $breaks ) {
		return implode(
			', ',
			array_map(
				static fn( $b ) => $b['min'] . ':' . wc_format_localized_price( $b['price'] ),
				self::clean_breaks( $breaks )
			)
		);
	}

	/**
	 * Tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function tab( $tabs ) {
		$tabs['dev3bq_wholesale'] = array(
			'label'    => __( 'Wholesale', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'target'   => 'dev3bq_wholesale_data',
			'class'    => array(),
			'priority' => 65,
		);
		return $tabs;
	}

	/**
	 * Tab icon.
	 */
	public function icon_css() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'product' === $screen->id ) {
			echo '<style>#woocommerce-product-data ul.wc-tabs li.dev3bq_wholesale_options a::before{content:"\f307";font-family:dashicons;}.dev3bq-var-prices{display:flex;flex-wrap:wrap;gap:0 12px}.dev3bq-var-prices .form-row{flex:1 1 160px}</style>';
		}
	}

	/**
	 * Panel.
	 */
	public function panel() {
		global $post;
		$id     = $post ? (int) $post->ID : 0;
		$prices = (array) get_post_meta( $id, Engine::META_PRICES, true );
		$breaks = (array) get_post_meta( $id, Engine::META_BREAKS, true );
		echo '<div id="dev3bq_wholesale_data" class="panel woocommerce_options_panel hidden">';
		wp_nonce_field( 'dev3bq_product', 'dev3bq_product_nonce' );
		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'          => 'dev3bq_visibility',
				'label'       => __( 'Who can buy', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				'value'       => (string) get_post_meta( $id, Engine::META_VISIBILITY, true ),
				'options'     => array(
					''          => __( 'Everyone', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
					'wholesale' => __( 'Wholesale customers only', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
					'retail'    => __( 'Retail customers only', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				),
				'desc_tip'    => true,
				'description' => __( 'Hidden products disappear from the shop, search and related products for everyone else.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => 'dev3bq_min_qty',
				'label'             => __( 'Wholesale minimum quantity', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				'type'              => 'number',
				'value'             => (string) get_post_meta( $id, Engine::META_MIN_QTY, true ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => 'dev3bq_step',
				'label'             => __( 'Wholesale pack size (step)', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				'type'              => 'number',
				'value'             => (string) get_post_meta( $id, Engine::META_STEP, true ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
				'description'       => __( 'Wholesale buyers order in multiples of this number (e.g. 6 per carton).', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			)
		);
		echo '</div>';
		foreach ( Roles::all() as $role ) {
			echo '<div class="options_group">';
			echo '<p class="form-field"><strong>' . esc_html( $role['name'] ) . '</strong></p>';
			woocommerce_wp_text_input(
				array(
					'id'          => 'dev3bq_price_' . $role['key'],
					'name'        => 'dev3bq_prices[' . $role['key'] . ']',
					/* translators: %s: currency symbol */
					'label'       => sprintf( __( 'Fixed price (%s)', 'dev3-ai-b2b-wholesale-for-woocommerce' ), get_woocommerce_currency_symbol() ),
					'data_type'   => 'price',
					'value'       => isset( $prices[ $role['key'] ] ) ? wc_format_localized_price( $prices[ $role['key'] ] ) : '',
					'placeholder' => __( 'Use pricing rules', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
					'desc_tip'    => true,
					'description' => __( 'Leave empty to use the pricing rules and the role discount.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'          => 'dev3bq_breaks_' . $role['key'],
					'name'        => 'dev3bq_breaks[' . $role['key'] . ']',
					'label'       => __( 'Quantity breaks', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
					'value'       => isset( $breaks[ $role['key'] ] ) ? self::breaks_text( $breaks[ $role['key'] ] ) : '',
					'placeholder' => '50:7.50, 100:6',
					'desc_tip'    => true,
					'description' => __( 'Comma-separated "minimum quantity:unit price" pairs. Used with the fixed price above.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				)
			);
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Saves the panel.
	 *
	 * @param \WC_Product $product Product.
	 */
	public function save( $product ) {
		if ( ! isset( $_POST['dev3bq_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dev3bq_product_nonce'] ) ), 'dev3bq_product' ) ) {
			return;
		}
		$vis = isset( $_POST['dev3bq_visibility'] ) ? sanitize_key( wp_unslash( $_POST['dev3bq_visibility'] ) ) : '';
		$product->update_meta_data( Engine::META_VISIBILITY, in_array( $vis, array( 'wholesale', 'retail' ), true ) ? $vis : '' );
		$product->update_meta_data( Engine::META_MIN_QTY, isset( $_POST['dev3bq_min_qty'] ) ? max( 0, (int) $_POST['dev3bq_min_qty'] ) : 0 );
		$product->update_meta_data( Engine::META_STEP, isset( $_POST['dev3bq_step'] ) ? max( 0, (int) $_POST['dev3bq_step'] ) : 0 );
		$prices = isset( $_POST['dev3bq_prices'] ) ? map_deep( wp_unslash( (array) $_POST['dev3bq_prices'] ), 'sanitize_text_field' ) : array();
		$breaks = isset( $_POST['dev3bq_breaks'] ) ? map_deep( wp_unslash( (array) $_POST['dev3bq_breaks'] ), 'sanitize_text_field' ) : array();
		$product->update_meta_data( Engine::META_PRICES, self::clean_prices( $prices ) );
		$product->update_meta_data( Engine::META_BREAKS, self::clean_role_breaks( $breaks ) );
		Engine::flush();
	}

	/**
	 * {role: price} cleaned.
	 *
	 * @param array $prices Raw.
	 * @return array
	 */
	public static function clean_prices( array $prices ) {
		$out = array();
		foreach ( $prices as $role => $price ) {
			$role  = sanitize_key( $role );
			$price = wc_format_decimal( (string) $price );
			if ( Roles::get( $role ) && '' !== $price && is_numeric( $price ) ) {
				$out[ $role ] = max( 0, (float) $price );
			}
		}
		return $out;
	}

	/**
	 * {role: breaks} cleaned.
	 *
	 * @param array $breaks Raw.
	 * @return array
	 */
	public static function clean_role_breaks( array $breaks ) {
		$out = array();
		foreach ( $breaks as $role => $raw ) {
			$role  = sanitize_key( $role );
			$clean = self::clean_breaks( $raw );
			if ( Roles::get( $role ) && $clean ) {
				$out[ $role ] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Variation fields.
	 *
	 * @param int      $loop      Index.
	 * @param array    $data      Data.
	 * @param \WP_Post $variation Variation post.
	 */
	public function variation_fields( $loop, $data, $variation ) {
		unset( $data );
		$prices = (array) get_post_meta( $variation->ID, Engine::META_PRICES, true );
		echo '<div class="dev3bq-var-prices">';
		foreach ( Roles::all() as $role ) {
			woocommerce_wp_text_input(
				array(
					'id'            => 'dev3bq_var_price_' . $role['key'] . '_' . $loop,
					'name'          => 'dev3bq_var_prices[' . $loop . '][' . $role['key'] . ']',
					/* translators: 1: role name, 2: currency symbol */
					'label'         => sprintf( __( '%1$s price (%2$s)', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $role['name'], get_woocommerce_currency_symbol() ),
					'data_type'     => 'price',
					'value'         => isset( $prices[ $role['key'] ] ) ? wc_format_localized_price( $prices[ $role['key'] ] ) : '',
					'placeholder'   => __( 'Parent / rules', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
					'wrapper_class' => 'form-row',
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Saves variation fields.
	 *
	 * @param int $variation_id Variation.
	 * @param int $loop         Index.
	 */
	public function save_variation( $variation_id, $loop ) {
		if ( ! current_user_can( 'edit_product', $variation_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the save-variations nonce before this hook runs.
		$all = isset( $_POST['dev3bq_var_prices'] ) ? map_deep( wp_unslash( (array) $_POST['dev3bq_var_prices'] ), 'sanitize_text_field' ) : array();
		$raw = isset( $all[ $loop ] ) && is_array( $all[ $loop ] ) ? $all[ $loop ] : array();
		update_post_meta( $variation_id, Engine::META_PRICES, self::clean_prices( $raw ) );
		Engine::flush();
	}
}
