<?php
/**
 * Customer email: reminder / expired.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Reminder.
 */
class Email_Customer_Quote_Reminder extends Email_Quote_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id             = 'dev3bq_customer_quote_reminder';
		$this->customer_email = true;
		$this->title          = __( 'Quote reminder / expired (customer)', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		$this->description    = __( 'Follow-up a few days after a quote was sent (see the B2B settings), and a note when a quote expires.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		parent::__construct();
	}

	/**
	 * Subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return 'expired' === $this->event ? __( 'Your quote {quote_number} has expired', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'A reminder about your quote {quote_number}', 'dev3-ai-b2b-wholesale-for-woocommerce' );
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
			'reminder' => __( 'Your quote is waiting', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'expired'  => __( 'Your quote has expired', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your quote is waiting', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Intro.
	 *
	 * @return string
	 */
	public function intro() {
		if ( 'expired' === $this->event ) {
			return __( 'The quote below has passed its validity date. Reply to this email or request a new quote and we will gladly update it for you.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
		return __( 'Just checking in: your quote is still available. Accept it online whenever you are ready, or send us a message if anything needs changing.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Button.
	 *
	 * @return array
	 */
	public function button() {
		return array(
			'url'  => $this->customer_url(),
			'text' => 'expired' === $this->event ? __( 'View the quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) : __( 'Review and accept', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}
}
