<?php
/**
 * Customer email: we got your quote request.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Quote received.
 */
class Email_Customer_Quote_Received extends Email_Quote_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id             = 'dev3bq_customer_quote_received';
		$this->customer_email = true;
		$this->title          = __( 'Quote request received (customer)', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		$this->description    = __( 'Confirms a quote request to the customer.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		parent::__construct();
	}

	/**
	 * Subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'We received your quote request {quote_number}', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Thanks for your request', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * No prices yet.
	 *
	 * @return bool
	 */
	protected function show_prices() {
		return false;
	}

	/**
	 * Intro.
	 *
	 * @return string
	 */
	public function intro() {
		return __( 'We received your quote request and will send you a priced quote soon. Here is what you asked for:', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Button.
	 *
	 * @return array
	 */
	public function button() {
		return array(
			'url'  => $this->customer_url(),
			'text' => __( 'View your request', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}
}
