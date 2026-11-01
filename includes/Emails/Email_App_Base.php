<?php
/**
 * Base for wholesale application emails.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

use Dev3\AiB2bWholesale\Wholesale\Applications;

defined( 'ABSPATH' ) || exit;

/**
 * Application email.
 */
abstract class Email_App_Base extends Email_Base {

	/**
	 * Application.
	 *
	 * @var array|null
	 */
	public $application = null;

	/**
	 * Placeholders.
	 */
	public function __construct() {
		$this->placeholders = array(
			'{company}' => '',
		);
		parent::__construct();
	}

	/**
	 * Loads the application.
	 *
	 * @param int $id Application.
	 * @return bool
	 */
	protected function load( $id ) {
		$this->application = Applications::get( $id );
		if ( ! $this->application ) {
			return false;
		}
		$this->object                    = $this->application;
		$this->placeholders['{company}'] = $this->application['company'];
		if ( $this->is_customer_email() ) {
			$this->recipient = $this->application['email'];
		}
		return true;
	}

	/**
	 * Details table.
	 *
	 * @return array
	 */
	protected function body() {
		$a       = $this->application;
		$details = array(
			__( 'Company', 'dev3-ai-b2b-wholesale-for-woocommerce' ) => $a['company'],
			__( 'Name', 'dev3-ai-b2b-wholesale-for-woocommerce' )    => trim( $a['first_name'] . ' ' . $a['last_name'] ),
			__( 'Email', 'dev3-ai-b2b-wholesale-for-woocommerce' )   => $a['email'],
			__( 'Account type', 'dev3-ai-b2b-wholesale-for-woocommerce' ) => $a['role_name'],
		);
		if ( ! $this->is_customer_email() ) {
			$details[ __( 'Phone', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ]   = $a['phone'];
			$details[ __( 'Tax ID', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ]  = $a['tax_id'];
			$details[ __( 'Website', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ] = $a['website'];
			$details[ __( 'Country', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ] = $a['country'];
			$details[ __( 'Message', 'dev3-ai-b2b-wholesale-for-woocommerce' ) ] = $a['message'];
		}
		return array( 'details' => array_filter( $details, static fn( $v ) => '' !== (string) $v ) );
	}
}
