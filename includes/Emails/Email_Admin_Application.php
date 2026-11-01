<?php
/**
 * Store email: new wholesale application.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Admin application email.
 */
class Email_Admin_Application extends Email_App_Base {

	/**
	 * Setup.
	 */
	public function __construct() {
		$this->id          = 'dev3bq_admin_application';
		$this->title       = __( 'New wholesale application', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		$this->description = __( 'Sent to the store when someone applies for a wholesale account.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( '[{site_title}] New wholesale application from {company}', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'New wholesale application', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Intro.
	 *
	 * @return string
	 */
	public function intro() {
		return __( 'A business applied for a wholesale account. Review it and approve or decline it in the B2B desk.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Button.
	 *
	 * @return array
	 */
	public function button() {
		return array(
			'url'  => admin_url( 'admin.php?page=' . DEV3BQ_SLUG . '#/customers' ),
			'text' => __( 'Review application', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
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
