<?php
/**
 * Customer email: your quote is ready (PDF attached).
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

use Dev3\AiB2bWholesale\Quotes\Pdf_Quote;

defined( 'ABSPATH' ) || exit;

/**
 * Quote ready.
 */
class Email_Customer_Quote_Ready extends Email_Quote_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id             = 'dev3bq_customer_quote_ready';
		$this->customer_email = true;
		$this->title          = __( 'Quote ready (customer)', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		$this->description    = __( 'Sends the priced quote with the PDF and an Accept link.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		parent::__construct();
	}

	/**
	 * Subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your quote {quote_number} from {site_title}', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your quote is ready', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Loads + attaches the PDF.
	 *
	 * @param int $id Quote.
	 * @return bool
	 */
	protected function load( $id ) {
		if ( ! parent::load( $id ) ) {
			return false;
		}
		if ( 'yes' === $this->get_option( 'attach_pdf', 'yes' ) ) {
			$file = Pdf_Quote::to_file( $this->quote );
			if ( $file ) {
				$this->files[] = $file;
			}
		}
		$this->args['message'] = $this->quote['message'];
		return true;
	}

	/**
	 * Intro.
	 *
	 * @return string
	 */
	public function intro() {
		return __( 'Here is your quote. Review the lines below (the PDF is attached), then accept it online to turn it into an order you can pay right away.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Button.
	 *
	 * @return array
	 */
	public function button() {
		return array(
			'url'  => $this->customer_url(),
			'text' => __( 'Review and accept', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}

	/**
	 * PDF option.
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields['attach_pdf'] = array(
			'title'   => __( 'Attach PDF', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'type'    => 'checkbox',
			'label'   => __( 'Attach the quote as a PDF', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'default' => 'yes',
		);
	}
}
