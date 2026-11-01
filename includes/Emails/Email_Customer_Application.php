<?php
/**
 * Applicant email: received / approved / declined.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Customer application email.
 */
class Email_Customer_Application extends Email_App_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id             = 'dev3bq_customer_application';
		$this->customer_email = true;
		$this->title          = __( 'Wholesale application update (applicant)', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		$this->description    = __( 'Sent to the applicant when the application is received, approved or declined.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		parent::__construct();
	}

	/**
	 * Subjects per event.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		$map = array(
			'received' => __( 'We received your wholesale application', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'approved' => __( 'Your wholesale account at {site_title} is active', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'rejected' => __( 'Your wholesale application at {site_title}', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
		return $map[ $this->event ] ?? $map['received'];
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
			'received' => __( 'Thanks for applying', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'approved' => __( 'Welcome to wholesale', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			'rejected' => __( 'About your application', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your wholesale application', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Intro.
	 *
	 * @return string
	 */
	public function intro() {
		switch ( $this->event ) {
			case 'approved':
				return __( 'Good news: your wholesale account is approved. Log in to see your prices, use the quick order form and request quotes.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
			case 'rejected':
				return __( 'Thank you for your interest. We are unable to approve a wholesale account for you at this time.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
			default:
				return __( 'We received your wholesale application and will review it shortly. You will get another email when it is approved.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
	}

	/**
	 * Button.
	 *
	 * @return array
	 */
	public function button() {
		if ( 'approved' !== $this->event ) {
			return array(
				'url'  => '',
				'text' => '',
			);
		}
		return array(
			'url'  => wc_get_page_permalink( 'myaccount' ),
			'text' => __( 'Go to your account', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}
}
