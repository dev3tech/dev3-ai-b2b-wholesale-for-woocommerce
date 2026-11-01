<?php
/**
 * My Account → Quotes endpoint (list + detail) and a wholesale prompt on the account dashboard.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Frontend;

use Dev3\AiB2bWholesale\Quotes\Quotes;
use Dev3\AiB2bWholesale\Settings;
use Dev3\AiB2bWholesale\Wholesale\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Account.
 */
class Account {

	const ENDPOINT = 'quotes';

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'page' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ) );
		add_action( 'woocommerce_account_dashboard', array( $this, 'dashboard_note' ) );
		add_action( 'init', array( $this, 'endpoint' ) );
	}

	/**
	 * Rewrite endpoint (WooCommerce adds its query vars, this keeps it registered early too).
	 */
	public function endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menu item before "Orders"→ after it.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public function menu( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'orders' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' );
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
		return $out;
	}

	/**
	 * Endpoint title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Quotes', 'dev3-ai-b2b-wholesale-for-woocommerce' );
	}

	/**
	 * Endpoint content.
	 *
	 * @param string $value Quote number (detail) or ''.
	 */
	public function page( $value = '' ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' !== $value ) {
			$quote = Quotes::get_by_number( $value );
			if ( ! $quote || get_current_user_id() !== (int) $quote['user_id'] ) {
				echo '<p>' . esc_html__( 'Quote not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</p>';
				return;
			}
			echo Storefront::mount( 'quote-view', array( 'number' => $quote['number'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in mount.
			return;
		}
		$user = wp_get_current_user();
		$res  = Quotes::query(
			array(
				'user_id'  => $user->ID,
				'email'    => $user->user_email,
				'per_page' => 50,
			)
		);
		Storefront::enqueue();
		$labels = Quotes::status_labels();
		echo '<div class="dev3bq-shop dev3bq-shop--account">';
		if ( ! $res['rows'] ) {
			echo '<div class="dev3bq-shop__card dev3bq-shop__empty"><p class="dev3bq-shop__title">' . esc_html__( 'No quotes yet', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</p><p>' . esc_html__( 'Add products to a quote from any product page, or describe what you need and we will price it for you.', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</p><p><a class="dev3bq-shop__btn" href="' . esc_url( Settings::page_url( 'quote_page_id' ) ) . '">' . esc_html__( 'Request a quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</a></p></div></div>';
			return;
		}
		echo '<table class="dev3bq-shop__table dev3bq-shop__table--stack"><thead><tr><th scope="col">' . esc_html__( 'Quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'Date', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'Status', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</th><th scope="col"><span class="dev3bq-sr">' . esc_html__( 'Actions', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</span></th></tr></thead><tbody>';
		foreach ( $res['rows'] as $q ) {
			$priced = in_array( $q['status'], array( 'sent', 'accepted', 'declined', 'expired' ), true );
			$url    = wc_get_endpoint_url( self::ENDPOINT, $q['number'], wc_get_page_permalink( 'myaccount' ) );
			echo '<tr><td data-label="' . esc_attr__( 'Quote', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '"><a href="' . esc_url( $url ) . '"><strong>' . esc_html( $q['number'] ) . '</strong></a></td>';
			echo '<td data-label="' . esc_attr__( 'Date', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $q['created_at'] ) ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Status', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '"><span class="dev3bq-shop__badge is-' . esc_attr( $q['status'] ) . '">' . esc_html( $labels[ $q['status'] ] ?? $q['status'] ) . '</span></td>';
			echo '<td data-label="' . esc_attr__( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '">' . ( $priced ? wp_kses_post( wc_price( $q['total'], array( 'currency' => $q['currency'] ) ) ) : '&mdash;' ) . '</td>';
			echo '<td><a class="dev3bq-shop__btn dev3bq-shop__btn--ghost" href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Account dashboard: invite retail customers to apply.
	 */
	public function dashboard_note() {
		$role = Roles::user_role();
		if ( '' !== $role ) {
			$def = Roles::get( $role );
			/* translators: %s: role name */
			echo '<p class="dev3bq-account-note">' . esc_html( sprintf( __( 'Your account has %s pricing.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), $def ? $def['name'] : '' ) ) . ' <a href="' . esc_url( Settings::page_url( 'quick_order_page_id' ) ) . '">' . esc_html__( 'Open the quick order form', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</a></p>';
			return;
		}
		if ( Settings::get( 'registration_open', true ) && (int) Settings::get( 'registration_page_id', 0 ) ) {
			echo '<p class="dev3bq-account-note">' . esc_html__( 'Buying for a business?', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . ' <a href="' . esc_url( Settings::page_url( 'registration_page_id' ) ) . '">' . esc_html__( 'Apply for a wholesale account', 'dev3-ai-b2b-wholesale-for-woocommerce' ) . '</a></p>';
		}
	}
}
