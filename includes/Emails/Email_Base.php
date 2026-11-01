<?php
/**
 * Shared WooCommerce email (loaded only when WC_Email exists).
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Base email: one template for every B2B email.
 */
abstract class Email_Base extends \WC_Email {

	/**
	 * Event key.
	 *
	 * @var string
	 */
	public $event = '';

	/**
	 * Extra args (message).
	 *
	 * @var array
	 */
	public $args = array();

	/**
	 * Sets templates.
	 */
	public function __construct() {
		$this->template_base  = DEV3BQ_PATH . 'templates/';
		$this->template_html  = 'emails/dev3bq-email.php';
		$this->template_plain = 'emails/plain/dev3bq-email.php';
		parent::__construct();
	}

	/**
	 * Loads the object; false when missing.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	abstract protected function load( $id );

	/**
	 * Intro paragraph.
	 *
	 * @return string
	 */
	abstract public function intro();

	/**
	 * Button { url, text }.
	 *
	 * @return array
	 */
	abstract public function button();

	/**
	 * Body data: items, totals, details.
	 *
	 * @return array
	 */
	abstract protected function body();

	/**
	 * Attachments for this send.
	 *
	 * @var array
	 */
	protected $files = array();

	/**
	 * Sends.
	 *
	 * @param int   $id   Object id.
	 * @param array $args event, message.
	 * @return bool
	 */
	public function trigger( $id, $args = array() ) {
		$this->setup_locale();
		$this->args  = (array) $args;
		$this->event = (string) ( $this->args['event'] ?? '' );
		$this->files = array();
		$sent        = false;
		if ( $this->load( $id ) && $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), array_merge( (array) $this->get_attachments(), $this->files ) );
		}
		foreach ( $this->files as $file ) {
			if ( is_string( $file ) && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->restore_locale();
		return $sent;
	}

	/**
	 * Headings per event (override).
	 *
	 * @return array
	 */
	public static function headings() {
		return array();
	}

	/**
	 * Heading follows the event unless the admin set one.
	 *
	 * @return string
	 */
	public function get_heading() {
		$custom = (string) $this->get_option( 'heading', '' );
		if ( '' !== $custom ) {
			return $this->format_string( $custom );
		}
		$map = static::headings();
		return $this->format_string( $map[ $this->event ] ?? $this->get_default_heading() );
	}

	/**
	 * Template args.
	 *
	 * @param bool $plain Plain text.
	 * @return array
	 */
	protected function template_args( $plain ) {
		$button = $this->button();
		$body   = $this->body();
		return array(
			'email_heading'      => $this->get_heading(),
			'intro'              => $this->intro(),
			'message'            => (string) ( $this->args['message'] ?? '' ),
			'items'              => $body['items'] ?? array(),
			'totals'             => $body['totals'] ?? array(),
			'details'            => $body['details'] ?? array(),
			'button_url'         => $button['url'],
			'button_text'        => $button['text'],
			'sent_to_admin'      => ! $this->is_customer_email(),
			'plain_text'         => $plain,
			'additional_content' => $this->get_additional_content(),
			'email'              => $this,
		);
	}

	/**
	 * HTML body.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain body.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
