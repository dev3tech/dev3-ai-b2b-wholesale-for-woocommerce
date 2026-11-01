<?php
/**
 * Customer email: a message from the store about a quote.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Message.
 */
class Email_Customer_Quote_Message extends Email_Quote_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id             = 'dev3bq_customer_quote_message';
		$this->customer_email = true;
		$this->title          = __( 'Quote message (customer)', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		$this->description    = __( 'Sent when the store replies on a quote.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		parent::__construct();
	}

	/**
	 * Subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'New message about your quote {quote_number}', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'A message about your quote', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Lines only (the message is the point).
	 *
	 * @return bool
	 */
	protected function show_prices() {
		return 'sent' === $this->quote['status'];
	}

	/**
	 * Intro.
	 *
	 * @return string
	 */
	public function intro() {
		return __( 'We sent you a message about your quote:', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Button.
	 *
	 * @return array
	 */
	public function button() {
		return array(
			'url'  => $this->customer_url(),
			'text' => __( 'View and reply', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}
}
