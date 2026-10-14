<?php
/**
 * 報價待追蹤清單（管理員／業務，HTML）
 *
 * @var string               $email_heading
 * @var string               $additional_content
 * @var int[]                $quote_ids
 * @var ULTQ_Email_Followup  $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cell = 'text-align:left;padding:8px;border:1px solid #e5e5e5;vertical-align:top;';
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>以下報價已寄出一段時間，顧客還沒有接受或婉拒，建議主動聯繫：</p>

<table cellspacing="0" cellpadding="6" border="1" style="border-collapse:collapse;border:1px solid #e5e5e5;width:100%;margin-bottom:24px;">
	<thead>
		<tr>
			<th style="<?php echo esc_attr( $cell ); ?>background:#f8f8f8;">單號</th>
			<th style="<?php echo esc_attr( $cell ); ?>background:#f8f8f8;">顧客</th>
			<th style="<?php echo esc_attr( $cell ); ?>background:#f8f8f8;">總計</th>
			<th style="<?php echo esc_attr( $cell ); ?>background:#f8f8f8;">寄出／到期</th>
			<th style="<?php echo esc_attr( $cell ); ?>background:#f8f8f8;">業務</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $quote_ids as $id ) : ?>
			<?php
			$customer = ULTQ_Quote_Post::get_customer( $id );
			$owner    = ULTQ_Sales::get_owner( $id );
			?>
			<tr>
				<td style="<?php echo esc_attr( $cell ); ?>"><a href="<?php echo esc_url( ULTQ_Quote_Post::admin_edit_url( $id ) ); ?>"><?php echo esc_html( ULTQ_Quote_Post::get_number( $id ) ); ?></a></td>
				<td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $customer['company'] ?: $customer['name'] ); ?><br><small><?php echo esc_html( trim( $customer['name'] . ' ' . $customer['phone'] ) ); ?></small></td>
				<td style="<?php echo esc_attr( $cell ); ?>"><?php echo wp_kses_post( wc_price( ULTQ_Quote_Post::sent( $id )['totals']['total'] ) ); ?></td>
				<td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( ULTQ_Cron::days_since_sent( $id ) . ' 天前' ); ?><br><small><?php echo esc_html( ULTQ_Quote_Post::sent( $id )['quote']['valid_until'] ); ?></small></td>
				<td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $owner ? ( ULTQ_Sales::info( $owner )['name'] ?? '' ) : '—' ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
