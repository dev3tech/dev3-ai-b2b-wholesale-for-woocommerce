<?php
/**
 * B2B email (plain text). Override in yourtheme/woocommerce/emails/plain/dev3bq-email.php.
 *
 * @package Dev3\AiB2bWholesale
 *
 * @var string $email_heading
 * @var string $intro
 * @var string $message
 * @var array  $items
 * @var array  $totals
 * @var array  $details
 * @var string $button_url
 * @var string $button_text
 * @var string $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
echo esc_html( wp_strip_all_tags( $intro ) ) . "\n\n";
if ( '' !== trim( (string) $message ) ) {
	echo esc_html( wp_strip_all_tags( $message ) ) . "\n\n";
}
foreach ( $items as $dev3bq_item ) {
	echo esc_html( $dev3bq_item['qty'] . ' x ' . $dev3bq_item['name'] );
	if ( '' !== (string) $dev3bq_item['total'] ) {
		echo ' - ' . esc_html( html_entity_decode( wp_strip_all_tags( $dev3bq_item['total'] ), ENT_QUOTES ) );
	}
	echo "\n";
}
if ( $items ) {
	echo "\n";
}
foreach ( $totals as $dev3bq_row ) {
	echo esc_html( $dev3bq_row[0] . ': ' . html_entity_decode( wp_strip_all_tags( $dev3bq_row[1] ), ENT_QUOTES ) ) . "\n";
}
foreach ( $details as $dev3bq_label => $dev3bq_value ) {
	echo esc_html( $dev3bq_label . ': ' . $dev3bq_value ) . "\n";
}
if ( $button_url ) {
	echo "\n" . esc_html( $button_text ) . ': ' . esc_url_raw( $button_url ) . "\n";
}
if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}
echo "\n" . esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) . "\n"; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce filter.
