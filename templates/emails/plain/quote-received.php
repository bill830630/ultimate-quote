<?php
/**
 * 已收到詢價（純文字）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
$customer = ULTQ_Quote_Post::get_customer( $quote_id );
printf( "%s 您好，\n\n", $customer['name'] );
printf( "我們已收到您的詢價，單號 %s，以下是您詢問的品項：\n\n", ULTQ_Quote_Post::get_number( $quote_id ) );
echo ULTQ_Helper::plain_items( $quote_id, false );

if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
echo "\n" . wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) . "\n";
