<?php
/**
 * Plain-text helpers for names that WordPress stores HTML-encoded.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Text.
 */
class Text {

	/**
	 * Plain text for JSON/React output (React escapes on render, so the server must not).
	 *
	 * WordPress stores term names (and kses-filtered product titles) with entities, e.g.
	 * "Mugs &amp; Drinkware"; names that were escaped before saving (imports, other plugins)
	 * can hold "&amp;amp;". Decoding until the value is stable turns both into "Mugs & Drinkware".
	 *
	 * @param string $text Stored text.
	 * @return string
	 */
	public static function plain( $text ) {
		$text = (string) $text;
		for ( $i = 0; $i < 3; $i++ ) {
			$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $text ) {
				break;
			}
			$text = $decoded;
		}
		return $text;
	}

	/**
	 * Plain name of a term.
	 *
	 * @param \WP_Term|mixed $term Term.
	 * @return string
	 */
	public static function term_name( $term ) {
		return $term instanceof \WP_Term ? self::plain( $term->name ) : '';
	}
}
