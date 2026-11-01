<?php
/**
 * DEV3 AI features: quote drafts from request text, email writer, application screening and the
 * shopper list builder. Every result is a draft; nothing here saves quote lines.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Ai;

use Dev3\AiB2bWholesale\Core3\Api\Ai;
use Dev3\AiB2bWholesale\Core3\Api\Api_Error;
use Dev3\AiB2bWholesale\Core3\Api\Prompts;
use Dev3\AiB2bWholesale\Pricing\Engine;
use Dev3\AiB2bWholesale\Quotes\Quotes;
use Dev3\AiB2bWholesale\Settings;
use Dev3\AiB2bWholesale\Wholesale\Applications;
use Dev3\AiB2bWholesale\Wholesale\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Assistant.
 */
class Assistant {

	const EMAIL_KINDS = array( 'cover', 'followup', 'reply', 'decline', 'expired', 'approved', 'declined' );

	/**
	 * Store name.
	 *
	 * @return string
	 */
	private static function store() {
		return html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Drafts quote lines from the request text.
	 *
	 * @param int    $quote_id Quote.
	 * @param string $text     Text (defaults to the stored request).
	 * @return array|\WP_Error { lines, unmatched, questions, message, dropped, meta }
	 */
	public static function draft_quote( $quote_id, $text = '' ) {
		$quote = Quotes::get( $quote_id );
		if ( ! $quote ) {
			return Api_Error::make( 'dev3bq_no_quote', __( 'Quote not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '', 404 );
		}
		$text = trim( '' !== trim( (string) $text ) ? (string) $text : $quote['request'] );
		if ( '' === $text ) {
			return Api_Error::make( 'dev3bq_no_request', __( 'There is no request text to work from.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), __( 'Paste the customer\'s email or describe the items in the box first.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), 400 );
		}
		$candidates = Matcher::candidates( $text, $quote['role'], 40 );
		if ( ! $candidates ) {
			return Api_Error::make( 'dev3bq_no_candidates', __( 'No catalogue products match this request.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), __( 'Check the product names in the request, or add the lines by hand.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), 422 );
		}
		$res = Prompts::run(
			'dev3bq_quote_draft',
			array(
				'store'     => self::store(),
				'request'   => mb_substr( $text, 0, 4000 ),
				'catalogue' => $candidates,
			),
			'admin'
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data  = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		$valid = array_flip( wp_list_pluck( $candidates, 'id' ) );
		$lines = array();
		$drop  = array();
		$seen  = array();
		foreach ( (array) ( $data['lines'] ?? array() ) as $line ) {
			$pid = (int) ( $line['product_id'] ?? 0 );
			if ( ! isset( $valid[ $pid ] ) ) {
				$drop[] = $pid;
				continue;
			}
			$qty  = max( 1, min( 100000, (int) ( $line['qty'] ?? 1 ) ) );
			$norm = Quotes::normalize_line(
				array(
					'product_id' => $pid,
					'qty'        => $qty,
					'note'       => (string) ( $line['note'] ?? '' ),
				),
				$quote['role']
			);
			if ( ! $norm ) {
				$drop[] = $pid;
				continue;
			}
			$key = $norm['product_id'] . ':' . $norm['variation_id'];
			if ( isset( $seen[ $key ] ) ) {
				$lines[ $seen[ $key ] ]['qty']       += $norm['qty'];
				$lines[ $seen[ $key ] ]['line_total'] = Engine::round( $lines[ $seen[ $key ] ]['qty'] * $lines[ $seen[ $key ] ]['unit_price'] );
				continue;
			}
			$conf         = isset( $line['confidence'] ) && in_array( $line['confidence'], array( 'high', 'medium', 'low' ), true ) ? $line['confidence'] : 'medium';
			$seen[ $key ] = count( $lines );
			$lines[]      = $norm + array( 'confidence' => $conf );
		}
		// Re-price merged lines at their final quantity.
		foreach ( $lines as $i => $l ) {
			$re          = Quotes::normalize_line( $l, $quote['role'] );
			$lines[ $i ] = array_merge( $l, array_intersect_key( $re, array_flip( array( 'unit_price', 'line_total', 'regular_price' ) ) ) );
		}
		$unmatched = array();
		foreach ( (array) ( $data['unmatched'] ?? array() ) as $u ) {
			if ( is_array( $u ) && '' !== trim( (string) ( $u['text'] ?? '' ) ) ) {
				$unmatched[] = array(
					'text'   => mb_substr( sanitize_text_field( $u['text'] ), 0, 160 ),
					'reason' => mb_substr( sanitize_text_field( (string) ( $u['reason'] ?? '' ) ), 0, 200 ),
				);
			}
		}
		if ( $drop ) {
			$unmatched[] = array(
				/* translators: %d: number of suggestions */
				'text'   => sprintf( _n( '%d suggestion', '%d suggestions', count( $drop ), 'dev3-ai-b2b-wholesale-for-woocommerce' ), count( $drop ) ),
				'reason' => __( 'Removed because the product is not in your catalogue.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			);
		}
		return array(
			'lines'     => $lines,
			'unmatched' => $unmatched,
			'questions' => array_values( array_slice( array_map( 'sanitize_text_field', array_filter( (array) ( $data['questions'] ?? array() ), 'is_string' ) ), 0, 3 ) ),
			'message'   => sanitize_textarea_field( (string) ( $data['message'] ?? '' ) ),
			'dropped'   => count( $drop ),
			'meta'      => self::meta( $res ),
		);
	}

	/**
	 * Usage meta for the review UI.
	 *
	 * @param array $res Ai result.
	 * @return array
	 */
	private static function meta( array $res ) {
		return array(
			'model'  => (string) ( $res['model'] ?? '' ),
			'tokens' => (int) ( $res['usage']['total'] ?? 0 ),
			'cached' => ! empty( $res['cached'] ),
		);
	}

	/**
	 * Email kind labels for the prompt.
	 *
	 * @return array
	 */
	public static function kind_labels() {
		return array(
			'cover'    => 'Cover email sending a priced quote; invite the customer to review and accept online.',
			'followup' => 'Friendly follow-up on a quote the customer has not answered yet.',
			'reply'    => 'Reply to the customer\'s latest message on the quote.',
			'decline'  => 'Polite note that we cannot supply part or all of the request, offering alternatives from the facts if any.',
			'expired'  => 'The quote expired; offer to refresh it.',
			'approved' => 'Welcome email: the wholesale account application is approved.',
			'declined' => 'Polite decline of a wholesale account application.',
		);
	}

	/**
	 * Writes an email draft.
	 *
	 * @param string $kind   EMAIL_KINDS.
	 * @param int    $id     Quote id (or application id for approved/declined).
	 * @param string $points Optional points from the team.
	 * @return array|\WP_Error { subject, body, meta }
	 */
	public static function write_email( $kind, $id, $points = '' ) {
		if ( ! in_array( $kind, self::EMAIL_KINDS, true ) ) {
			return Api_Error::make( 'dev3bq_bad_kind', __( 'Unknown email type.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '', 400 );
		}
		$facts = in_array( $kind, array( 'approved', 'declined' ), true ) ? self::application_facts( $id ) : self::quote_facts( $id );
		if ( is_wp_error( $facts ) ) {
			return $facts;
		}
		$res = Prompts::run(
			'dev3bq_quote_email',
			array(
				'store'      => self::store(),
				'tone'       => Settings::get( 'ai_tone', 'friendly' ),
				'kind_label' => self::kind_labels()[ $kind ],
				'facts'      => $facts,
				'points'     => '' !== trim( (string) $points ) ? mb_substr( sanitize_textarea_field( $points ), 0, 1000 ) : '(none)',
			),
			'admin'
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		$body = trim( sanitize_textarea_field( (string) ( $data['body'] ?? '' ) ) );
		if ( '' === $body ) {
			return Api_Error::make( 'dev3bq_ai_empty', __( 'DEV3 AI returned an empty email. Try again.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '', 502 );
		}
		return array(
			'subject' => mb_substr( sanitize_text_field( (string) ( $data['subject'] ?? '' ) ), 0, 140 ),
			'body'    => $body,
			'meta'    => self::meta( $res ),
		);
	}

	/**
	 * Quote facts (minimal; no addresses).
	 *
	 * @param int $id Quote.
	 * @return array|\WP_Error
	 */
	private static function quote_facts( $id ) {
		$quote = Quotes::get( $id );
		if ( ! $quote ) {
			return Api_Error::make( 'dev3bq_no_quote', __( 'Quote not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '', 404 );
		}
		$cur   = $quote['currency'];
		$lines = array();
		foreach ( array_slice( Quotes::items( $id ), 0, 15 ) as $item ) {
			$lines[] = $item['qty'] . ' x ' . $item['name'] . ' @ ' . html_entity_decode( wp_strip_all_tags( wc_price( $item['unit_price'], array( 'currency' => $cur ) ) ), ENT_QUOTES );
		}
		$last = '';
		foreach ( array_reverse( Quotes::events( $id, true ) ) as $event ) {
			if ( 'message' === $event['type'] && 'customer' === $event['actor'] ) {
				$last = mb_substr( $event['message'], 0, 1200 );
				break;
			}
		}
		$name = trim( (string) strtok( $quote['name'], ' ' ) );
		return array(
			'quote_number'          => $quote['number'],
			'status'                => $quote['status'],
			'customer_first_name'   => $name,
			'company'               => $quote['company'],
			'lines'                 => $lines,
			'total'                 => html_entity_decode( wp_strip_all_tags( wc_price( $quote['total'], array( 'currency' => $cur ) ) ), ENT_QUOTES ),
			'shipping'              => $quote['shipping'] > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( $quote['shipping'], array( 'currency' => $cur ) ) ), ENT_QUOTES ) : 'not included',
			'valid_until'           => $quote['valid_until'] ? date_i18n( get_option( 'date_format' ), strtotime( $quote['valid_until'] ) ) : '',
			'original_request'      => mb_substr( $quote['request'], 0, 1200 ),
			'latest_customer_reply' => $last,
		);
	}

	/**
	 * Application facts.
	 *
	 * @param int $id Application.
	 * @return array|\WP_Error
	 */
	private static function application_facts( $id ) {
		$app = Applications::get( $id );
		if ( ! $app ) {
			return Api_Error::make( 'dev3bq_no_app', __( 'Application not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '', 404 );
		}
		$role = Roles::get( $app['role'] );
		return array(
			'customer_first_name' => $app['first_name'],
			'company'             => $app['company'],
			'account_type'        => $role ? $role['name'] : $app['role'],
			'role_discount'       => $role && $role['discount'] ? $role['discount'] . '%' : '',
			'minimum_order'       => $role && $role['min_value'] ? html_entity_decode( wp_strip_all_tags( wc_price( $role['min_value'] ) ), ENT_QUOTES ) : '',
			'account_url'         => wc_get_page_permalink( 'myaccount' ),
			'reason'              => $app['reason'],
		);
	}

	/**
	 * Screens an application.
	 *
	 * @param int $id Application.
	 * @return array|\WP_Error { summary, suggested_role, signals, questions, meta }
	 */
	public static function screen_application( $id ) {
		$app = Applications::get( $id );
		if ( ! $app ) {
			return Api_Error::make( 'dev3bq_no_app', __( 'Application not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '', 404 );
		}
		$roles = array();
		foreach ( Roles::all() as $r ) {
			$roles[] = $r['key'] . ': ' . $r['name'];
		}
		$domain = substr( strrchr( $app['email'], '@' ), 1 );
		$res    = Prompts::run(
			'dev3bq_application_screen',
			array(
				'application' => array(
					'company'        => $app['company'],
					'email_domain'   => $domain,
					'website'        => $app['website'],
					'country'        => $app['country'],
					'tax_id'         => $app['tax_id'],
					'requested_role' => $app['role'],
					'message'        => mb_substr( $app['message'], 0, 1500 ),
					'has_phone'      => '' !== $app['phone'],
				),
				'roles'       => implode( "\n", $roles ),
			),
			'admin'
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data    = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		$role    = sanitize_key( (string) ( $data['suggested_role'] ?? '' ) );
		$signals = array();
		foreach ( (array) ( $data['signals'] ?? array() ) as $s ) {
			if ( is_array( $s ) && '' !== trim( (string) ( $s['label'] ?? '' ) ) ) {
				$signals[] = array(
					'label' => mb_substr( sanitize_text_field( $s['label'] ), 0, 160 ),
					'tone'  => in_array( $s['tone'] ?? '', array( 'good', 'neutral', 'warning' ), true ) ? $s['tone'] : 'neutral',
				);
			}
		}
		$out = array(
			'summary'        => mb_substr( sanitize_text_field( (string) ( $data['summary'] ?? '' ) ), 0, 400 ),
			'suggested_role' => Roles::get( $role ) ? $role : $app['role'],
			'signals'        => array_slice( $signals, 0, 5 ),
			'questions'      => array_values( array_slice( array_map( 'sanitize_text_field', array_filter( (array) ( $data['questions'] ?? array() ), 'is_string' ) ), 0, 3 ) ),
			'at'             => gmdate( 'c' ),
		);
		Applications::save_ai( $id, $out );
		$out['meta'] = self::meta( $res );
		return $out;
	}

	/**
	 * Shopper list builder: AI when allowed, else (or on any error) the non-AI parser.
	 *
	 * @param string $text Text.
	 * @param string $role Role.
	 * @return array { lines[{product_id, qty}], unmatched[], ai (bool) }
	 */
	public static function parse_for_shopper( $text, $role ) {
		$text = mb_substr( trim( sanitize_textarea_field( (string) $text ) ), 0, 2000 );
		if ( '' === $text ) {
			return array(
				'lines'     => array(),
				'unmatched' => array(),
				'ai'        => false,
			);
		}
		$fallback = static function () use ( $text, $role ) {
			$res       = Matcher::parse( $text, $role, true );
			$res['ai'] = false;
			return $res;
		};
		if ( ! Settings::get( 'public_parse', true ) || ! Ai::enabled( 'storefront' ) ) {
			return $fallback();
		}
		$candidates = Matcher::candidates( $text, $role, 30, true );
		if ( ! $candidates ) {
			return $fallback();
		}
		$args = Prompts::build(
			'dev3bq_shopper_parse',
			array(
				'request'   => $text,
				'catalogue' => $candidates,
			)
		);
		if ( is_wp_error( $args ) ) {
			return $fallback();
		}
		$res = Ai::run_or(
			'shopper_parse',
			$args,
			'storefront',
			static function () use ( $fallback ) {
				return $fallback();
			}
		);
		if ( isset( $res['ai'] ) && false === $res['ai'] ) {
			return $res;
		}
		$data  = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		$valid = array_flip( wp_list_pluck( $candidates, 'id' ) );
		$lines = array();
		foreach ( (array) ( $data['lines'] ?? array() ) as $line ) {
			$pid = (int) ( $line['product_id'] ?? 0 );
			if ( isset( $valid[ $pid ] ) ) {
				$lines[] = array(
					'product_id' => $pid,
					'qty'        => max( 1, min( 100000, (int) ( $line['qty'] ?? 1 ) ) ),
				);
			}
		}
		if ( ! $lines ) {
			return $fallback();
		}
		return array(
			'lines'     => $lines,
			'unmatched' => array_values( array_slice( array_map( 'sanitize_text_field', array_filter( (array) ( $data['unmatched'] ?? array() ), 'is_string' ) ), 0, 20 ) ),
			'ai'        => true,
		);
	}
}
