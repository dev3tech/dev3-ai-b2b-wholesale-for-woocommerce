<?php
/**
 * Quote PDF layout.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Quotes;

use Dev3\AiB2bWholesale\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a quote as a PDF.
 */
class Pdf_Quote {

	const M      = 48;
	const ACCENT = '#4f46e5';
	const INK    = '#111827';
	const MUTED  = '#4b5563';
	const LINE   = '#e5e7eb';

	/**
	 * Money for the PDF (currency code when the symbol is not in the font).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function money( $amount, $currency ) {
		$num    = number_format( (float) $amount, wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator() );
		$symbol = html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' );
		if ( ! Pdf::encodable( $symbol ) ) {
			return $currency . ' ' . $num;
		}
		$pos = get_option( 'woocommerce_currency_pos', 'left' );
		switch ( $pos ) {
			case 'right':
				return $num . $symbol;
			case 'right_space':
				return $num . ' ' . $symbol;
			case 'left_space':
				return $symbol . ' ' . $num;
			default:
				return $symbol . $num;
		}
	}

	/**
	 * Store address lines.
	 *
	 * @return string[]
	 */
	private static function store_lines() {
		$custom = trim( (string) Settings::get( 'store_address', '' ) );
		if ( '' !== $custom ) {
			return array_filter( array_map( 'trim', explode( "\n", $custom ) ) );
		}
		$parts = array(
			get_option( 'woocommerce_store_address', '' ),
			get_option( 'woocommerce_store_address_2', '' ),
			trim( get_option( 'woocommerce_store_postcode', '' ) . ' ' . get_option( 'woocommerce_store_city', '' ) ),
		);
		$base  = function_exists( 'WC' ) ? WC()->countries->get_base_country() : '';
		$names = function_exists( 'WC' ) ? WC()->countries->get_countries() : array();
		if ( $base && isset( $names[ $base ] ) ) {
			$parts[] = html_entity_decode( $names[ $base ], ENT_QUOTES, 'UTF-8' );
		}
		$parts[] = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
		return array_values( array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * Date in the site format.
	 *
	 * @param string $mysql Date.
	 * @return string
	 */
	private static function date( $mysql ) {
		if ( ! $mysql ) {
			return '';
		}
		return date_i18n( get_option( 'date_format', 'F j, Y' ), strtotime( $mysql ) );
	}

	/**
	 * Builds the PDF.
	 *
	 * @param array $quote Quote row.
	 * @return string PDF bytes.
	 */
	public static function render( array $quote ) {
		$items = Quotes::items( $quote['id'] );
		/**
		 * Filters the data printed on the quote PDF.
		 *
		 * @param array $data { quote, items, store_name, store_lines, terms }.
		 */
		$data  = (array) apply_filters(
			'dev3bq_pdf_data',
			array(
				'quote'       => $quote,
				'items'       => $items,
				'store_name'  => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'store_lines' => self::store_lines(),
				'terms'       => '' !== trim( $quote['terms'] ) ? $quote['terms'] : Settings::text( 'quote_terms' ),
			)
		);
		$quote = $data['quote'];
		$items = $data['items'];
		$cur   = $quote['currency'] ? $quote['currency'] : get_woocommerce_currency();
		/* translators: %s: quote number */
		$pdf = new Pdf( sprintf( __( 'Quote %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $quote['number'] ) );
		$pdf->add_page();
		$m     = self::M;
		$right = Pdf::W - $m;

		// Header.
		$pdf->rect( 0, 0, Pdf::W, 6, self::ACCENT );
		$pdf->text( $m, 64, $data['store_name'], 18, array( 'bold' => true ) );
		$y = 82;
		foreach ( array_slice( $data['store_lines'], 0, 6 ) as $line ) {
			$pdf->text( $m, $y, $line, 9, array( 'color' => self::MUTED ) );
			$y += 12;
		}
		$pdf->text(
			$right,
			64,
			__( 'QUOTE', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			22,
			array(
				'bold'  => true,
				'align' => 'right',
				'color' => self::ACCENT,
			)
		);
		$meta = array(
			__( 'Quote number', 'dev3-ai-b2b-wholesale-for-woocommerce' ) => $quote['number'],
			__( 'Date', 'dev3-ai-b2b-wholesale-for-woocommerce' )         => self::date( $quote['sent_at'] ? $quote['sent_at'] : $quote['created_at'] ),
			__( 'Valid until', 'dev3-ai-b2b-wholesale-for-woocommerce' )  => self::date( $quote['valid_until'] ),
		);
		$my   = 84;
		foreach ( $meta as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$pdf->text(
				$right - 110,
				$my,
				$label,
				9,
				array(
					'color' => self::MUTED,
					'align' => 'right',
				)
			);
			$pdf->text(
				$right,
				$my,
				$value,
				9,
				array(
					'bold'  => true,
					'align' => 'right',
				)
			);
			$my += 13;
		}

		// Customer.
		$y = max( $y, $my ) + 18;
		$pdf->text(
			$m,
			$y,
			__( 'PREPARED FOR', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			8,
			array(
				'bold'  => true,
				'color' => self::MUTED,
			)
		);
		$y += 14;
		foreach ( array_filter( array( $quote['name'], $quote['company'], $quote['email'], $quote['phone'] ) ) as $i => $line ) {
			$pdf->text( $m, $y, $line, 0 === $i ? 11 : 10, array( 'bold' => 0 === $i ) );
			$y += 14;
		}

		// Lines.
		$y        += 14;
		$cols      = array(
			'item'  => $m + 8,
			'qty'   => $right - 190,
			'unit'  => $right - 90,
			'total' => $right - 8,
		);
		$head      = static function ( $pdf, $y ) use ( $m, $right, $cols ) {
			$pdf->rect( $m, $y, $right - $m, 22, '#f3f4f6' );
			$pdf->text( $cols['item'], $y + 15, __( 'Item', 'dev3-ai-b2b-wholesale-for-woocommerce' ), 9, array( 'bold' => true ) );
			$pdf->text(
				$cols['qty'],
				$y + 15,
				__( 'Qty', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				9,
				array(
					'bold'  => true,
					'align' => 'right',
				)
			);
			$pdf->text(
				$cols['unit'],
				$y + 15,
				__( 'Unit price', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				9,
				array(
					'bold'  => true,
					'align' => 'right',
				)
			);
			$pdf->text(
				$cols['total'],
				$y + 15,
				__( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				9,
				array(
					'bold'  => true,
					'align' => 'right',
				)
			);
			return $y + 22;
		};
		$y         = $head( $pdf, $y );
		$name_w    = $cols['qty'] - 40 - $cols['item'];
		$page_foot = Pdf::H - 70;
		foreach ( $items as $item ) {
			$lines = Pdf::wrap( $item['name'], $name_w, 10, true );
			$sub   = array();
			if ( '' !== $item['sku'] ) {
				/* translators: %s: SKU */
				$sub[] = sprintf( __( 'SKU %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $item['sku'] );
			}
			if ( '' !== $item['note'] ) {
				$sub = array_merge( $sub, Pdf::wrap( $item['note'], $name_w, 8.5 ) );
			}
			$h = 10 + count( $lines ) * 13 + count( $sub ) * 11 + 6;
			if ( $y + $h > $page_foot ) {
				$pdf->add_page();
				$y = $head( $pdf, $m );
			}
			$ty = $y + 17;
			foreach ( $lines as $line ) {
				$pdf->text( $cols['item'], $ty, $line, 10, array( 'bold' => true ) );
				$ty += 13;
			}
			foreach ( $sub as $line ) {
				$pdf->text( $cols['item'], $ty - 2, $line, 8.5, array( 'color' => self::MUTED ) );
				$ty += 11;
			}
			$pdf->text( $cols['qty'], $y + 17, (string) $item['qty'], 10, array( 'align' => 'right' ) );
			$pdf->text( $cols['unit'], $y + 17, self::money( $item['unit_price'], $cur ), 10, array( 'align' => 'right' ) );
			$pdf->text( $cols['total'], $y + 17, self::money( $item['line_total'], $cur ), 10, array( 'align' => 'right' ) );
			$y += $h;
			$pdf->line( $m, $y, $right, $y, self::LINE );
		}

		// Totals.
		$rows = array( array( __( 'Subtotal', 'dev3-ai-b2b-wholesale-for-woocommerce' ), self::money( $quote['subtotal'], $cur ), false ) );
		if ( $quote['discount'] > 0 ) {
			$rows[] = array( __( 'Discount', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '-' . self::money( $quote['discount'], $cur ), false );
		}
		if ( $quote['shipping'] > 0 ) {
			$rows[] = array( __( 'Shipping', 'dev3-ai-b2b-wholesale-for-woocommerce' ), self::money( $quote['shipping'], $cur ), false );
		}
		if ( 'exempt' === $quote['tax_mode'] ) {
			$rows[] = array( __( 'Tax', 'dev3-ai-b2b-wholesale-for-woocommerce' ), __( 'Exempt', 'dev3-ai-b2b-wholesale-for-woocommerce' ), false );
		} elseif ( $quote['tax'] > 0 ) {
			$rows[] = array( __( 'Estimated tax', 'dev3-ai-b2b-wholesale-for-woocommerce' ), self::money( $quote['tax'], $cur ), false );
		}
		$rows[] = array( __( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ), self::money( $quote['total'], $cur ), true );
		if ( $y + 26 + count( $rows ) * 18 > $page_foot ) {
			$pdf->add_page();
			$y = $m;
		}
		$y += 22;
		foreach ( $rows as $r ) {
			if ( $r[2] ) {
				$pdf->rect( $right - 220, $y - 13, 220, 24, '#eef2ff' );
			}
			$pdf->text( $right - 208, $y + 3, $r[0], $r[2] ? 11 : 10, array( 'bold' => $r[2] ) );
			$pdf->text(
				$right - 8,
				$y + 3,
				$r[1],
				$r[2] ? 12 : 10,
				array(
					'bold'  => $r[2],
					'align' => 'right',
				)
			);
			$y += $r[2] ? 26 : 18;
		}

		// Message + terms.
		foreach ( array(
			__( 'Message', 'dev3-ai-b2b-wholesale-for-woocommerce' ) => $quote['message'],
			__( 'Terms', 'dev3-ai-b2b-wholesale-for-woocommerce' )   => $data['terms'],
		) as $label => $text ) {
			$text = trim( (string) $text );
			if ( '' === $text ) {
				continue;
			}
			$lines = Pdf::wrap( $text, $right - $m, 9 );
			$y    += 18;
			if ( $y + 16 + count( $lines ) * 12 > $page_foot ) {
				$pdf->add_page();
				$y = $m;
			}
			$pdf->text(
				$m,
				$y,
				strtoupper( $label ),
				8,
				array(
					'bold'  => true,
					'color' => self::MUTED,
				)
			);
			$y += 14;
			foreach ( $lines as $line ) {
				$pdf->text( $m, $y, $line, 9, array( 'color' => self::INK ) );
				$y += 12;
			}
		}

		// Footer on every page.
		$count = $pdf->page_count();
		for ( $i = 0; $i < $count; $i++ ) {
			$pdf->set_page( $i );
			$pdf->line( $m, Pdf::H - 44, $right, Pdf::H - 44, self::LINE );
			$pdf->text( $m, Pdf::H - 30, $data['store_name'] . ' · ' . $quote['number'], 8, array( 'color' => self::MUTED ) );
			$pdf->text(
				$right,
				Pdf::H - 30,
				/* translators: 1: page, 2: pages */
				sprintf( __( 'Page %1$d of %2$d', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $i + 1, $count ),
				8,
				array(
					'color' => self::MUTED,
					'align' => 'right',
				)
			);
		}
		return $pdf->output();
	}

	/**
	 * Writes the PDF to a private temp file (email attachment).
	 *
	 * @param array $quote Quote.
	 * @return string Path ('' on failure).
	 */
	public static function to_file( array $quote ) {
		$dir = trailingslashit( get_temp_dir() ) . 'dev3bq-' . wp_hash( 'dev3bq-pdf' );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$path = $dir . '/' . sanitize_file_name( 'quote-' . $quote['number'] . '.pdf' );
		return false !== file_put_contents( $path, self::render( $quote ) ) ? $path : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Admin download URL (nonce bound to the admin session).
	 *
	 * @param array $quote Quote.
	 * @return string
	 */
	public static function admin_url( array $quote ) {
		return add_query_arg(
			array(
				'dev3bq_pdf' => rawurlencode( $quote['number'] ),
				'_wpnonce'   => wp_create_nonce( 'dev3bq_pdf_' . $quote['id'] ),
			),
			admin_url( 'admin-post.php?action=dev3bq_pdf' )
		);
	}

	/**
	 * Customer download URL (access key).
	 *
	 * @param array $quote Quote.
	 * @return string
	 */
	public static function customer_url( array $quote ) {
		return add_query_arg(
			array(
				'dev3bq_pdf' => rawurlencode( $quote['number'] ),
				'key'        => $quote['access_key'],
			),
			home_url( '/' )
		);
	}
}
