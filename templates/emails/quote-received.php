<?php
/**
 * 已收到詢價（顧客，HTML）
 *
 * @var string                     $email_heading
 * @var string                     $additional_content
 * @var int                        $quote_id
 * @var ULTQ_Email_Quote_Received  $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$customer = ULTQ_Quote_Post::get_customer( $quote_id );
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( '%s 您好，', $customer['name'] ) ); ?></p>
<p>我們已收到您的詢價，單號 <strong><?php echo esc_html( ULTQ_Quote_Post::get_number( $quote_id ) ); ?></strong>，以下是您詢問的品項：</p>

<?php ULTQ_Helper::email_partial( 'emails/quote-items.php', array( 'quote_id' => $quote_id, 'show_prices' => false ) ); ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
