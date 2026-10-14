<?php
/**
 * 報價即將到期提醒（純文字）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
$customer = ULTQ_Quote_Post::get_customer( $quote_id );
$quote    = ULTQ_Quote_Post::sent( $quote_id )['quote'];
printf( "%s 您好，\n\n", $customer['name'] );
printf( "提醒您，報價單 %s 的有效期限到 %s，過期後價格可能會調整。\n\n", ULTQ_Quote_Post::get_number( $quote_id ), $quote['valid_until'] );
echo ULTQ_Helper::plain_items( $quote_id, true, true ) . "\n";
if ( ULTQ_Settings::get( 'allow_respond' ) ) {
	echo '查看並確認報價：' . $view_url . "\n";
}
echo '下載報價單 PDF：' . $pdf_url . "\n";

if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
echo "\n" . wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) . "\n";
