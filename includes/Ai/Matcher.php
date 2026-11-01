<?php
/**
 * Finds real catalogue products for free text: candidate retrieval for the AI and the non-AI list parser.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Ai;

use Dev3\AiB2bWholesale\Data\Text;
use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Woo\Storefront_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Matcher.
 */
class Matcher {

	const STOP = array( 'the', 'and', 'for', 'with', 'please', 'need', 'want', 'would', 'like', 'some', 'pcs', 'pieces', 'units', 'unit', 'each', 'per', 'box', 'boxes', 'our', 'you', 'can', 'quote', 'price', 'prices', 'also', 'about', 'from', 'that', 'this', 'have', 'send', 'deliver', 'delivery', 'shipping', 'ship', 'to', 'of', 'in', 'a', 'an', 'x', 'we', 'us', 'me', 'my', 'are', 'is', 'be', 'next', 'week', 'month', 'by', 'asap', 'thanks', 'thank', 'hello', 'hi' );

	/**
	 * Search tokens from text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function tokens( $text ) {
		$text  = strtolower( wp_strip_all_tags( (string) $text ) );
		$parts = preg_split( '/[^\p{L}\p{N}\-_]+/u', $text );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( $p, '-_' );
			if ( mb_strlen( $p ) < 3 || is_numeric( $p ) || in_array( $p, self::STOP, true ) ) {
				continue;
			}
			// Simple singular form so "mugs" finds "Mug".
			if ( mb_strlen( $p ) >= 4 && 's' === mb_substr( $p, -1 ) && 'ss' !== mb_substr( $p, -2 ) ) {
				$p = mb_substr( $p, 0, -1 );
			}
			$out[ $p ] = true;
		}
		return array_slice( array_keys( $out ), 0, 25 );
	}

	/**
	 * Product search (titles, content, SKU; variations included).
	 *
	 * @param string $term  Term.
	 * @param int    $limit Limit.
	 * @return int[]
	 */
	public static function search( $term, $limit = 20 ) {
		$store                      = \WC_Data_Store::load( 'product' );
		$prev                       = Storefront_Pricing::$bypass;
		Storefront_Pricing::$bypass = true;
		$ids                        = (array) $store->search_products( wc_clean( $term ), '', true, false, $limit );
		Storefront_Pricing::$bypass = $prev;
		$sku                        = wc_get_product_id_by_sku( wc_clean( $term ) );
		if ( $sku ) {
			array_unshift( $ids, $sku );
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Candidate products for a request, best first.
	 *
	 * @param string $text  Request text.
	 * @param string $role  Role (visibility).
	 * @param int    $limit Max candidates.
	 * @param bool   $storefront Only purchasable, visible products.
	 * @return array [ {id, name, sku, type, attributes, category, product} ]
	 */
	public static function candidates( $text, $role = '', $limit = 40, $storefront = false ) {
		$scores = array();
		$tokens = self::tokens( $text );
		foreach ( $tokens as $token ) {
			foreach ( self::search( $token, 25 ) as $i => $id ) {
				$scores[ $id ] = ( isset( $scores[ $id ] ) ? $scores[ $id ] : 0 ) + 10 - min( 9, $i / 3 );
			}
		}
		arsort( $scores );
		$out = array();
		foreach ( array_keys( $scores ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || 'publish' !== get_post_status( $product->get_parent_id() ? $product->get_parent_id() : $id ) ) {
				continue;
			}
			if ( $product->is_type( 'variable' ) ) {
				// Offer the variations instead of the parent.
				foreach ( array_slice( $product->get_children(), 0, 12 ) as $child_id ) {
					if ( ! isset( $out[ $child_id ] ) ) {
						$child = wc_get_product( $child_id );
						if ( $child && self::allowed( $child, $role, $storefront ) ) {
							$out[ $child_id ] = self::describe( $child );
						}
					}
				}
			} elseif ( self::allowed( $product, $role, $storefront ) && ! isset( $out[ $id ] ) ) {
				$out[ $id ] = self::describe( $product );
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return array_slice( array_values( $out ), 0, $limit );
	}

	/**
	 * Whether a product may be offered.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $role    Role.
	 * @param bool        $storefront  Storefront.
	 * @return bool
	 */
	private static function allowed( $product, $role, $storefront ) {
		if ( ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) && ! apply_filters( 'dev3bq_quote_product_type', false, $product ) ) {
			return false;
		}
		if ( ! Engine::visible_for( $product, $role ) ) {
			return false;
		}
		return ! $storefront || ( $product->is_purchasable() && 'private' !== get_post_status( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) );
	}

	/**
	 * Compact description for the AI.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public static function describe( $product ) {
		$attrs = array();
		if ( $product->is_type( 'variation' ) ) {
			foreach ( $product->get_variation_attributes( false ) as $key => $value ) {
				$label           = wc_attribute_label( str_replace( 'attribute_', '', $key ), $product );
				$term            = taxonomy_exists( $key ) ? get_term_by( 'slug', $value, $key ) : null;
				$attrs[ $label ] = $term instanceof \WP_Term ? Text::term_name( $term ) : $value;
			}
		}
		$cat_source = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		$cats       = $cat_source ? wp_get_post_terms( $cat_source->get_id(), 'product_cat', array( 'fields' => 'names' ) ) : array();
		return array(
			'id'         => $product->get_id(),
			'name'       => wp_strip_all_tags( $product->get_name() ),
			'sku'        => (string) $product->get_sku(),
			'type'       => $product->get_type(),
			'attributes' => $attrs,
			'category'   => is_array( $cats ) ? implode( ', ', array_map( array( Text::class, 'plain' ), array_slice( $cats, 0, 3 ) ) ) : '',
		);
	}

	/**
	 * Splits a list into item phrases with quantities ("120 kraft mugs, 30x A5 notebook").
	 *
	 * @param string $text Text.
	 * @return array [ {text, qty, qty_given} ]
	 */
	public static function split( $text ) {
		$text  = str_replace( array( "\r", '•', '·' ), array( '', "\n", "\n" ), (string) $text );
		$parts = preg_split( '/\n|;|,(?!\d)|\band\b|\bplus\b|\bund\b|\bet\b/iu', $text );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			$part = trim( preg_replace( '/^[\-\*\d]+[\.\)]\s+(?=\D)/u', '', trim( $part ) ) );
			// "NB-A5, 3": a bare number after a comma is the quantity of the previous item.
			if ( preg_match( '/^\d{1,6}$/', $part ) && $out && ! $out[ count( $out ) - 1 ]['qty_given'] ) {
				$out[ count( $out ) - 1 ]['qty']       = max( 1, min( 100000, (int) $part ) );
				$out[ count( $out ) - 1 ]['qty_given'] = true;
				continue;
			}
			if ( mb_strlen( $part ) < 2 ) {
				continue;
			}
			$qty   = 0;
			$label = $part;
			if ( preg_match( '/^(\d{1,6})\s*(?:x|×|pcs|pieces|units|stk|qty)?\.?\s+(.+)$/iu', $part, $m ) ) {
				$qty   = (int) $m[1];
				$label = $m[2];
			} elseif ( preg_match( '/^(.+?)\s*(?:[x×:\-=,]|qty|quantity)\s*(\d{1,6})\s*(?:pcs|pieces|units)?$/iu', $part, $m ) ) {
				$qty   = (int) $m[2];
				$label = $m[1];
			} elseif ( preg_match( '/^(.+?)\s+(\d{1,6})\s*(?:pcs|pieces|units)?$/iu', $part, $m ) ) {
				$qty   = (int) $m[2];
				$label = $m[1];
			}
			$label = trim( $label, " \t-:" );
			if ( '' === $label || ( ! self::tokens( $label ) && ! wc_get_product_id_by_sku( $label ) ) ) {
				continue;
			}
			$out[] = array(
				'text'      => mb_substr( $label, 0, 160 ),
				'qty'       => max( 1, min( 100000, $qty ) ),
				'qty_given' => $qty > 0,
			);
		}
		return array_slice( $out, 0, 50 );
	}

	/**
	 * Non-AI parser: one best product per phrase by SKU or name overlap.
	 *
	 * @param string $text   Text.
	 * @param string $role   Role.
	 * @param bool   $storefront Storefront.
	 * @return array { lines[{product_id, qty, text, confidence}], unmatched[] }
	 */
	public static function parse( $text, $role = '', $storefront = false ) {
		$lines     = array();
		$unmatched = array();
		foreach ( self::split( $text ) as $phrase ) {
			$best  = null;
			$score = 0;
			$sku   = wc_get_product_id_by_sku( $phrase['text'] );
			if ( $sku ) {
				$p = wc_get_product( $sku );
				if ( $p && self::allowed( $p, $role, $storefront ) ) {
					$best  = $p;
					$score = 1;
				}
			}
			if ( ! $best ) {
				$want = self::tokens( $phrase['text'] );
				foreach ( self::candidates( $phrase['text'], $role, 12, $storefront ) as $c ) {
					$have = self::tokens( $c['name'] . ' ' . implode( ' ', $c['attributes'] ) . ' ' . $c['sku'] );
					$hit  = $want ? count( array_intersect( $want, $have ) ) / count( $want ) : 0;
					if ( $hit > $score ) {
						$score = $hit;
						$best  = wc_get_product( $c['id'] );
					}
				}
			}
			if ( $best && $score >= 0.5 ) {
				$lines[] = array(
					'product_id' => $best->get_id(),
					'qty'        => $phrase['qty'],
					'text'       => $phrase['text'],
					'confidence' => $score >= 0.99 ? 'high' : ( $score >= 0.66 ? 'medium' : 'low' ),
				);
			} else {
				$unmatched[] = $phrase['text'];
			}
		}
		return array(
			'lines'     => $lines,
			'unmatched' => $unmatched,
		);
	}
}
