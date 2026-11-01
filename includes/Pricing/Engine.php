<?php
/**
 * Pricing engine: one price for (product, role, quantity), used by the cart, the storefront and quotes.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Pricing;

use Dev3\AiB2bWholesale\Wholesale\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Engine.
 */
class Engine {

	const META_PRICES     = '_dev3bq_prices';
	const META_BREAKS     = '_dev3bq_breaks';
	const META_VISIBILITY = '_dev3bq_visibility';
	const META_MIN_QTY    = '_dev3bq_min_qty';
	const META_STEP       = '_dev3bq_step';

	/**
	 * Memo.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Clears the memo.
	 */
	public static function flush() {
		self::$memo = array();
	}

	/**
	 * Store-rounded amount.
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	public static function round( $amount ) {
		return round( max( 0, (float) $amount ), function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );
	}

	/**
	 * Applies a discount.
	 *
	 * @param string $type   percent|fixed|price.
	 * @param float  $amount Amount.
	 * @param float  $base   Base price.
	 * @return float
	 */
	public static function apply( $type, $amount, $base ) {
		switch ( $type ) {
			case 'price':
				return self::round( $amount );
			case 'fixed':
				return self::round( $base - $amount );
			default:
				return self::round( $base * ( 1 - min( 100, max( 0, $amount ) ) / 100 ) );
		}
	}

	/**
	 * The product's own active price (sale price when on sale), never one we changed in the cart.
	 *
	 * @param \WC_Product $product Product.
	 * @return float
	 */
	public static function base_price( $product ) {
		$regular = $product->get_regular_price( 'edit' );
		$sale    = $product->get_sale_price( 'edit' );
		if ( '' !== $sale && $product->is_on_sale( 'edit' ) ) {
			return (float) $sale;
		}
		if ( '' !== $regular ) {
			return (float) $regular;
		}
		return (float) $product->get_price( 'edit' );
	}

	/**
	 * Product-level role prices / breaks (variation first, then its parent).
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $key     Meta key.
	 * @param string      $role    Role.
	 * @return mixed|null
	 */
	private static function product_meta( $product, $key, $role ) {
		foreach ( array( $product->get_id(), $product->get_parent_id() ) as $id ) {
			if ( ! $id ) {
				continue;
			}
			$map = get_post_meta( $id, $key, true );
			if ( is_array( $map ) && isset( $map[ $role ] ) && '' !== $map[ $role ] && array() !== $map[ $role ] ) {
				return $map[ $role ];
			}
		}
		return null;
	}

	/**
	 * Price for a role and quantity.
	 *
	 * @param \WC_Product|int $product Product or id.
	 * @param string          $role    Role key ('' = retail / guest).
	 * @param int             $qty     Quantity.
	 * @return array { price, base, regular, source (product|rule|role|none), rule_id, rule_name, break, label, breaks[{min,price}] }
	 */
	public static function price( $product, $role = '', $qty = 1 ) {
		$product = is_numeric( $product ) ? wc_get_product( (int) $product ) : $product;
		$qty     = max( 1, (int) $qty );
		if ( ! $product instanceof \WC_Product ) {
			return self::result( 0, 0, 0, 'none' );
		}
		$memo_key = $product->get_id() . '|' . $role . '|' . $qty;
		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}
		$base    = self::base_price( $product );
		$regular = '' !== $product->get_regular_price( 'edit' ) ? (float) $product->get_regular_price( 'edit' ) : $base;
		$out     = null;

		// 1. Product-level fixed price (+ its own breaks) for this role.
		if ( '' !== $role ) {
			$fixed = self::product_meta( $product, self::META_PRICES, $role );
			if ( null !== $fixed && is_numeric( $fixed ) ) {
				$tiers = array(
					array(
						'min'   => 1,
						'price' => self::round( $fixed ),
					),
				);
				$raw   = self::product_meta( $product, self::META_BREAKS, $role );
				foreach ( Product_Meta::clean_breaks( is_array( $raw ) ? $raw : array() ) as $b ) {
					$tiers[] = array(
						'min'   => $b['min'],
						'price' => self::round( $b['price'] ),
					);
				}
				$price = self::round( $fixed );
				$brk   = 0;
				foreach ( $tiers as $t ) {
					if ( $t['min'] > 1 && $qty >= $t['min'] ) {
						$price = $t['price'];
						$brk   = $t['min'];
					}
				}
				$out           = self::result( $price, $base, $regular, 'product', 0, '', $brk );
				$out['breaks'] = $tiers;
			}
		}

