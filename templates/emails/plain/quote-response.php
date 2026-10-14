<?php
/**
 * 顧客回覆報價通知（純文字）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
$accepted = 'accept' === $response['action'];
$customer = ULTQ_Quote_Post::get_customer( $quote_id );
printf(
	$accepted ? "顧客已於 %1\$s 在網站上接受報價單 %2\$s（總計 %3\$s），簽署人：%4\$s。\n\n" : "顧客已於 %1\$s 婉拒報價單 %2\$s（總計 %3\$s）。\n\n",
	wp_date( 'Y-m-d H:i', (int) $response['time'] ),
	ULTQ_Quote_Post::get_number( $quote_id ),
	ULTQ_Helper::money( ULTQ_Quote_Post::sent( $quote_id )['totals']['total'] ),
	$response['name']
);
if ( $response['note'] ) {
	echo ( $accepted ? '顧客備註：' : '婉拒原因：' ) . $response['note'] . "\n\n";
}
echo "【顧客資料】\n";
foreach ( array( '聯絡人' => 'name', '公司名稱' => 'company', 'Email' => 'email', '電話' => 'phone' ) as $label => $key ) {
	if ( '' !== $customer[ $key ] ) {
		echo $label . '：' . $customer[ $key ] . "\n";
	}
}
echo "\n前往後台查看詢價單：" . ULTQ_Quote_Post::admin_edit_url( $quote_id ) . "\n";

if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
echo "\n" . wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) . "\n";
