<?php
/**
 * Registers the WooCommerce email classes and sends them.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

use Dev3\AiB2bWholesale\Quotes\Quotes;

defined( 'ABSPATH' ) || exit;

/**
 * Emails.
 */
class Emails {

	/**
	 * Send type → [ class id, default event ].
	 */
	const TYPES = array(
		'admin_application'       => array( 'Dev3bq_Email_Admin_Application', '' ),
		'customer_application'    => array( 'Dev3bq_Email_Customer_Application', 'received' ),
		'admin_quote_request'     => array( 'Dev3bq_Email_Admin_Quote', 'request' ),
		'admin_quote_decision'    => array( 'Dev3bq_Email_Admin_Quote', 'accepted' ),
		'customer_quote_received' => array( 'Dev3bq_Email_Customer_Quote_Received', '' ),
		'customer_quote_ready'    => array( 'Dev3bq_Email_Customer_Quote_Ready', '' ),
		'customer_quote_reminder' => array( 'Dev3bq_Email_Customer_Quote_Reminder', 'reminder' ),
		'customer_quote_message'  => array( 'Dev3bq_Email_Customer_Quote_Message', '' ),
	);

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'classes' ) );
	}

	/**
	 * Adds our classes to WooCommerce → Settings → Emails.
	 *
	 * @param array $emails Emails.
	 * @return array
	 */
	public static function classes( $emails ) {
		if ( ! class_exists( '\WC_Email' ) ) {
			return $emails;
		}
		$emails['Dev3bq_Email_Admin_Application']       = new Email_Admin_Application();
		$emails['Dev3bq_Email_Customer_Application']    = new Email_Customer_Application();
		$emails['Dev3bq_Email_Admin_Quote']             = new Email_Admin_Quote();
		$emails['Dev3bq_Email_Customer_Quote_Received'] = new Email_Customer_Quote_Received();
		$emails['Dev3bq_Email_Customer_Quote_Ready']    = new Email_Customer_Quote_Ready();
		$emails['Dev3bq_Email_Customer_Quote_Reminder'] = new Email_Customer_Quote_Reminder();
		$emails['Dev3bq_Email_Customer_Quote_Message']  = new Email_Customer_Quote_Message();
		return $emails;
	}

	/**
	 * Sends one of our emails.
	 *
	 * @param string $type Type (TYPES key).
	 * @param int    $id   Quote or application id.
	 * @param array  $args event, message.
	 * @return bool Sent.
	 */
	public static function send( $type, $id, array $args = array() ) {
		if ( ! function_exists( 'WC' ) || ! isset( self::TYPES[ $type ] ) ) {
			return false;
		}
		$mailer = WC()->mailer();
		$emails = $mailer ? $mailer->get_emails() : array();
		$email  = $emails[ self::TYPES[ $type ][0] ] ?? null;
		if ( ! $email ) {
			return false;
		}
		if ( empty( $args['event'] ) ) {
			$args['event'] = self::TYPES[ $type ][1];
		}
		$sent = (bool) $email->trigger( (int) $id, $args );
		if ( $sent && $email instanceof Email_Quote_Base ) {
			/* translators: %s: email title */
			Quotes::add_event( $id, 'email', 'system', sprintf( __( 'Email sent: %s', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $email->get_title() ) );
		}
		return $sent;
	}
}