		// 2. Rules (highest priority wins; ties → lowest price).
		if ( null === $out ) {
			$best = null;
			foreach ( Rules::active() as $rule ) {
				if ( ! Rules::for_role( $rule, $role ) || ! Rules::covers( $rule, $product ) ) {
					continue;
				}
				$calc = self::rule_price( $rule, $base, $qty );
				if ( null === $best || $rule['priority'] > $best['rule']['priority'] || ( $rule['priority'] === $best['rule']['priority'] && $calc['price'] < $best['calc']['price'] ) ) {
					$best = array(
						'rule' => $rule,
						'calc' => $calc,
					);
				}
			}
			if ( $best ) {
				$out           = self::result( $best['calc']['price'], $base, $regular, 'rule', $best['rule']['id'], $best['rule']['name'], $best['calc']['break'] );
				$out['breaks'] = self::rule_tiers( $best['rule'], $base );
			}
		}

		// 3. Role default discount.
		if ( null === $out && '' !== $role ) {
			$def = Roles::get( $role );
			if ( $def && $def['discount'] > 0 ) {
				$out = self::result( self::apply( 'percent', $def['discount'], $base ), $base, $regular, 'role' );
			}
		}

		if ( null === $out ) {
			$out = self::result( self::round( $base ), $base, $regular, 'none' );
		}
		$def          = '' !== $role ? Roles::get( $role ) : null;
		$out['label'] = $def ? ( '' !== $def['label'] ? $def['label'] : __( 'Wholesale price', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ) : '';

		/**
		 * Filters the computed price.
		 *
		 * @param array       $out     Result.
		 * @param \WC_Product $product Product.
		 * @param string      $role    Role.
		 * @param int         $qty     Quantity.
		 */
		$out                     = (array) apply_filters( 'dev3bq_price', $out, $product, $role, $qty );
		self::$memo[ $memo_key ] = $out;
		return $out;
	}

	/**
	 * Rule price at a quantity.
	 *
	 * @param array $rule Rule.
	 * @param float $base Base.
	 * @param int   $qty  Qty.
	 * @return array { price, break }
	 */
	public static function rule_price( array $rule, $base, $qty ) {
		$price = self::apply( $rule['type'], $rule['amount'], $base );
		$brk   = 0;
		foreach ( $rule['breaks'] as $b ) {
			if ( $qty >= (int) $b['min'] ) {
				$price = self::apply( $b['type'], (float) $b['amount'], $base );
				$brk   = (int) $b['min'];
			}
		}
		return array(
			'price' => $price,
			'break' => $brk,
		);
	}

	/**
	 * Display tiers for a rule.
	 *
	 * @param array $rule Rule.
	 * @param float $base Base.
	 * @return array
	 */
	private static function rule_tiers( array $rule, $base ) {
		$tiers = array(
			array(
				'min'   => 1,
				'price' => self::apply( $rule['type'], $rule['amount'], $base ),
			),
		);
		foreach ( $rule['breaks'] as $b ) {
			$tiers[] = array(
				'min'   => (int) $b['min'],
				'price' => self::apply( $b['type'], (float) $b['amount'], $base ),
			);
		}
		return $tiers;
	}

	/**
	 * Result shape.
	 *
	 * @param float  $price     Price.
	 * @param float  $base      Base.
	 * @param float  $regular   Regular.
	 * @param string $source    Source.
	 * @param int    $rule_id   Rule.
	 * @param string $rule_name Rule name.
	 * @param int    $brk       Break min.
	 * @return array
	 */
	private static function result( $price, $base, $regular, $source, $rule_id = 0, $rule_name = '', $brk = 0 ) {
		return array(
			'price'     => (float) $price,
			'base'      => (float) $base,
			'regular'   => (float) $regular,
			'source'    => $source,
			'rule_id'   => (int) $rule_id,
			'rule_name' => (string) $rule_name,
			'break'     => (int) $brk,
			'label'     => '',
			'breaks'    => array(),
		);
	}

	/**
	 * Wholesale visibility of a product for a role.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $role    Role.
	 * @return bool
	 */
	public static function visible_for( $product, $role ) {
		$id  = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$vis = (string) get_post_meta( $id, self::META_VISIBILITY, true );
		if ( 'wholesale' === $vis ) {
			return '' !== $role;
		}
		if ( 'retail' === $vis ) {
			return '' === $role;
		}
		return true;
	}

	/**
	 * Minimum quantity and step for wholesale buyers.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $role    Role.
	 * @return array { min, step }
	 */
	public static function qty_rules( $product, $role ) {
		if ( '' === $role ) {
			return array(
				'min'  => 1,
				'step' => 1,
			);
		}
		$min  = 0;
		$step = 0;
		foreach ( array( $product->get_id(), $product->get_parent_id() ) as $id ) {
			if ( ! $id ) {
				continue;
			}
			$min  = $min ? $min : (int) get_post_meta( $id, self::META_MIN_QTY, true );
			$step = $step ? $step : (int) get_post_meta( $id, self::META_STEP, true );
		}
		$step = max( 1, $step );
		$min  = max( 1, $min, $step );
		// The minimum must itself be a whole number of packs.
		$min = (int) ( ceil( $min / $step ) * $step );
		return array(
			'min'  => $min,
			'step' => $step,
		);
	}
}
