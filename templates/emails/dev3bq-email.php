<?php
/**
 * B2B email (HTML). Override in yourtheme/woocommerce/emails/dev3bq-email.php.
 *
 * @package Dev3\AiB2bWholesale
 *
 * @var string    $email_heading
 * @var string    $intro
 * @var string    $message
 * @var array     $items   [{name, qty, unit, total}] (unit/total are HTML from wc_price or '').
 * @var array     $totals  [[label, html]].
 * @var array     $details [label => text].
 * @var string    $button_url
 * @var string    $button_text
 * @var bool      $sent_to_admin
 * @var string    $additional_content
 * @var \WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
?>
<p><?php echo esc_html( $intro ); ?></p>
<?php if ( '' !== trim( (string) $message ) ) : ?>
	<blockquote style="margin:0 0 16px;padding:12px 16px;border-left:4px solid #4f46e5;background:#f5f5ff;white-space:pre-line;"><?php echo esc_html( $message ); ?></blockquote>
<?php endif; ?>
<?php if ( $items ) : ?>
	<?php $dev3bq_has_prices = '' !== (string) $items[0]['unit']; ?>
	<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 16px;border-color:#e5e5e5;">
		<thead>
			<tr>
				<th scope="col" style="text-align:left;"><?php esc_html_e( 'Product', 'dev3-ai-b2b-wholesale-for-woocommerce' ); ?></th>
				<th scope="col" style="text-align:right;"><?php esc_html_e( 'Qty', 'dev3-ai-b2b-wholesale-for-woocommerce' ); ?></th>
				<?php if ( $dev3bq_has_prices ) : ?>
					<th scope="col" style="text-align:right;"><?php esc_html_e( 'Unit price', 'dev3-ai-b2b-wholesale-for-woocommerce' ); ?></th>
					<th scope="col" style="text-align:right;"><?php esc_html_e( 'Total', 'dev3-ai-b2b-wholesale-for-woocommerce' ); ?></th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $items as $dev3bq_item ) : ?>
				<tr>
					<td style="text-align:left;"><?php echo esc_html( $dev3bq_item['name'] ); ?></td>
					<td style="text-align:right;"><?php echo esc_html( (string) $dev3bq_item['qty'] ); ?></td>
					<?php if ( $dev3bq_has_prices ) : ?>
						<td style="text-align:right;"><?php echo wp_kses_post( $dev3bq_item['unit'] ); ?></td>
						<td style="text-align:right;"><?php echo wp_kses_post( $dev3bq_item['total'] ); ?></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<?php if ( $totals ) : ?>
			<tfoot>
				<?php foreach ( $totals as $dev3bq_row ) : ?>
					<tr>
						<th scope="row" colspan="<?php echo $dev3bq_has_prices ? 3 : 1; ?>" style="text-align:right;"><?php echo esc_html( $dev3bq_row[0] ); ?></th>
						<td style="text-align:right;"><?php echo wp_kses_post( $dev3bq_row[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tfoot>
		<?php endif; ?>
	</table>
<?php endif; ?>
<?php if ( $details ) : ?>
	<table cellspacing="0" cellpadding="6" style="width:100%;margin:0 0 16px;">
		<?php foreach ( $details as $dev3bq_label => $dev3bq_value ) : ?>
			<tr>
				<th scope="row" style="text-align:left;vertical-align:top;width:35%;"><?php echo esc_html( $dev3bq_label ); ?></th>
				<td style="text-align:left;white-space:pre-line;"><?php echo esc_html( $dev3bq_value ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php endif; ?>
<?php if ( $button_url ) : ?>
	<p style="margin:24px 0;"><a href="<?php echo esc_url( $button_url ); ?>" style="display:inline-block;padding:12px 20px;background:#4f46e5;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;"><?php echo esc_html( $button_text ); ?></a></p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce email hook.
