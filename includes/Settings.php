<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * Wholesale roles live in their own option (see Wholesale\Roles), pricing rules in a table.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale;

use Dev3\AiB2bWholesale\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3bq_settings_defaults / dev3bq_settings_sanitize.
 */
class Settings {

	const GUEST_PRICES   = array( 'show', 'hide' );
	const QUOTE_BUTTON   = array( 'everyone', 'guests', 'wholesale', 'hidden_price', 'off' );
	const BREAK_COUNT    = array( 'line', 'parent' );
	const ACCOUNT_TIMING = array( 'now', 'on_approval' );
	const AI_TONES       = array( 'friendly', 'formal', 'concise' );

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'guest_prices'           => 'show',
				'guest_price_text'       => '',
				'guest_quote_instead'    => true,
				'show_retail_price'      => true,
				'show_break_table'       => true,
				'break_count'            => 'line',
				'retail_hidden_gateways' => array(),
				'retail_hidden_shipping' => array(),
				'quote_button'           => 'everyone',
				'cart_to_quote'          => true,
				'quote_page_id'          => 0,
				'quick_order_page_id'    => 0,
				'registration_page_id'   => 0,
				'quote_valid_days'       => 30,
				'quote_terms'            => '',
				'quote_prefix'           => 'Q-',
				'followup_days'          => 3,
				'expiry_email'           => true,
				'account_timing'         => 'now',
				'registration_open'      => true,
				'store_address'          => '',
				'ai_tone'                => 'friendly',
				'public_parse'           => true,
				'delete_on_uninstall'    => false,
			)
		);
	}

	/**
	 * Shorthand getter.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Shopper-facing text with its translated default (defaults stay empty so nothing is translated before init).
	 *
	 * @param string $key guest_price_text|quote_terms.
	 * @return string
	 */
	public static function text( $key ) {
		$value = trim( (string) self::get( $key, '' ) );
		if ( '' !== $value ) {
			return $value;
		}
		if ( 'guest_price_text' === $key ) {
			return __( 'Log in to see prices', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
		if ( 'quote_terms' === $key ) {
			return __( 'Prices are valid until the date shown. Shipping and taxes as listed. Accepting this quote creates an order that you can pay online.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
		return '';
	}

	/**
	 * URL of a configured page (or the shop when unset).
	 *
	 * @param string $key  quote_page_id|quick_order_page_id|registration_page_id.
	 * @param array  $args Query args.
	 * @return string
	 */
	public static function page_url( $key, array $args = array() ) {
		$id  = (int) self::get( $key, 0 );
		$url = $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : '';
		if ( ! $url ) {
			$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		}
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		foreach ( array( 'guest_quote_instead', 'show_retail_price', 'show_break_table', 'cart_to_quote', 'expiry_email', 'registration_open', 'public_parse', 'delete_on_uninstall' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}
		$enums = array(
			'guest_prices'   => self::GUEST_PRICES,
			'quote_button'   => self::QUOTE_BUTTON,
			'break_count'    => self::BREAK_COUNT,
			'account_timing' => self::ACCOUNT_TIMING,
			'ai_tone'        => self::AI_TONES,
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}
		foreach ( array( 'retail_hidden_gateways', 'retail_hidden_shipping' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $input[ $key ] ) ) ) );
			}
		}
		foreach ( array( 'quote_page_id', 'quick_order_page_id', 'registration_page_id' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = max( 0, (int) $input[ $key ] );
			}
		}
		if ( isset( $input['quote_valid_days'] ) ) {
			$out['quote_valid_days'] = min( 365, max( 1, (int) $input['quote_valid_days'] ) );
		}
		if ( isset( $input['followup_days'] ) ) {
			$out['followup_days'] = min( 60, max( 0, (int) $input['followup_days'] ) );
		}
		if ( isset( $input['guest_price_text'] ) ) {
			$out['guest_price_text'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['guest_price_text'] ) ), 0, 80 );
		}
		if ( isset( $input['quote_prefix'] ) ) {
			$out['quote_prefix'] = mb_substr( preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $input['quote_prefix'] ), 0, 8 );
		}
		foreach ( array(
			'quote_terms'   => 1000,
			'store_address' => 400,
		) as $key => $max ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input[ $key ] ) ), 0, $max );
			}
		}
		return $out;
	}
}
