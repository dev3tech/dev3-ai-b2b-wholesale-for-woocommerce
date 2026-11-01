<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Admin;

use Dev3\AiB2bWholesale\Core3\Core\Assets;
use Dev3\AiB2bWholesale\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3BQ_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$pending    = class_exists( 'WooCommerce' ) ? \Dev3\AiB2bWholesale\Wholesale\Applications::pending_count() + (int) \Dev3\AiB2bWholesale\Quotes\Quotes::stats()['new'] : 0;
		$badge      = $pending ? ' <span class="awaiting-mod count-' . (int) $pending . '"><span class="pending-count">' . (int) $pending . '</span></span>' : '';
		$this->hook = (string) add_menu_page( DEV3BQ_NAME, __( 'DEV3 B2B & Quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . $badge, Capabilities::manage(), DEV3BQ_SLUG, array( $this, 'render' ), 'dashicons-store', 56 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3bq-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3BQ_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3bq-admin-root" class="dev3bq-root dev3bq-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3BQ_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</a>' );
		return $links;
	}
}
