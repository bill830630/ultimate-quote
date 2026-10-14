<?php
/**
 * 顧客回覆報價通知（管理員，HTML）
 *
 * @var string                     $email_heading
 * @var string                     $additional_content
 * @var int                        $quote_id
 * @var array                      $response  action/name/note/time
 * @var ULTQ_Email_Quote_Response  $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$accepted = 'accept' === $response['action'];
$totals   = ULTQ_Quote_Post::sent( $quote_id )['totals'];
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>
	<?php
	echo esc_html( sprintf(
		$accepted ? '顧客已於 %1$s 在網站上接受報價單 %2$s（總計 %3$s），簽署人：%4$s。' : '顧客已於 %1$s 婉拒報價單 %2$s（總計 %3$s）。',
		wp_date( 'Y-m-d H:i', (int) $response['time'] ),
		ULTQ_Quote_Post::get_number( $quote_id ),
		ULTQ_Helper::money( $totals['total'] ),
		$response['name']
	) );
	?>
</p>

<?php if ( $response['note'] ) : ?>
	<p><strong><?php echo $accepted ? '顧客備註' : '婉拒原因'; ?>：</strong><br><?php echo nl2br( esc_html( $response['note'] ) ); ?></p>
<?php endif; ?>

<h2>顧客資料</h2>
<?php ULTQ_Helper::email_partial( 'emails/customer-details.php', array( 'quote_id' => $quote_id ) ); ?>

<p><a href="<?php echo esc_url( ULTQ_Quote_Post::admin_edit_url( $quote_id ) ); ?>">前往後台查看詢價單</a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
