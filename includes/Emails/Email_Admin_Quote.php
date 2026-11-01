<?php
/**
 * Store email: new quote request / accepted / declined / customer message.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Admin quote email.
 */
class Email_Admin_Quote extends Email_Quote_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id          = 'dev3bq_admin_quote';
		$this->title       = __( 'Quote activity (store)', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		$this->description = __( 'Sent to the store for new quote requests, accepted or declined quotes and customer messages.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * Subject per event.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		$map = array(
			'request'  => __( '[{site_title}] New quote request {quote_number} from {customer}', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'accepted' => __( '[{site_title}] Quote {quote_number} accepted', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'declined' => __( '[{site_title}] Quote {quote_number} declined', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'message'  => __( '[{site_title}] New message on quote {quote_number}', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
		return $map[ $this->event ] ?? $map['request'];
	}

	/**
	 * Subject follows the event unless the admin set one.
	 *
	 * @return string
	 */
	public function get_subject() {
		$custom = (string) $this->get_option( 'subject', '' );
		return $this->format_string( '' !== $custom ? $custom : $this->get_default_subject() );
	}

	/**
	 * Headings.
	 *
	 * @return array
	 */
	public static function headings() {
		return array(
			'request'  => __( 'New quote request', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'accepted' => __( 'Quote accepted', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'declined' => __( 'Quote declined', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'message'  => __( 'New customer message', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Quote activity', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Prices only once the store has priced the quote.
	 *
	 * @return bool
	 */
	protected function show_prices() {
		return 'request' !== $this->event;
	}

	/**
	 * Intro.
	 *
	 * @return string
	 */
	public function intro() {
		switch ( $this->event ) {
			case 'accepted':
				return __( 'The customer accepted this quote. A pending order with the quoted prices was created and the customer can pay it online.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
			case 'declined':
				return __( 'The customer declined this quote.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
			case 'message':
				return __( 'The customer sent a message about this quote:', 'dev3-ai-b2b-wholesale-for-woocommerce' );
			default:
				return __( 'A customer asked for a quote. Open it to price the lines (DEV3 AI can draft it from the request text) and send it.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
	}

	/**
	 * Button.
	 *
	 * @return array
	 */
	public function button() {
		return array(
			'url'  => $this->admin_url(),
			'text' => __( 'Open the quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}

	/**
	 * Recipient field.
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			array(
				'recipient' => array(
					'title'       => __( 'Recipient(s)', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
					'type'        => 'text',
					/* translators: %s: admin email */
					'description' => sprintf( __( 'Comma-separated. Defaults to %s.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), esc_html( get_option( 'admin_email' ) ) ),
					'placeholder' => '',
					'default'     => '',
				),
			),
			$this->form_fields
		);
	}
}
