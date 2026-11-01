<?php
/**
 * Base for quote emails.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

use Dev3\AiB2bWholesale\Quotes\Quotes;
use Dev3\AiB2bWholesale\Quotes\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Quote email.
 */
abstract class Email_Quote_Base extends Email_Base {

	/**
	 * Quote.
	 *
	 * @var array|null
	 */
	public $quote = null;

	/**
	 * Placeholders.
	 */
	public function __construct() {
		$this->placeholders = array(
			'{quote_number}' => '',
			'{customer}'     => '',
		);
		parent::__construct();
	}

	/**
	 * Loads the quote.
	 *
	 * @param int $id Quote.
	 * @return bool
	 */
	protected function load( $id ) {
		$this->quote = Quotes::get( $id );
		if ( ! $this->quote ) {
			return false;
		}
		$this->object                         = $this->quote;
		$this->placeholders['{quote_number}'] = $this->quote['number'];
		$this->placeholders['{customer}']     = '' !== $this->quote['company'] ? $this->quote['company'] : $this->quote['name'];
		if ( $this->is_customer_email() ) {
			$this->recipient = $this->quote['email'];
		}
		return true;
	}

	/**
	 * Customer link.
	 *
	 * @return string
	 */
	protected function customer_url() {
		return Service::customer_url( $this->quote );
	}

	/**
	 * Admin link.
	 *
	 * @return string
	 */
	protected function admin_url() {
		return admin_url( 'admin.php?page=' . DEV3BQ_SLUG . '#/quotes/' . (int) $this->quote['id'] );
	}

	/**
	 * Whether prices are shown in the body.
	 *
	 * @return bool
	 */
	protected function show_prices() {
		return true;
	}

	/**
	 * Lines + totals.
	 *
	 * @return array
	 */
	protected function body() {
		$prices = $this->show_prices();
		$items  = array();
		foreach ( Quotes::items( $this->quote['id'] ) as $item ) {
			$items[] = array(
				'name'  => $item['name'] . ( '' !== $item['sku'] ? ' (' . $item['sku'] . ')' : '' ),
				'qty'   => $item['qty'],
				'unit'  => $prices ? wc_price( $item['unit_price'], array( 'currency' => $this->quote['currency'] ) ) : '',
				'total' => $prices ? wc_price( $item['line_total'], array( 'currency' => $this->quote['currency'] ) ) : '',
			);
		}
		$totals = array();
		if ( $prices && $items ) {
			$cur      = array( 'currency' => $this->quote['currency'] );
			$totals[] = array( __( 'Subtotal', 'dev3-ai-b2b-wholesale-for-woocommerce' ), wc_price( $this->quote['subtotal'], $cur ) );
			if ( $this->quote['discount'] > 0 ) {
				$totals[] = array( __( 'Discount', 'dev3-ai-b2b-wholesale-for-woocommerce' ), '-' . wc_price( $this->quote['discount'], $cur ) );
			}
			if ( $this->quote['shipping'] > 0 ) {
				$totals[] = array( __( 'Shipping', 'dev3-ai-b2b-wholesale-for-woocommerce' ), wc_price( $this->quote['shipping'], $cur ) );
			}
			if ( $this->quote['tax'] > 0 ) {
				$totals[] = array( __( 'Estimated tax', 'dev3-ai-b2b-wholesale-for-woocommerce' ), wc_price( $this->quote['tax'], $cur ) );
			}
			$totals[] = array( __( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ), wc_price( $this->quote['total'], $cur ) );
		}
		$details = array();
		if ( $this->quote['valid_until'] && $prices ) {
			$details[ __( 'Valid until', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ] = date_i18n( get_option( 'date_format' ), strtotime( $this->quote['valid_until'] ) );
		}
		if ( ! $this->is_customer_email() ) {
			$details[ __( 'Customer', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ] = trim( $this->quote['name'] . ' ' . ( '' !== $this->quote['company'] ? '(' . $this->quote['company'] . ')' : '' ) . ' · ' . $this->quote['email'] );
			if ( '' !== $this->quote['request'] && 'request' === $this->event ) {
				$details[ __( 'Request', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ] = $this->quote['request'];
			}
		}
		return array(
			'items'   => $items,
			'totals'  => $totals,
			'details' => $details,
		);
	}
}
