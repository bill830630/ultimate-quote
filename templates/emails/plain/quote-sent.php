<?php
/**
 * 報價單（純文字）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
$customer = ULTQ_Quote_Post::get_customer( $quote_id );
$quote    = ULTQ_Quote_Post::get_quote( $quote_id );
printf( "%s 您好，\n\n", $customer['name'] );
$rev = ULTQ_Quote_Post::revision( $quote_id ) + 1;
if ( $rev > 1 ) {
	printf( "詢價單 %s 的報價已更新為第 %d 版，內容如下，請以這一版為準。正式報價單 PDF 已附在這封信中。\n\n", ULTQ_Quote_Post::get_number( $quote_id ), $rev );
} else {
	printf( "感謝您的詢價，詢價單 %s 的報價如下，正式報價單 PDF 已附在這封信中。\n\n", ULTQ_Quote_Post::get_number( $quote_id ) );
}
echo ULTQ_Helper::plain_items( $quote_id, true ) . "\n";
if ( $quote['valid_until'] ) {
	echo '報價有效期限：' . $quote['valid_until'] . "\n";
}
if ( $quote['payment_terms'] ) {
	echo '付款條件：' . $quote['payment_terms'] . "\n";
}
if ( $quote['note'] ) {
	echo "\n" . $quote['note'] . "\n";
}
echo "\n下載報價單 PDF：" . $pdf_url . "\n";
if ( ! empty( $xlsx_url ) ) {
	echo '下載 Excel 版：' . $xlsx_url . "\n";
}
if ( ULTQ_Settings::get( 'allow_respond' ) ) {
	echo '查看並確認報價（可直接線上接受）：' . $view_url . "\n";
}

if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
echo "\n" . wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) . "\n";
