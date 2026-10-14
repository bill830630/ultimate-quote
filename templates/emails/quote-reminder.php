<?php
/**
 * 報價即將到期提醒（顧客，HTML）
 *
 * @var string                     $email_heading
 * @var string                     $additional_content
 * @var int                        $quote_id
 * @var string                     $pdf_url
 * @var string                     $view_url
 * @var ULTQ_Email_Quote_Reminder  $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$customer = ULTQ_Quote_Post::get_customer( $quote_id );
$quote    = ULTQ_Quote_Post::sent( $quote_id )['quote'];
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( '%s 您好，', $customer['name'] ) ); ?></p>
<p>提醒您，報價單 <strong><?php echo esc_html( ULTQ_Quote_Post::get_number( $quote_id ) ); ?></strong> 的有效期限到 <strong><?php echo esc_html( $quote['valid_until'] ); ?></strong>，過期後價格可能會調整。</p>

<?php ULTQ_Helper::email_partial( 'emails/quote-items.php', array( 'quote_id' => $quote_id, 'show_prices' => true, 'sent' => true ) ); ?>

<?php if ( ULTQ_Settings::get( 'allow_respond' ) ) : ?>
	<p style="margin:24px 0;">
		<a href="<?php echo esc_url( $view_url ); ?>" style="display:inline-block;padding:10px 20px;background:<?php echo esc_attr( get_option( 'woocommerce_email_base_color', '#7f54b3' ) ); ?>;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">查看並確認報價</a>
	</p>
<?php endif; ?>

<p><a href="<?php echo esc_url( $pdf_url ); ?>">下載報價單 PDF</a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
