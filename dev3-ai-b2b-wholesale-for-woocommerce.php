<?php
/**
 * Plugin Name:       DEV3 AI B2B Wholesale & Quote Requests for WooCommerce
 * Plugin URI:        https://dev3.tech/item/dev3-ai-b2b-wholesale-for-woocommerce
 * Description:       Wholesale roles, tiered B2B pricing, quick order and request-a-quote for WooCommerce, with DEV3 AI quote drafts from free-text requests.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-b2b-wholesale-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Dev3\AiB2bWholesale
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3BQ_VERSION', '1.0.0' );
define( 'DEV3BQ_FILE', __FILE__ );
define( 'DEV3BQ_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3BQ_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3BQ_SLUG', 'dev3-ai-b2b-wholesale-for-woocommerce' );
define( 'DEV3BQ_NAME', 'DEV3 AI B2B Wholesale & Quote Requests for WooCommerce' );
define( 'DEV3BQ_MIN_PHP', '7.4' );
define( 'DEV3BQ_MIN_WP', '6.0' );
define( 'DEV3BQ_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3BQ_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiB2bWholesale\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3BQ_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiB2bWholesale\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiB2bWholesale\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiB2bWholesale\Plugin', 'instance' ) );
