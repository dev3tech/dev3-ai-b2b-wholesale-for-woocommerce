<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale;

use Dev3\AiB2bWholesale\Core3\Core\Assets;
use Dev3\AiB2bWholesale\Core3\Core\Privacy;
use Dev3\AiB2bWholesale\Core3\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3bq_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3BQ_DB_VERSION );
		Wholesale\Roles::sync_wp_roles();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		update_option( 'dev3bq_flush_rewrite', 1 );
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( has_filter( 'dev3bq_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			return;
		}
		add_filter( 'dev3bq_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3bq_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3bq_schema_sql', array( Data\Tables::class, 'schema' ), 10, 2 );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_filter( 'dev3bq_manage_capability', array( __CLASS__, 'manage_capability' ), 5 );
		add_action( 'init', array( $this, 'textdomain' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compat' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( self::CRON_HOOK, array( Quotes\Service::class, 'daily' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( Ai\Prompts_Library::class, 'register' ), 5 );
		add_action( 'init', array( $this, 'assets' ) );
		Privacy::init();
		add_action( 'init', array( Data\Personal_Data::class, 'register' ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'needs_woo' ) );
			return;
		}
		( new Wholesale\Roles() )->register();
		( new Pricing\Product_Meta() )->register();
		( new Woo\Storefront_Pricing() )->register();
		( new Woo\Checkout_Rules() )->register();
		( new Woo\Order_Admin() )->register();
		( new Emails\Emails() )->register();
		( new Frontend\Storefront() )->register();
		( new Frontend\Account() )->register();
	}

	/**
	 * Shop managers run the B2B desk (filter dev3bq_manage_capability to change).
	 *
	 * @param string $cap Capability.
	 * @return string
	 */
	public static function manage_capability( $cap ) {
		return 'manage_options' === $cap ? 'manage_woocommerce' : $cap;
	}

	/**
	 * Installs tables / WP roles after updates.
	 */
	public static function maybe_upgrade() {
		Schema::maybe_install( DEV3BQ_DB_VERSION );
		if ( get_option( 'dev3bq_flush_rewrite' ) ) {
			delete_option( 'dev3bq_flush_rewrite' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Translations (on init, as WP 6.7+ expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-b2b-wholesale-for-woocommerce', false, dirname( plugin_basename( DEV3BQ_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the admin bundle once (Assets::register adds the boot script on every call).
	 */
	public function assets() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		Assets::register(
			array( 'admin' ),
			array(
				'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'currencySymbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES ) : '$',
				'decimals'       => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
				'wooActive'      => class_exists( 'WooCommerce' ),
				'ordersUrl'      => admin_url( 'admin.php?page=wc-orders' ),
				'productsUrl'    => admin_url( 'edit.php?post_type=product' ),
				'usersUrl'       => admin_url( 'users.php' ),
				'emailsUrl'      => admin_url( 'admin.php?page=wc-settings&tab=email' ),
				'homeUrl'        => home_url( '/' ),
			)
		);
	}

	/**
	 * HPOS + Cart/Checkout blocks compatibility.
	 */
	public function declare_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3BQ_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3BQ_FILE, true );
		}
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function needs_woo() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'DEV3 AI B2B Wholesale & Quote Requests needs WooCommerce to be active.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</p></div>';
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		if ( class_exists( 'WooCommerce' ) ) {
			( new Rest\Admin_Controller() )->register_routes();
			( new Rest\Public_Controller() )->register_routes();
		}
	}
}
